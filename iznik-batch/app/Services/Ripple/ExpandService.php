<?php

namespace App\Services\Ripple;

use App\Database\Expressions\Alias;
use App\Database\Expressions\AnyValue;
use App\Database\Expressions\Arithmetic;
use App\Database\Expressions\CaseWhen;
use App\Database\Expressions\CastAs;
use App\Database\Expressions\Coalesce;
use App\Database\Expressions\Comparison;
use App\Database\Expressions\CountDistinct;
use App\Database\Expressions\In;
use App\Database\Expressions\JsonContains;
use App\Database\Expressions\JsonValid;
use App\Database\Expressions\Min;
use App\Database\Expressions\NullIf;
use App\Database\Expressions\Point;
use App\Database\Expressions\StArea;
use App\Database\Expressions\StAsText;
use App\Database\Expressions\StBuffer;
use App\Database\Expressions\StContains;
use App\Database\Expressions\StDifference;
use App\Database\Expressions\StEnvelope;
use App\Database\Expressions\StGeometryType;
use App\Database\Expressions\StGeomFromText;
use App\Database\Expressions\StIntersection;
use App\Database\Expressions\StIntersects;
use App\Database\Expressions\StSimplify;
use App\Database\Expressions\StSrid;
use App\Database\Expressions\StUnion;
use App\Database\Expressions\StWithin;
use App\Database\Expressions\StX;
use App\Database\Expressions\StY;
use App\Database\Expressions\Value;
use App\Support\GreatCircle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The rippling-out reach engine.
 *
 * Maintains one rippling_reach row per active post (the subset of
 * messages_spatial — the browsable, approved, not-taken set), advancing each
 * post's reach polygon over wall-clock time per the hazard schedule. Runs in the
 * existing batch container and computes reach via the routing server (see
 * ReachService) — no new container.
 *
 * PR A scope: compute + persist reach only ("dark" — nothing reads it yet).
 * Immediate mails (PR B), cross-group insertion (PR D engine hook), held-reply
 * release (PR C) all bolt onto this same per-tick loop later.
 */
class ExpandService
{
    private const SRID = 3857;

    /** Metres to blur a poster's origin before it drives the reach (matches Utils::BLUR_USER). */
    private const BLUR_USER = 400;

    /** Maintains the sandwich-bounds columns alongside every polygon write. */
    private ReachBoundsService $bounds;

    /** Chooses each post's reach budget from how thinly freeglers are spread around it. */
    private DensityService $density;

    /** Which communities have switched rippling off, per direction (groups.settings.rippling). */
    private GroupRippleOptOut $optOut;

    /** Releases held replies when a post's reach is dropped by an opt-out. */
    private RippleReplyService $replies;

    /** Memoized rippling_reach density-column check, so a pre-migration deploy is a no-op. */
    private static ?bool $densityColumns = null;

    public function __construct(
        private ReachService $reach,
        ?ReachBoundsService $bounds = null,
        ?DensityService $density = null,
        ?GroupRippleOptOut $optOut = null,
        ?RippleReplyService $replies = null
    ) {
        $this->bounds = $bounds ?? new ReachBoundsService();
        $this->density = $density ?? new DensityService();
        $this->optOut = $optOut ?? new GroupRippleOptOut();
        $this->replies = $replies ?? new RippleReplyService(new ReachQueryService());
    }

    /** Has the density-sizing migration run? Without it the cap still applies, unrecorded. */
    private function densityColumnsReady(): bool
    {
        if (self::$densityColumns === null) {
            try {
                self::$densityColumns = Schema::hasColumn('rippling_reach', 'density_band');
            } catch (\Throwable) {
                self::$densityColumns = false;
            }
        }

        return self::$densityColumns;
    }

    /** Test-only: forget the memoized density-column check. */
    public static function forgetDensityColumns(): void
    {
        self::$densityColumns = null;
    }

    /**
     * SET-clause values deriving the sandwich bounds from the SAME polygon WKT being
     * written, so polygon and bounds land in ONE statement — no timing window in which
     * a new polygon has stale bounds. Empty pre-migration. Reused (added to the
     * polygon => ... entry) by every caller that writes rippling_reach.polygon, so
     * bounds are always set atomically alongside it. Matches
     * ReachBoundsService::outerExpr()/innerExpr()'s ST_Buffer(ST_Simplify(...))
     * shape (those stay string-returning for MigrateReachBoundsSchemaCommand's own
     * raw-SQL callers); expressed structurally here so it composes as
     * ->update()/->upsert() values.
     *
     * @return array<string,mixed> empty if bounds columns don't exist yet
     */
    private function boundsSetSql(string $storeWkt): array
    {
        if (!$this->bounds->ready()) {
            return [];
        }
        $poly = new StGeomFromText(Value::of($storeWkt), self::SRID);

        return [
            'outer_bound' => new StBuffer(new StSimplify($poly, ReachBoundsService::TOLERANCE), ReachBoundsService::TOLERANCE),
            'inner_bound' => new StBuffer(new StSimplify($poly, ReachBoundsService::TOLERANCE), -ReachBoundsService::TOLERANCE),
        ];
    }

    /**
     * As boundsSetSql, but the envelope fallback for polygons whose derivation THROWS
     * (~94% of production polygons are technically invalid): the MBR still finds the
     * row, the exact polygon decides. Never a degenerate POINT for an open post — that
     * would prune it from the browse R-tree.
     *
     * @return array<string,mixed> empty if bounds columns don't exist yet
     */
    private function boundsEnvelopeSql(string $storeWkt): array
    {
        if (!$this->bounds->ready()) {
            return [];
        }

        return [
            'outer_bound' => new StEnvelope(new StGeomFromText(Value::of($storeWkt), self::SRID)),
            'inner_bound' => null,
        ];
    }

    /**
     * @return array{initialized:int,expanded:int,completed:int,removed:int,skipped:int,errors:int}
     */
    /**
     * @param int|null    $onlyMsgid     Restrict the whole run to one message ID (controlled testing).
     * @param string|null $withinPolyWkt Restrict the whole run to posts whose origin point falls within
     *                                   this WKT polygon (SRID self::SRID) — the area test (e.g. ripple
     *                                   the recent posts near Edinburgh). The go-live arrival cutoff
     *                                   still applies (an area scope filters where, not when).
     */
    public function process(bool $dryRun = false, int $limit = 500, ?int $onlyMsgid = null, ?string $withinPolyWkt = null): array
    {
        $stats = [
            'initialized' => 0, 'expanded' => 0, 'completed' => 0,
            'removed' => 0, 'skipped' => 0, 'errors' => 0, 'rippled_in' => 0, 'mailed' => 0,
            'memberships_added' => 0, 'pulled_on_leave' => 0,
            'pulled_on_removal' => 0, 'memberships_removed' => 0,
        ];

        // A scoped run ($onlyMsgid or $withinPolyWkt) targets a chosen subset of posts (controlled/area
        // testing): init, advance AND retraction are all restricted to the same subset, so the group
        // experiment retracts a rejected/removed post (drops reach + pulls its rippled copies) instead
        // of leaving live copies behind and continuing to ripple it into yet more groups.
        $scoped = $onlyMsgid !== null || $withinPolyWkt !== null;

        // Master activation switch. While rippling is globally disabled an UNSCOPED run does nothing
        // (no reach computed, nothing rippled). A SCOPED run is still allowed through while global is
        // off - this is how the group experiment runs: RIPPLE_WITHIN_GROUPS set + RIPPLE_ENABLED false
        // ripples ONLY the scoped (experiment) groups, everyone else stays dark. The unscoped cron is
        // also unscheduled when off (routes/console.php); this gate is defence-in-depth.
        if (!config('freegle.ripple.enabled') && !$scoped) {
            return $stats;
        }

        // 1. Stop-and-retract for posts that have left the browsable set — rejected/removed on
        //    their origin group, withdrawn, expired or deleted (Taken/Received stay in
        //    messages_spatial and are excluded): drop reach AND pull every rippled-in copy,
        //    removing now-purposeless ripple-joined memberships.
        // Retraction is deliberately NOT area-scoped (only --msgid restricts it). A rippled_in
        // copy is a committed artifact whose cleanup must complete even after the post's origin
        // group leaves the trial - at which point its origin drops out of $withinPolyWkt. Area-
        // scoping the retraction stranded copies in receiving groups when a group was removed
        // from the experiment (poster had already left, but the post stayed live there). See
        // ExpandServiceTest::test_*_retraction_*_not_gated_by_current_area_scope.
        $this->removeStaleAndRetract($dryRun, $stats, $onlyMsgid);
        // 1a. Pull rippled-in copies stranded when the HOME post is deleted or moved back to
        //     pending on its origin group. Those actions leave the rippled-in copies Approved
        //     (so the post still has messages_spatial rows) while the origin row is gone or
        //     Pending, so the spatial-null trigger in removeStaleAndRetract never sees them.
        $this->retractCopiesOrphanedByOriginRemoval($dryRun, $stats, $onlyMsgid);
        // 1b. Pull rippled-in posts from any group whose poster has actively left it, so a
        //     leave removes the poster's post from that group (not just their membership).
        $this->pullRippledPostsFromLeftGroups($dryRun, $stats, $onlyMsgid);
        // 1c. Stop-and-retract for posts on a community that has since switched ripple-OUT off
        //     (groups.settings.rippling.out). initialiseNew's gate only stops NEW posts, so
        //     without this a community that opts out keeps expanding everything it had already
        //     started - including on the deploy that first gives it the setting.
        $this->retractOptedOutCommunities($dryRun, $stats, $onlyMsgid);

        // 2. Initialise reach for posts new to messages_spatial.
        $this->initialiseNew($dryRun, $limit, $stats, $onlyMsgid, $withinPolyWkt);

        // 3. Advance reach for posts whose next tick is due — active hours only.
        if ($this->inActiveHours()) {
            $this->advanceDue($dryRun, $limit, $stats, $onlyMsgid, $withinPolyWkt);
        }

        return $stats;
    }


    /**
     * Backfill: shrink the stored reach of EXISTING active posts whose reach was
     * computed before the audience-budget cap (config freegle.ripple.extent) was
     * turned on, so already-over-reached posts stop covering more than
     * ~target_users members for the rest of their life.
     *
     * Pure reach-geometry shrink: it re-fetches the now-capped schedule for each
     * post and overwrites the stored polygon + schedule at the post's CURRENT
     * tick. It deliberately does NOT ripple into/out of groups, touch
     * memberships, or bump updated_at — so it generates no mail (sendReachDigests
     * only scans recently-updated rows, and the rippling_reach_notified ledger
     * blocks re-notification regardless) and never retracts copies already
     * delivered to far groups (we shrink future reach, we don't claw back).
     *
     * No-op unless the cap is active. Only rows whose pool (total_freeglers)
     * exceeds the cap are candidates — nothing else can be over it. Galera-safe:
     * one row per UPDATE.
     *
     * @return array{candidates:int, shrunk:int, skipped:int}
     */
    public function recomputeReach(bool $dryRun = false, int $limit = 1000, ?int $onlyMsgid = null): array
    {
        $stats = ['candidates' => 0, 'shrunk' => 0, 'skipped' => 0, 'groups_before' => 0, 'groups_after' => 0];

        $target = (int) config('freegle.ripple.extent.target_users', 0);
        if (!config('freegle.ripple.extent.enabled') || $target <= 0) {
            return $stats; // cap not active — there is nothing smaller to shrink to
        }

        $cols = ['msgid', 'lat', 'lng', 'tick', 'total_freeglers', 'rejected_groups', 'status'];
        if ($this->densityColumnsReady()) {
            $cols[] = 'max_minutes_cap';
        }
        $q = DB::table('rippling_reach')
            ->select(array_merge($cols, [
                // current footprint, for the crosspost-breadth stat
                new Alias(new StAsText('polygon'), 'cur_wkt'),
            ]))
            ->where('status', '!=', 'rejected')            // active reach rows only
            ->where('total_freeglers', '>', $target);      // only rows that can exceed the cap
        if ($onlyMsgid !== null) {
            $q->where('msgid', $onlyMsgid);
        }
        $rows = $q->orderBy('msgid')->limit($limit)->get();

        foreach ($rows as $row) {
            $stats['candidates']++;

            // Re-fetch the schedule from the stored (already-blurred) origin. With
            // the cap now configured ReachService sends target_users, so this comes
            // back capped to the nearest ~target_users freeglers. The post keeps the
            // reach BUDGET it was sized with - this pass shrinks the audience, and
            // silently re-sizing to the flat cap here would undo the density decision.
            $schedule = $this->reach->computeSchedule(
                (float) $row->lat,
                (float) $row->lng,
                isset($row->max_minutes_cap) && $row->max_minutes_cap !== null
                    ? (float) $row->max_minutes_cap
                    : null
            );
            if ($schedule === null || empty($schedule['ticks'])) {
                $stats['skipped']++;
                continue; // routing unreachable this run — safe to retry later
            }

            $ticks = $schedule['ticks'];
            $newMax = (int) ($ticks[count($ticks) - 1]['cumulative_users'] ?? 0);
            // Only proceed if the recomputed reach really is smaller than the pool
            // (i.e. the cap actually bound for this origin).
            if ($newMax <= 0 || $newMax >= (int) $row->total_freeglers) {
                $stats['skipped']++;
                continue;
            }

            $entry = $this->entryForTick($ticks, (int) $row->tick);
            $tickGeom = $this->resolveTickGeometry($entry, (float) $row->lat, (float) $row->lng);
            if ($tickGeom === null) {
                $stats['skipped']++;
                continue;
            }
            $tickWkt = $tickGeom['wkt'];

            $storeWkt = $this->unionWithOriginGroupArea((int) $row->msgid, $tickWkt);

            // Cross-post breadth: how many groups the post hits under its CURRENT reach
            // vs under the CAPPED reach, counted with the SAME selection the live ripple
            // uses (rippleIntoNewGroups). Accumulated for both dry-run and live so a
            // dry-run reports the cap's headline impact on crossposting before any write.
            $stats['groups_before'] += $this->countCrosspostGroups((string) $row->cur_wkt);
            $stats['groups_after'] += $this->countCrosspostGroups($storeWkt);

            if ($dryRun) {
                $stats['shrunk']++;
                continue;
            }

            // updated_at omitted - the raw form's self-assignment suppressed an ON
            // UPDATE auto-bump that rippling_reach.updated_at does not actually have
            // (verified: empty `extra` in information_schema - see
            // ReachBoundsService::nullInner()'s docblock for the same finding), so the
            // reach mailer still never reconsiders this row either way.
            // Polygon + derived bounds in ONE statement; envelope retry on throw.
            $shrinkValues = [
                'polygon' => new StGeomFromText(Value::of($storeWkt), self::SRID),
                'schedule' => json_encode($ticks),
                'reachable_group_ids' => json_encode($this->tickReachableIds($entry, $schedule)),
                'total_freeglers' => (int) $schedule['total_freeglers'],
                'max_drive_min' => $schedule['max_drive_min'],
            ];
            $boundsSet = $this->boundsSetSql($storeWkt);
            try {
                DB::table('rippling_reach')->where('msgid', $row->msgid)->update($shrinkValues + $boundsSet);
            } catch (\Throwable $e) {
                if ($boundsSet === []) {
                    throw $e;
                }
                DB::table('rippling_reach')->where('msgid', $row->msgid)
                    ->update($shrinkValues + $this->boundsEnvelopeSql($storeWkt));
            }
            // Preserve any secondary-group "out of area" rejection clips (the clip
            // statement shrinks polygon and NULLs inner_bound atomically).
            $this->reapplyClips((int) $row->msgid, $row->rejected_groups ?? null);
            // Routing-provided bounds upgrade the columns after the clips, verified
            // against the FINAL stored polygon.
            if ($tickGeom['outer'] !== null) {
                $this->bounds->sync((int) $row->msgid, $tickGeom['outer'], $tickGeom['inner']);
            }

            $stats['shrunk']++;
            $this->logEvent((int) $row->msgid, 'reach_shrunk', (int) $row->tick, $entry);
        }

        return $stats;
    }

    /**
     * Count the Freegle groups a reach polygon would crosspost into — the same
     * publish/type/onhere/playground/polyindex + ST_Intersects selection
     * rippleIntoNewGroups uses (minus the per-poster re-ripple guards, which are
     * post-specific and not part of a breadth measure). Used by recomputeReach to
     * report how the audience cap narrows cross-posting.
     */
    private function countCrosspostGroups(string $wkt): int
    {
        if ($wkt === '') {
            return 0;
        }

        $excluded = $this->optOut->excludedGroupIds(GroupRippleOptOut::DIRECTION_IN);

        return DB::table('groups as g')
            ->where('g.publish', 1)
            ->where('g.type', 'Freegle')
            ->where('g.onhere', 1)
            ->where('g.nameshort', 'NOT LIKE', '%playground%')
            ->whereNotNull('g.polyindex')
            ->where(new Comparison(new StGeometryType('g.polyindex'), '<>', Value::of('POINT')))
            ->where(new StIntersects('g.polyindex', new StGeomFromText(Value::of($wkt), self::SRID)))
            ->when(!empty($excluded), fn ($q) => $q->whereNotIn('g.id', $excluded))
            ->count();
    }

    /**
     * Stop-and-retract for every post that has left messages_spatial — rejected/removed on its
     * origin group, withdrawn, expired or deleted (Taken/Received stay in messages_spatial and
     * are intentionally excluded). For each such post we drop its rippling_reach row (which both
     * stops further expansion and lets ripple:release-replies treat the post as gone, releasing
     * any held replies) and retract every rippled-in copy (see retractRippledCopiesForRemovedPost).
     *
     * Scope: only --msgid restricts this (controlled single-post testing). It is intentionally
     * NOT area-scoped - retracting an already-rippled copy must complete regardless of whether the
     * post's origin is still inside the current trial polygon, otherwise copies are stranded in
     * receiving groups when a group leaves the experiment. $stats['removed'] = reach rows dropped.
     */
    private function removeStaleAndRetract(bool $dryRun, array &$stats, ?int $onlyMsgid = null): void
    {
        try {
            $stale = DB::table('rippling_reach as mr')
                ->select('mr.msgid as msgid')
                ->leftJoin('messages_spatial as ms', 'ms.msgid', '=', 'mr.msgid')
                ->whereNull('ms.msgid')
                ->where('mr.status', '!=', 'held')
                ->when($onlyMsgid !== null, fn ($q) => $q->where('mr.msgid', $onlyMsgid))
                ->get();
            if ($stale->isEmpty()) {
                return;
            }
            $msgids = $stale->map(static fn ($r) => (int) $r->msgid)->all();

            if ($dryRun) {
                $stats['removed'] += count($msgids);
                $stats['pulled_on_removal'] += (int) DB::table('messages_groups')
                    ->whereIn('msgid', $msgids)
                    ->where('rippled_in', 1)
                    ->where('deleted', 0)
                    ->count();

                return;
            }

            foreach ($msgids as $msgid) {
                $this->retractRippledCopiesForRemovedPost($msgid, $stats);
                DB::table('rippling_reach')->where('msgid', $msgid)->delete();
                $stats['removed']++;
            }
        } catch (\Throwable $e) {
            $stats['errors']++;
            Log::warning("ripple: remove-stale-and-retract failed: {$e->getMessage()}");
        }
    }

    /**
     * Stop-and-retract every post whose community has switched ripple-OUT off
     * (groups.settings.rippling.out) since the post started rippling.
     *
     * initialiseNew's opt-out gate only keeps NEW posts from starting, so on its own it would
     * leave a phantom or training community's in-flight ripples expanding for the rest of their
     * life - and on the deploy that first writes the setting, EVERY live practice post there
     * would keep going. This closes that: drop the reach row (which stops expansion and takes the
     * post out of every reach-driven read path), pull the copies already delivered - exactly as
     * removeStaleAndRetract does for a post that has left the browsable set - and release any
     * replies still held against the reach we are dropping.
     *
     * Skips 'held' rows for the same reason the other retraction paths do: their copies are
     * deliberately Pending for per-group moderation. A held post that is later re-approved
     * flips back out of 'held' and is caught by the next run of this pass.
     *
     * Scope: only --msgid restricts it, matching removeStaleAndRetract - retracting a committed
     * copy must complete regardless of the current area scope.
     */
    private function retractOptedOutCommunities(bool $dryRun, array &$stats, ?int $onlyMsgid = null): void
    {
        try {
            $excluded = $this->optOut->excludedGroupIds(GroupRippleOptOut::DIRECTION_OUT);
            if (empty($excluded)) {
                return;
            }

            // ms.groupid is the post's own community (messages_spatial.msgid is UNIQUE). A post
            // whose community opted out is matched here however far it had already spread.
            $q = DB::table('rippling_reach as mr')
                ->join('messages_spatial as ms', 'ms.msgid', '=', 'mr.msgid')
                ->where('mr.status', '<>', 'held')
                ->whereIn('ms.groupid', $excluded);
            if ($onlyMsgid !== null) {
                $q->where('mr.msgid', $onlyMsgid);
            }
            $msgids = $q->pluck('mr.msgid')->map(static fn ($id) => (int) $id)->all();
            if (empty($msgids)) {
                return;
            }

            if ($dryRun) {
                $stats['removed'] += count($msgids);
                $stats['pulled_on_removal'] += (int) DB::table('messages_groups')
                    ->whereIn('msgid', $msgids)
                    ->where('rippled_in', 1)
                    ->where('deleted', 0)
                    ->count();

                return;
            }

            foreach ($msgids as $msgid) {
                $this->retractRippledCopiesForRemovedPost($msgid, $stats);
                // Release any still-held replies BEFORE dropping the reach row. The post is live
                // (it is still in messages_spatial), so these replies are real people waiting on
                // a ripple that is never coming: with no reach row, ripple:release-replies takes
                // the "transiently absent, wait for re-initialisation" branch and would hold them
                // for ever, and initialiseNew will never re-create the row. Releasing hands them
                // to the offerer, which is what would have happened when the reach reached them.
                // (Replies made from here on are not held at all - the Go gate only holds when a
                // reach row exists, so it fails open.)
                $stats['released_on_opt_out'] = ($stats['released_on_opt_out'] ?? 0)
                    + $this->replies->releaseAll($msgid, 'community-opted-out');
                DB::table('rippling_reach')->where('msgid', $msgid)->delete();
                $stats['removed']++;
                Log::info("ripple: retracted $msgid - its community has rippling switched off");
            }
        } catch (\Throwable $e) {
            $stats['errors']++;
            Log::warning("ripple: retract-opted-out-communities failed: {$e->getMessage()}");
        }
    }

    /**
     * Pull rippled-in copies stranded when the HOME post is no longer live-approved on its
     * origin group. removeStaleAndRetract only fires once a post has left messages_spatial
     * entirely, but a mod Delete or Back-to-Pending on the origin group leaves the rippled-in
     * copies Approved (so the msgid still has spatial rows) while the origin row is gone (Delete
     * hard-deletes it) or Pending (Back-to-Pending) - so that trigger never sees them and the
     * copies are stranded on the neighbouring groups. This catches exactly that case: an
     * active-reach post with a live rippled_in copy but NO live Approved origin row. Reuses the
     * same retraction (soft-delete + Message/Deleted log + ripple-membership cleanup, no
     * Group/Left) and drops the reach row so it stops spreading; a later re-approval on the home
     * group re-ripples it afresh. Best-effort: never breaks the run.
     */
    private function retractCopiesOrphanedByOriginRemoval(bool $dryRun, array &$stats, ?int $onlyMsgid = null): void
    {
        try {
            $orphaned = DB::table('rippling_reach as mr')
                ->select('mr.msgid as msgid')
                ->distinct()
                ->join('messages_groups as mg', function ($j) {
                    $j->on('mg.msgid', '=', 'mr.msgid')
                      ->where('mg.rippled_in', 1)
                      ->where('mg.deleted', 0);
                })
                ->where('mr.status', '!=', 'held')
                ->whereNotExists(function ($q) {
                    $q->from('messages_groups as o')
                      ->whereColumn('o.msgid', 'mr.msgid')
                      ->where('o.rippled_in', 0)
                      ->where('o.deleted', 0)
                      ->where('o.collection', 'Approved');
                })
                ->when($onlyMsgid !== null, fn ($q) => $q->where('mr.msgid', $onlyMsgid))
                ->get();
            if ($orphaned->isEmpty()) {
                return;
            }
            $msgids = $orphaned->map(static fn ($r) => (int) $r->msgid)->all();

            if ($dryRun) {
                $stats['pulled_on_removal'] += (int) DB::table('messages_groups')
                    ->whereIn('msgid', $msgids)
                    ->where('rippled_in', 1)
                    ->where('deleted', 0)
                    ->count();

                return;
            }

            foreach ($msgids as $msgid) {
                $this->retractRippledCopiesForRemovedPost($msgid, $stats);
                DB::table('rippling_reach')->where('msgid', $msgid)->delete();
                $stats['removed']++;
            }
        } catch (\Throwable $e) {
            $stats['errors']++;
            Log::warning("ripple: retract-orphaned-copies failed: {$e->getMessage()}");
        }
    }

    /**
     * Pull every rippled-in copy of a post that has left the browsable set and clean up the
     * memberships rippling created for it. Soft-deletes (deleted=1) each rippled_in
     * messages_groups copy with a Message/Deleted audit log, then — for each group the copy was
     * pulled from — removes the poster's ripple-joined (rippled=1) membership IFF they have no
     * other live post on that group, so a retracted post does not strand the poster in groups
     * they only joined to carry it.
     *
     * Deliberately writes NO Group/Left log for the membership removal. The re-ripple guard
     * (rippleIntoNewGroups / addPosterMembershipToRippledGroups / pullRippledPostsFromLeftGroups)
     * treats ANY Group/Left after a Group/Joined text='Rippled' as the poster opting out of that
     * group, so a Left here would permanently bar this poster's FUTURE posts from rippling into
     * the group (the trigger also fires on withdraw/expire, not just rejection). The removal is a
     * system cleanup, not an opt-out — the retraction itself is audited by Message/Deleted, and
     * the dangling Joined='Rippled' (no Left, no membership) is exactly what lets a later ripple
     * re-add the membership. Idempotent (only touches deleted=0 rows). Best-effort: never breaks
     * the run.
     */
    private function retractRippledCopiesForRemovedPost(int $msgid, array &$stats): void
    {
        $posterId = DB::table('messages')->where('id', $msgid)->value('fromuser');

        $groupids = DB::table('messages_groups')
            ->where('msgid', $msgid)
            ->where('rippled_in', 1)
            ->where('deleted', 0)
            ->pluck('groupid');

        foreach ($groupids as $groupid) {
            $this->retractRippledCopyInGroup(
                $msgid,
                (int) $groupid,
                $posterId,
                'Rippling: removed on origin removal',
                'pulled_on_removal',
                $stats
            );
        }
    }

    /**
     * Reach-scoped retraction (cap-backlog cleanup): for an ACTIVE rippled post,
     * soft-delete its rippled-in copies in the groups whose polygon no longer
     * intersects the post's (capped) reach polygon, while leaving the post live on
     * the origin group and every still-reached group. The underlying message row is
     * untouched, so existing chats/replies (keyed on msgid) keep working and still
     * link to the open post — removing a copy only takes it out of that group's
     * browse. Skips posts with a terminal outcome (taken/promised/received): those
     * are complete and must not be disturbed. Galera-safe (one row per statement).
     *
     * @return int groups retracted for this post (also accumulates into $stats)
     */
    public function retractOutOfReachCopies(int $msgid, bool $dryRun, array &$stats): int
    {
        if ($this->hasTerminalOutcome($msgid)) {
            $stats['skipped_terminal'] = ($stats['skipped_terminal'] ?? 0) + 1;
            return 0;
        }

        // Rippled-in groups whose polyindex no longer intersects the post's current
        // (capped) reach polygon — i.e. groups we would NOT ripple into now. With the
        // reachable-gate on, ALSO retract groups the polygon still covers but which are
        // no longer in the node-reachable set (rr.reachable_group_ids), so a reach that
        // overshot water self-heals. The `rr.reachable_group_ids IS NOT NULL` guard means
        // reaches computed before the gate rolled out (NULL column) are never retracted by
        // it - only polygon-based retraction applies to them.
        // JSON_LENGTH > 0: an EMPTY stored set means "gate could not compute" (zero
        // members found is indistinguishable from a transient members-query failure),
        // and targeting already treats [] as unavailable - so retraction must never
        // act on it either, or one bad routing-side query would retract every copy
        // of the post. Polygon-based retraction still applies to such rows.
        $rows = DB::table('messages_groups as mg')
            ->select('g.id')
            ->join('groups as g', 'g.id', '=', 'mg.groupid')
            ->join('rippling_reach as rr', 'rr.msgid', '=', 'mg.msgid')
            ->where('mg.msgid', $msgid)
            ->where('mg.rippled_in', 1)
            ->where('mg.deleted', 0)
            ->where('rr.status', '<>', 'held')
            ->whereNotNull('g.polyindex')
            ->where(new Comparison(new StGeometryType('g.polyindex'), '<>', Value::of('POINT')))
            ->where(function ($q) {
                $q->whereNot(new StIntersects('g.polyindex', 'rr.polygon'));
                if ($this->reachableGateEnabled()) {
                    // JSON_LENGTH > 0: an EMPTY stored set means "gate could not compute"
                    // (zero members found is indistinguishable from a transient
                    // members-query failure), and targeting already treats [] as
                    // unavailable - so retraction must never act on it either, or one
                    // bad routing-side query would retract every copy of the post.
                    $q->orWhere(function ($q2) {
                        $q2->whereNotNull('rr.reachable_group_ids')
                            ->where(new JsonValid('rr.reachable_group_ids'))
                            ->whereJsonLength('rr.reachable_group_ids', '>', 0)
                            ->whereNot(new JsonContains('rr.reachable_group_ids', new CastAs('g.id', 'JSON')));
                    });
                }
            })
            ->get();

        if ($dryRun) {
            $n = count($rows);
            $stats['would_retract_groups'] = ($stats['would_retract_groups'] ?? 0) + $n;
            return $n;
        }

        $posterId = DB::table('messages')->where('id', $msgid)->value('fromuser');
        $retracted = 0;
        foreach ($rows as $r) {
            $before = $stats['pulled_out_of_reach'] ?? 0;
            $this->retractRippledCopyInGroup(
                $msgid,
                (int) $r->id,
                $posterId,
                'Rippling: out of capped reach',
                'pulled_out_of_reach',
                $stats
            );
            if (($stats['pulled_out_of_reach'] ?? 0) > $before) {
                $retracted++;
            }
        }
        return $retracted;
    }

    /**
     * Soft-delete one rippled-in copy and, when the poster has no OTHER live post on
     * the group, remove the ripple-join membership (rippled=1; an organic membership
     * is never touched). Writes a Message/Deleted log but deliberately NO Group/Left
     * log, so a later ripple can re-add the membership. Shared by the origin-removal
     * and out-of-reach retraction paths. Galera-safe: one row per statement.
     */
    private function retractRippledCopyInGroup(int $msgid, int $groupid, $posterId, string $reason, string $statKey, array &$stats): void
    {
        $n = DB::table('messages_groups')
            ->where('msgid', $msgid)
            ->where('groupid', $groupid)
            ->where('rippled_in', 1)
            ->where('deleted', 0)
            ->update(['deleted' => 1]);
        if ($n < 1) {
            return;
        }
        $stats[$statKey] = ($stats[$statKey] ?? 0) + 1;
        DB::table('logs')->insert([
            'timestamp' => now(),
            'type' => 'Message',
            'subtype' => 'Deleted',
            'user' => $posterId,
            'byuser' => null,
            'groupid' => $groupid,
            'msgid' => $msgid,
            'text' => $reason,
        ]);

        if (!$posterId) {
            return;
        }
        // Only remove the membership when this poster has no OTHER live post on the group
        // (the copy we just pulled is now deleted=1, so it is excluded by deleted=0).
        $hasOtherPost = DB::table('messages_groups as mg')
            ->join('messages as m', 'm.id', '=', 'mg.msgid')
            ->where('m.fromuser', $posterId)
            ->where('mg.groupid', $groupid)
            ->where('mg.deleted', 0)
            ->exists();
        if ($hasOtherPost) {
            return;
        }
        // Only a ripple-join (rippled=1) is removed; an organic membership is never touched.
        $removed = DB::table('memberships')
            ->where('userid', $posterId)
            ->where('groupid', $groupid)
            ->where('rippled', 1)
            ->delete();
        if ($removed > 0) {
            $stats['memberships_removed'] = ($stats['memberships_removed'] ?? 0) + 1;
        }
    }

    /**
     * One-off remediation for the window before rippling honoured users_banned: for every
     * ripple-join (memberships.rippled=1) into a group the member is now banned from, soft-delete
     * that member's rippled-in post copies still live there and remove the ripple-join membership.
     *
     * A ban (users_banned row, no expiry) is an explicit mod ejection that deletes the membership
     * and withdraws the poster's posts; the unguarded ripple used to re-join the banned poster and
     * re-insert their post. Only ripple-joins (rippled=1) are removed — an organic membership is
     * never touched. Driven off the membership rows, so it targets exactly "banned users who were
     * (re-)joined by rippling". Galera-safe: one row per statement; honours --dry-run.
     *
     * @return array{pairs:int,memberships_removed:int,posts_pulled:int}
     */
    public function pullBannedRippleMemberships(?int $userId, int $limit, bool $dryRun, array &$stats): array
    {
        $q = DB::table('memberships as m')
            ->join('users_banned as ub', function ($j) {
                $j->on('ub.userid', '=', 'm.userid')->on('ub.groupid', '=', 'm.groupid');
            })
            ->where('m.rippled', 1);
        if ($userId !== null) {
            $q->where('m.userid', $userId);
        }
        $pairs = $q->orderBy('m.userid')->orderBy('m.groupid')
            ->limit($limit)
            ->get(['m.userid', 'm.groupid']);

        foreach ($pairs as $p) {
            $stats['pairs'] = ($stats['pairs'] ?? 0) + 1;
            $userid = (int) $p->userid;
            $groupid = (int) $p->groupid;

            // Soft-delete this banned member's rippled-in post copies still live in the group.
            $msgids = DB::table('messages_groups as mg')
                ->join('messages as msg', 'msg.id', '=', 'mg.msgid')
                ->where('msg.fromuser', $userid)
                ->where('mg.groupid', $groupid)
                ->where('mg.rippled_in', 1)
                ->where('mg.deleted', 0)
                ->pluck('mg.msgid');
            foreach ($msgids as $msgid) {
                if ($dryRun) {
                    $stats['posts_pulled'] = ($stats['posts_pulled'] ?? 0) + 1;
                    continue;
                }
                // Reuses the shared retraction (Message/Deleted log, no Group/Left). It removes the
                // membership only when no OTHER live post remains; the explicit delete below covers
                // the case where an organic post keeps it, since a banned member must be off entirely.
                $this->retractRippledCopyInGroup(
                    (int) $msgid,
                    $groupid,
                    $userid,
                    'Rippling: pulled - poster banned from group',
                    'posts_pulled',
                    $stats
                );
            }

            // Remove the ripple-join membership itself (idempotent with the above).
            if ($dryRun) {
                $exists = DB::table('memberships')
                    ->where('userid', $userid)->where('groupid', $groupid)->where('rippled', 1)
                    ->exists();
                if ($exists) {
                    $stats['memberships_removed'] = ($stats['memberships_removed'] ?? 0) + 1;
                }
                continue;
            }
            $removed = DB::table('memberships')
                ->where('userid', $userid)
                ->where('groupid', $groupid)
                ->where('rippled', 1)
                ->delete();
            if ($removed > 0) {
                $stats['memberships_removed'] = ($stats['memberships_removed'] ?? 0) + 1;
            }
        }

        return $stats;
    }

    private function initialiseNew(bool $dryRun, int $limit, array &$stats, ?int $onlyMsgid = null, ?string $withinPolyWkt = null): void
    {
        // Go-live flood guard: only posts that arrived on or after the configured
        // cutoff ever start rippling, so flipping RIPPLE_ENABLED on does not make the
        // entire historical pending backlog eligible at once. Empty config = no cutoff.
        $enabledAt = config('freegle.ripple.enabled_at');

        // Candidate source: live posts with NO reach row yet (anti-join).
        $q = DB::table('messages_spatial as ms')
            ->select([
                'ms.msgid as msgid',
                new Alias(new AnyValue(new StY('ms.point')), 'lat'),
                new Alias(new AnyValue(new StX('ms.point')), 'lng'),
                new Alias(new Min('ms.arrival'), 'arrival'),
            ])
            ->leftJoin('rippling_reach as mr', 'mr.msgid', '=', 'ms.msgid')
            ->whereNull('mr.msgid');

        if ($onlyMsgid !== null) {
            // A single chosen post (controlled test) targets its msgid directly and bypasses the
            // arrival cutoff AND the reply-saturation stop — the chosen post may predate go-live or
            // already be saturated, and selecting nothing would be a surprising no-op for an
            // explicit one-post request.
            $q->where('ms.msgid', $onlyMsgid);
        } else {
            // An area scope is an ADDITIONAL filter on top of normal behaviour: the go-live arrival
            // cutoff still applies, so an area run ripples only the recent (post-cutoff) posts inside
            // the polygon rather than the whole historical backlog there.
            if ($withinPolyWkt !== null) {
                $q->where(new StContains(new StGeomFromText(Value::of($withinPolyWkt), self::SRID), 'ms.point'));
            }
            if (!empty($enabledAt)) {
                $q->where('ms.arrival', '>=', $enabledAt);
            }
            // Reply-saturation stop (extent-governor T1.1): a post that already has >= threshold
            // distinct repliers never starts rippling - it has enough interest without reach.
            // 0 disables. Applies to normal and scoped (experiment) runs alike.
            $satStop = (int) config('freegle.ripple.reply_saturation_stop', 5);
            if ($satStop > 0) {
                $q->where(function ($sub) {
                    $sub->from('chat_messages as cm')
                        ->whereColumn('cm.refmsgid', 'ms.msgid')
                        ->where('cm.type', 'Interested')
                        ->select(new CountDistinct('cm.userid'));
                }, '<', $satStop);
            }
        }

        // Ripple-OUT opt-out (groups.settings.rippling.out): a post on a community that has
        // switched rippling off never gets a reach row, so it is never crossposted and never
        // appears in another member's nearby feed (both read paths hang off rippling_reach).
        // This is a community-level policy rather than a rollout guard, so unlike the arrival
        // cutoff and the saturation stop it applies to --msgid and area runs too.
        //
        // messages_spatial.msgid is UNIQUE, so ms.groupid is the post's single recorded
        // community. A candidate here has no reach row, hence has never rippled, so that
        // recorded community is one it was posted to directly rather than rippled into. The
        // NULL arm matters: groupid is nullable and `NULL NOT IN (...)` is NULL, which would
        // silently drop every group-less row from the candidate set.
        $outOptOut = $this->optOut->excludedGroupIds(GroupRippleOptOut::DIRECTION_OUT);
        if (!empty($outOptOut)) {
            $q->where(function ($sub) use ($outOptOut) {
                $sub->whereNull('ms.groupid')->orWhereNotIn('ms.groupid', $outOptOut);
            });
        }

        $rows = $q->groupBy('ms.msgid')->limit($limit)->get();

        // ── Phase 1: compute reach schedules CONCURRENTLY, deduped by blurred origin ──
        //
        // computeSchedule is a deterministic function of the blurred origin (and config), so
        // posts sharing a blurred origin (e.g. the same postcode centroid - measured ~2.6x on
        // prod) need the routing server hit only ONCE. We blur every post, collapse to the set
        // of DISTINCT origins, and fan those out across the routing server with Http::pool (one
        // Dijkstra per request, CPU-bound on the routing host - cap the fan-out near its core
        // count). This is the only parallelised part; the DB writes below stay strictly serial.
        //
        // Blur (~400m, BLUR_USER) keeps the reach no more precise than the location Freegle
        // already exposes elsewhere, so the reach polygon is not a location oracle (#privacy);
        // it is deterministic per location, which is exactly what makes the de-dup exact.
        $blurredByRow = [];   // row index => ['lat'=>, 'lng'=>]
        $distinctOrigins = []; // "lat,lng" => ['lat'=>, 'lng'=>]
        foreach ($rows as $i => $row) {
            if ($row->arrival === null) {
                continue; // handled (with the warning) in Phase 2
            }
            [$lat, $lng] = $this->blurOrigin((float) $row->lat, (float) $row->lng);
            $blurredByRow[$i] = ['lat' => $lat, 'lng' => $lng, 'key' => $lat . ',' . $lng];
            $distinctOrigins[$lat . ',' . $lng] = ['lat' => $lat, 'lng' => $lng];
        }

        // Every reach grows to the SAME budget: the widest any band earns. The cap
        // belongs to the person who would travel, not to the item (DensityService
        // docblock), and a post cannot know which bands the members around it fall
        // in - so it must reach far enough for the sparse ones and let each member be
        // admitted on their own band on the way out. Sizing this at the origin
        // instead is what left a rural member permanently unable to see their nearest
        // town's posts.
        //
        // The origin's own band is still measured and stored: it is what the row is
        // read back by, and the density analytics compare bands against each other.
        // It is a description of where the post is, not the limit on where it goes.
        $ceiling = DensityService::ceiling();
        $capByKey = [];
        foreach ($distinctOrigins as $k => $o) {
            $capByKey[$k] = $this->density->capFor($o['lat'], $o['lng']);
            $distinctOrigins[$k]['max_minutes'] = $ceiling;
        }

        $scheduleByKey = []; // "lat,lng" => parsed schedule | null

        // Reuse: a reach schedule is a deterministic function of the blurred origin (+ global ripple
        // config - see the Phase-1 note above), so if another live post at the SAME blurred origin
        // already has a real computed reach, copy its schedule rather than hit the routing server
        // again. This covers "same user posting again from home" and any co-located posts; on the
        // same-origin batch (many posts share a postcode/home) it removes the bulk of routing calls.
        // Exact because blurOrigin quantises to 4dp and only the blurred origin + global config feed
        // the schedule. If that config (curve/max_minutes/extent) ever changes, set
        // freegle.ripple.reuse_reach=false to disable if stale reuse is ever suspected.
        if (config('freegle.ripple.reuse_reach', true) && !empty($distinctOrigins)) {
            $pairs = array_values($distinctOrigins);
            $placeholders = implode(',', array_fill(0, count($pairs), '(?, ?)'));
            $reuseParams = [];
            foreach ($pairs as $p) {
                $reuseParams[] = $p['lat'];
                $reuseParams[] = $p['lng'];
            }
            $capCol = $this->densityColumnsReady() ? ', max_minutes_cap' : '';
            // keep-raw: row-constructor `(lat, lng) IN ((?,?),(?,?)...)` - the builder cannot render a tuple IN
            $existing = DB::select(
                'SELECT lat, lng, schedule, total_freeglers, max_drive_min' . $capCol . '
                 FROM rippling_reach
                 WHERE schedule IS NOT NULL AND (lat, lng) IN (' . $placeholders . ')',
                $reuseParams
            );
            foreach ($existing as $e) {
                // Rebuild the same 4dp key the distinctOrigins map uses, so PHP-float and DB-double
                // string formatting agree (both sides are round(...,4)).
                $k = round((float) $e->lat, 4) . ',' . round((float) $e->lng, 4);
                if (!isset($distinctOrigins[$k]) || isset($scheduleByKey[$k])) {
                    continue; // not one of this batch's origins, or already reused
                }
                // A stored schedule computed under a DIFFERENT reach budget is not this
                // post's schedule, however co-located the two posts are. Every reach now
                // grows to the ceiling, so this mostly guards the rows written before
                // that - and it is what makes a change to the ceiling take effect
                // everywhere rather than only where nobody had posted before.
                if ($capCol !== '') {
                    $storedCap = $e->max_minutes_cap === null ? null : (float) $e->max_minutes_cap;
                    if ($storedCap === null || abs($storedCap - $ceiling) > 0.001) {
                        continue;
                    }
                }
                $ticks = json_decode($e->schedule, true);
                if (!is_array($ticks) || empty($ticks)) {
                    continue;
                }
                $scheduleByKey[$k] = [
                    'ticks' => $ticks,
                    'total_freeglers' => (int) $e->total_freeglers,
                    'max_drive_min' => (float) $e->max_drive_min,
                ];
                unset($distinctOrigins[$k]); // reused - do not recompute this origin on the routing server
                $stats['reused'] = ($stats['reused'] ?? 0) + 1;
            }
        }

        $concurrency = max(1, (int) config('freegle.ripple.compute_concurrency', 8));
        $keys = array_keys($distinctOrigins);
        foreach (array_chunk($keys, $concurrency) as $keyChunk) {
            $origins = array_map(static fn ($k) => $distinctOrigins[$k], $keyChunk);
            $results = $this->reach->computeSchedulesBatch($origins);
            foreach (array_values($keyChunk) as $j => $k) {
                $scheduleByKey[$k] = $results[$j] ?? null;
            }
        }

        // ── Phase 2: apply each post's schedule serially (one DB writer - Galera-safe) ──
        // DO NOT parallelise this loop: the rippling_reach / messages_groups / memberships
        // writes must stay single-writer and in order.
        foreach ($rows as $i => $row) {
            try {
                if ($row->arrival === null) {
                    // Without arrival we cannot place the post on its hazard schedule.
                    Log::warning("ripple: null arrival for msg {$row->msgid}, skipping");
                    $stats['skipped']++;
                    continue;
                }

                $lat = $blurredByRow[$i]['lat'];
                $lng = $blurredByRow[$i]['lng'];

                $cap = $capByKey[$blurredByRow[$i]['key']]
                    ?? ['band' => DensityService::BAND_UNKNOWN, 'radius_miles' => null,
                        'max_minutes' => (float) config('freegle.ripple.max_minutes', 30)];

                $schedule = $scheduleByKey[$blurredByRow[$i]['key']] ?? null;
                if ($schedule === null) {
                    // The blurred origin can snap to a DISCONNECTED routing node (a driveway stub
                    // or isolated segment) whose drive-isochrone reaches almost nothing, so the
                    // schedule comes back empty -> the post is skipped on EVERY run and never
                    // ripples. Because blurOrigin is deterministic this is permanent: ~16% of live
                    // candidates were stranded this way. Fall back to the post's RAW origin, which
                    // is geocoded onto the connected road network. A ~400m blur is imperceptible
                    // against a 30-min drive isochrone (the innermost tick is already km-scale), so
                    // this does not meaningfully reduce origin privacy - it only rescues the posts
                    // the blur would otherwise lose. Costs one extra routing call per stranded post.
                    $schedule = $this->reach->computeSchedule(
                        (float) $row->lat, (float) $row->lng, $ceiling
                    );
                    if ($schedule !== null) {
                        $lat = (float) $row->lat;
                        $lng = (float) $row->lng;
                    }
                }
                if ($schedule === null) {
                    // Genuinely unreachable (raw origin off-graph too) — retry next run.
                    $stats['skipped']++;
                    continue;
                }

                $arrival = Carbon::parse($row->arrival);
                // total_ticks is the hazard-schedule length (the wall-clock plan), NOT the
                // count of usable polygons — some routing ticks may have empty polygons and
                // be filtered out. Keeping these aligned is what lets the 'done' check fire.
                $total = $this->reach->totalTicks();

                // Start at the tick appropriate for how long the post has already been live
                // (back-filled posts get their correct reach at once, not the tiny initial one).
                $elapsedHours = $arrival->diffInMinutes(now()) / 60.0;
                $tick = min($this->reach->tickForElapsedHours($elapsedHours), $total);
                $entry = $this->entryForTick($schedule['ticks'], $tick);
                if ($entry === null) {
                    $stats['skipped']++;
                    continue;
                }
                $next = $this->reach->nextExpansionAfter($arrival, $tick);
                $status = $next === null ? 'done' : 'expanding';

                if (!$dryRun) {
                    $tickGeom = $this->resolveTickGeometry($entry, (float) $lat, (float) $lng);
                    if ($tickGeom === null) {
                        // Routing unreachable mid-run - leave the post for the next pass
                        // rather than storing a reach with no polygon.
                        $stats['skipped']++;
                        continue;
                    }
                    $tickWkt = $tickGeom['wkt'];
                    $storeWkt = $this->unionWithOriginGroupArea((int) $row->msgid, $tickWkt);
                    // Upsert, not plain INSERT: the anti-join guarantees no existing row so this
                    // behaves exactly like INSERT, while staying safe against a concurrent run
                    // seeding the same msgid (created_at is preserved - not in the SET).
                    // Polygon + derived bounds land in the SAME statement (outer_bound is
                    // NOT NULL, and there must never be a window with stale/absent bounds);
                    // envelope retry if derivation throws on pathological geometry.
                    $ready = $this->bounds->ready();
                    $withDensity = $this->densityColumnsReady();
                    $initTail = array_merge([
                        'arrival' => $arrival,
                        'mode' => $this->reach->mode(),
                        'tick' => $tick,
                        'total_ticks' => $total,
                        'total_freeglers' => $schedule['total_freeglers'],
                        'max_drive_min' => $schedule['max_drive_min'],
                        'schedule' => json_encode($schedule['ticks']),
                        'reachable_group_ids' => json_encode($this->tickReachableIds($entry, $schedule)),
                        'next_expansion_at' => $next,
                        'status' => $status,
                    ], $withDensity ? [
                        'density_band' => $cap['band'],
                        'density_radius_miles' => $cap['radius_miles'],
                        'max_minutes_cap' => $ceiling,
                    ] : []);
                    $initStore = function (string $wkt) use ($initTail, $row, $lat, $lng, $ready): void {
                        // Builder::upsert()'s $update entries are keyed shorthand for MySQL's
                        // ON DUPLICATE KEY UPDATE col = VALUES(col), matching every column the
                        // raw form listed there; msgid/created_at are excluded from $update so
                        // created_at is preserved on a duplicate, exactly as the raw form's
                        // ON DUPLICATE KEY UPDATE (which never mentioned created_at) did.
                        $now = now();
                        $geom = new StGeomFromText(Value::of($wkt), self::SRID);
                        $values = array_merge([
                            'msgid' => $row->msgid,
                            'lat' => $lat,
                            'lng' => $lng,
                            'polygon' => $geom,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ], $initTail);
                        if ($ready) {
                            $values['outer_bound'] = new StBuffer(new StSimplify($geom, ReachBoundsService::TOLERANCE), ReachBoundsService::TOLERANCE);
                            $values['inner_bound'] = new StBuffer(new StSimplify($geom, ReachBoundsService::TOLERANCE), -ReachBoundsService::TOLERANCE);
                        }
                        $update = array_values(array_diff(array_keys($values), ['msgid', 'created_at']));
                        try {
                            DB::table('rippling_reach')->upsert($values, ['msgid'], $update);
                        } catch (\Throwable $e) {
                            if (!$ready) {
                                throw $e; // same failure the legacy statement would have had
                            }
                            $values['outer_bound'] = new StEnvelope($geom);
                            $values['inner_bound'] = null;
                            DB::table('rippling_reach')->upsert($values, ['msgid'], $update);
                        }
                    };
                    $storeWkt = $this->storeWithUndoLogShrink($initStore, $storeWkt, (int) $row->msgid);
                    // Routing-provided bounds (tighter than derived) upgrade the columns,
                    // verified against the stored polygon.
                    if ($tickGeom['outer'] !== null) {
                        $this->bounds->sync((int) $row->msgid, $tickGeom['outer'], $tickGeom['inner']);
                    }
                    $this->rippleIntoNewGroups(
                        (int) $row->msgid, $storeWkt, $stats,
                        $this->tickReachableIdsOrNull($entry, $schedule)
                    );
                    // Reach mail is decoupled into the sharded `mail:digest:unified --mode=reach`
                    // pass (UnifiedDigestService::sendReachDigests). It must NOT run inline here:
                    // the 2026-06-24 live profile showed it was ~75% of this serial Phase-2 loop's
                    // wall-clock, and mail has no Galera single-writer constraint. The reach write
                    // above bumps rippling_reach.updated_at, which is the signal that pass picks up.
                }

                $stats['initialized']++;
                $this->logEvent($row->msgid, 'init', $tick, $entry);
            } catch (\Throwable $e) {
                $stats['errors']++;
                Log::warning("ripple: init failed for msg {$row->msgid}: {$e->getMessage()}");
            }
        }
    }

    private function advanceDue(bool $dryRun, int $limit, array &$stats, ?int $onlyMsgid = null, ?string $withinPolyWkt = null): void
    {
        $rows = DB::table('rippling_reach')
            ->where('status', 'expanding')
            ->whereNotNull('next_expansion_at')
            ->where('next_expansion_at', '<=', now())
            ->when($onlyMsgid !== null, fn ($q) => $q->where('msgid', $onlyMsgid))
            ->when($withinPolyWkt !== null, fn ($q) => $q->where(new StContains(
                new StGeomFromText(Value::of($withinPolyWkt), self::SRID),
                new StSrid(new Point('lng', 'lat'), self::SRID)
            )))
            ->limit($limit)
            ->get();

        foreach ($rows as $row) {
            try {
                $ticks = json_decode($row->schedule, true);
                if (!is_array($ticks) || empty($ticks)) {
                    $stats['skipped']++;
                    continue;
                }

                if ($row->arrival === null) {
                    $stats['skipped']++;
                    continue;
                }
                $arrival = Carbon::parse($row->arrival);

                // Reply-saturation stop (extent-governor T1.1): once a post has enough distinct
                // repliers it already has plenty of interest, so stop expanding - mark it done and
                // do not fan out further. Type-agnostic; 0 disables.
                $satStop = (int) config('freegle.ripple.reply_saturation_stop', 5);
                if ($satStop > 0 && $this->distinctReplierCount((int) $row->msgid) >= $satStop) {
                    if (!$dryRun) {
                        DB::table('rippling_reach')->where('msgid', $row->msgid)->update([
                            'status' => 'done',
                            'next_expansion_at' => null,
                            'updated_at' => now(),
                        ]);
                    }
                    $stats['completed']++;
                    $this->logEvent($row->msgid, 'reply_saturated', (int) $row->tick, []);
                    continue;
                }

                // Outcome stop: a post that has been taken/received/withdrawn has left the
                // browsable set, so stop expanding and do not ripple it any further. Checked
                // here against messages_outcomes (not just via removeStale) because removeStale
                // runs on UNSCOPED runs only and keys off messages_spatial, which the separate
                // messages:update-spatial-index cron lags - so without this an already-taken post
                // keeps rippling into new groups for a tick or two after the outcome is recorded.
                if ($this->hasTerminalOutcome((int) $row->msgid)) {
                    if (!$dryRun) {
                        DB::table('rippling_reach')->where('msgid', $row->msgid)->update([
                            'status' => 'done',
                            'next_expansion_at' => null,
                            'updated_at' => now(),
                        ]);
                    }
                    $stats['completed']++;
                    $this->logEvent($row->msgid, 'outcome_stop', (int) $row->tick, []);
                    continue;
                }

                $elapsedHours = $arrival->diffInMinutes(now()) / 60.0;
                // The post's own hazard-schedule length (stored at init), used as the ceiling
                // for both the target tick and the 'done' transition.
                $total = (int) $row->total_ticks;
                $target = min($this->reach->tickForElapsedHours($elapsedHours), $total);

                // A floor set by something we have LEARNED, as opposed to the clock.
                // A scout who replied was outside the reach at the time, so their
                // reply is evidence the item is wanted that far out - and the people
                // around them should get the same chance rather than waiting for the
                // schedule to arrive. Never lowers the target, and never exceeds the
                // post's own schedule length.
                if ($row->min_tick !== null) {
                    $target = min(max($target, (int) $row->min_tick), $total);
                }

                if ($target <= (int) $row->tick) {
                    // Not actually due for a new tick yet — reschedule and move on.
                    if (!$dryRun) {
                        $next = $this->reach->nextExpansionAfter($arrival, (int) $row->tick, $total);
                        DB::table('rippling_reach')->where('msgid', $row->msgid)->update([
                            'next_expansion_at' => $next,
                            'status' => $next === null ? 'done' : 'expanding',
                            'updated_at' => now(),
                        ]);
                    }
                    $stats['skipped']++;
                    continue;
                }

                $entry = $this->entryForTick($ticks, $target);
                if ($entry === null) {
                    $stats['skipped']++;
                    continue;
                }
                $next = $this->reach->nextExpansionAfter($arrival, $target, $total);
                $status = $next === null ? 'done' : 'expanding';

                if (!$dryRun) {
                    $tickGeom = $this->resolveTickGeometry($entry, (float) $row->lat, (float) $row->lng);
                    if ($tickGeom === null) {
                        // Routing unreachable - keep the previous polygon and retry this
                        // tick on the next run (next_expansion_at is already due).
                        $stats['skipped']++;
                        continue;
                    }
                    $tickWkt = $tickGeom['wkt'];
                    $storeWkt = $this->unionWithOriginGroupArea((int) $row->msgid, $tickWkt);
                    // Polygon + derived bounds in ONE statement (no stale-bounds window);
                    // envelope retry if the derivation throws on pathological geometry.
                    // COALESCE(Value::of(...), 'reachable_group_ids'): a NULL slim-schedule
                    // id set (tickReachableIdsJson returns null when the entry carries none)
                    // keeps the stored column, exactly as the raw form's
                    // `COALESCE(?, reachable_group_ids)` with a NULL binding did.
                    $advanceValues = fn (string $wkt): array => [
                        'polygon' => new StGeomFromText(Value::of($wkt), self::SRID),
                        'reachable_group_ids' => new Coalesce(Value::of($this->tickReachableIdsJson($entry)), 'reachable_group_ids'),
                        'tick' => $target,
                        'next_expansion_at' => $next,
                        'status' => $status,
                        'updated_at' => now(),
                    ];
                    $advanceStore = function (string $wkt) use ($advanceValues, $row): void {
                        $boundsSet = $this->boundsSetSql($wkt);
                        try {
                            try {
                                DB::table('rippling_reach')->where('msgid', $row->msgid)
                                    ->update($advanceValues($wkt) + $boundsSet);
                            } catch (\Throwable $e) {
                                // 1713 depends only on the OLD values of the updated
                                // columns, so the envelope variant (same columns) can
                                // only fail the same way - skip straight to the split.
                                if ($boundsSet === [] || $this->isUndoLogTooBig($e)) {
                                    throw $e; // same failure the legacy statement would have had
                                }
                                DB::table('rippling_reach')->where('msgid', $row->msgid)
                                    ->update($advanceValues($wkt) + $this->boundsEnvelopeSql($wkt));
                            }
                        } catch (\Throwable $e) {
                            if (!$this->isUndoLogTooBig($e)) {
                                throw $e;
                            }
                            $this->advanceSplitForUndoLog($wkt, $advanceValues, (int) $row->msgid);
                        }
                    };
                    $storeWkt = $this->storeWithUndoLogShrink($advanceStore, $storeWkt, (int) $row->msgid);
                    // The polygon was just overwritten from the cached schedule, which does NOT
                    // include any secondary-group rejection clips. Re-subtract every rejected
                    // group so a secondary "out of area" rejection survives expansion (#9).
                    // (The clip statement shrinks polygon and NULLs inner_bound atomically.)
                    $this->reapplyClips((int) $row->msgid, $row->rejected_groups ?? null);
                    // Routing-provided bounds (tighter than the derived ones) upgrade the
                    // columns AFTER the clips, verified against the FINAL stored polygon.
                    if ($tickGeom['outer'] !== null) {
                        $this->bounds->sync((int) $row->msgid, $tickGeom['outer'], $tickGeom['inner']);
                    }
                    // Targeting ids for THIS tick: prefer the stored slim schedule's
                    // per-tick set (exact for this drive-time); fall back to the cached
                    // column (schedules stored before per-tick ids existed).
                    $cachedReachable = null;
                    if ($this->reachableGateEnabled()) {
                        $cachedReachable = is_array($entry['reachable_group_ids'] ?? null)
                            ? array_map('intval', $entry['reachable_group_ids'])
                            : null;
                        if ($cachedReachable === null) {
                            $raw = DB::table('rippling_reach')->where('msgid', $row->msgid)->value('reachable_group_ids');
                            $cachedReachable = $raw ? json_decode($raw, true) : null;
                        }
                    }
                    $this->rippleIntoNewGroups((int) $row->msgid, $storeWkt, $stats, $cachedReachable);
                    // Reach mail decoupled into `mail:digest:unified --mode=reach` — see
                    // initialiseNew and UnifiedDigestService::sendReachDigests.
                }

                $stats['expanded']++;
                if ($status === 'done') {
                    $stats['completed']++;
                }
                $this->logEvent($row->msgid, 'expand', $target, $entry);
            } catch (\Throwable $e) {
                $stats['errors']++;
                Log::warning("ripple: advance failed for msg {$row->msgid}: {$e->getMessage()}");
            }
        }
    }

    /**
     * True when the road-reachable ripple gate is enabled (plan 2026-07-06). Off by
     * default and independent of RIPPLE_ENABLED; enable only once the routing server
     * that returns reachable_group_ids is deployed and validated.
     */
    private function reachableGateEnabled(): bool
    {
        return (bool) config('freegle.ripple.reachable_gate', false);
    }

    /**
     * Ripple a post INTO every published group whose area the reach now covers (#6).
     *
     * "Crosses into a new group" = the reach polygon intersects the group's area. A
     * group's area is its DPA (poly) if present, else its CGA (polyofficial) — exactly
     * what groups.polyindex holds (GroupStatsService stores
     * ST_GeomFromText(COALESCE(poly, polyofficial, 'POINT(0 0)'))), so we test the
     * spatial-indexed polyindex and skip the (0,0) point sentinel.
     *
     * Inserts a messages_groups row idempotently (INSERT IGNORE + NOT EXISTS on the existing
     * (msgid,groupid) rows, so the origin group and already-rippled groups are never touched
     * or duplicated). The row is inserted Approved when ripple.rippled_in_pending_hours = 0
     * (the default - no Pending flicker, since the post was already vetted on origin), else
     * Pending so AutoApproveService approves it after the mod-veto window.
     */
    private function rippleIntoNewGroups(int $msgid, string $reachWkt, array &$stats, ?array $reachableGroupIds = null): void
    {
        try {
            // Never ripple a post that has already been taken/received/withdrawn into new groups,
            // even if its reach row has not yet been stopped - covers the tick-0 ripple from
            // initialiseNew and the manual `ripple:expand --msgid=...` path, both of which reach
            // here without advanceDue's outcome-stop having run. messages_outcomes is the source of
            // truth; messages_spatial lags the outcome (see hasTerminalOutcome).
            if ($this->hasTerminalOutcome($msgid)) {
                return;
            }

            // TN posts must not be rippled into new groups while TN still cross-posts the same
            // item to multiple Freegle groups by tnpostid. Once TN is restricted to a single
            // origin group (design.md #10), this guard can be removed.
            $isTn = DB::table('messages')
                ->where('id', $msgid)
                ->whereNotNull('tnpostid')
                ->where('tnpostid', '!=', '')
                ->exists();
            if ($isTn) {
                return;
            }

            // rippled_in_pending_hours = 0 (default) approves the rippled-in row AT ripple-in
            // time, so it never flickers into the Pending mod queue (the post was already
            // vetted on its origin group; matches AutoApproveService::approveOnGroup -
            // collection='Approved', approvedby NULL, approvedat NOW; spatial indexing follows
            // via the message_spatial cron). >0 inserts Pending for the mod-veto window.
            $immediateApprove = ((int) config('freegle.ripple.rippled_in_pending_hours', 0)) <= 0;
            $collection = $immediateApprove ? 'Approved' : 'Pending';
            $approvedAt = $immediateApprove ? 'NOW()' : 'NULL';

            // Resolve the target groups with a plain, NON-LOCKING snapshot SELECT first, then
            // insert each membership row on its own. The previous single INSERT ... SELECT took
            // shared next-key locks on EVERY source row it read - the groups scan, the
            // messages_groups dup check and the triple-nested `logs` "rippled-then-left" scan
            // (100k-2.6M rows) - and held them for the whole statement under REPEATABLE READ. Run
            // concurrently (the scheduler piled up dozens of overlapping runs) those locks collided
            // on the messages_groups msgid index and on `logs`, starving the serial background
            // worker's audit inserts (2026-06-26 1205 lock-wait storm + backlog). A read SELECT
            // takes no row locks; each per-row INSERT IGNORE locks only the row it writes, briefly,
            // and is Galera-safe (one row per statement). Mirrors addPosterMembershipToRippledGroups.
            $msg = DB::table('messages')->where('id', $msgid)->first(['type', 'fromuser']);
            if (!$msg) {
                return;
            }

            // Reachable-gate (plan 2026-07-06): when enabled AND the routing server sent a
            // reachable-group set, restrict targets to groups containing a road node
            // reachable from the origin - so a reach polygon that overshoots water can't
            // ripple across an uncrossable barrier. The polygon ST_Intersects stays as the
            // Ripple-IN opt-out (groups.settings.rippling.in): never crosspost into a community
            // that has switched rippling off. The `%playground%` name test below predates this
            // and stays as belt-and-braces for a playground community created before anyone gives
            // it the setting; the setting is the deliberate, per-community mechanism (set by
            // ripple:opt-out), and the only one that also covers ripple-OUT (see initialiseNew).
            $inOptOut = $this->optOut->excludedGroupIds(GroupRippleOptOut::DIRECTION_IN);

            // cheap spatial-index prefilter; this is an AND gate on top. whereIn() binds
            // the ids as parameters rather than inlining them as literals like the raw
            // form's `(int)`-cast interpolation did - equally injection-safe, just bound
            // instead of escaped. Empty set or gate off => clause omitted => unchanged
            // behaviour (fall back to polygon only).
            $targetGroups = DB::table('groups as g')
                ->select('g.id')
                ->where('g.publish', 1)
                ->where('g.type', 'Freegle')
                ->where('g.onhere', 1)
                ->where('g.nameshort', 'NOT LIKE', '%playground%')
                ->when(!empty($inOptOut), fn ($q) => $q->whereNotIn('g.id', $inOptOut))
                ->whereNotNull('g.polyindex')
                ->where(new Comparison(new StGeometryType('g.polyindex'), '<>', Value::of('POINT')))
                ->where(new StIntersects('g.polyindex', new StGeomFromText(Value::of($reachWkt), self::SRID)))
                ->when(
                    $this->reachableGateEnabled() && !empty($reachableGroupIds),
                    fn ($q) => $q->whereIn('g.id', array_map('intval', $reachableGroupIds))
                )
                ->whereNotExists(fn ($q) => $q->from('messages_groups as mg')
                    ->where('mg.msgid', $msgid)
                    ->whereColumn('mg.groupid', 'g.id'))
                // Suppress re-rippling only when the poster's MOST RECENT Group/Joined log
                // for this group is a ripple-join (text='Rippled') AND they then LEFT it -
                // i.e. the membership they last opted out of was a rippled one. Most recent
                // join wins: the whereNotExists on lj2 makes lj the latest Joined, so a later
                // manual/ordinary join (then leave) means they treated it as a normal group
                // and rippling is NOT blocked; ll.id > lj.id requires the leave to follow
                // that ripple-join. Sites B/C (addPosterMembershipToRippledGroups,
                // pullRippledPostsFromLeftGroups) apply the identical rule.
                ->whereNotExists(fn ($q) => $q->from('logs as lj')
                    ->where('lj.user', $msg->fromuser)
                    ->whereColumn('lj.groupid', 'g.id')
                    ->where('lj.type', 'Group')
                    ->where('lj.subtype', 'Joined')
                    ->where('lj.text', 'Rippled')
                    ->whereNotExists(fn ($q2) => $q2->from('logs as lj2')
                        ->whereColumn('lj2.user', 'lj.user')
                        ->whereColumn('lj2.groupid', 'lj.groupid')
                        ->where('lj2.type', 'Group')
                        ->where('lj2.subtype', 'Joined')
                        ->whereColumn('lj2.id', '>', 'lj.id'))
                    ->whereExists(fn ($q2) => $q2->from('logs as ll')
                        ->whereColumn('ll.user', 'lj.user')
                        ->whereColumn('ll.groupid', 'lj.groupid')
                        ->where('ll.type', 'Group')
                        ->where('ll.subtype', 'Left')
                        ->whereColumn('ll.id', '>', 'lj.id')))
                // A ban is an explicit mod ejection: it withdraws the poster's live posts
                // and (modern ban) deletes their membership while recording a users_banned
                // row. Never ripple a poster's post into a group they are banned from - that
                // would silently re-insert the post (and, via addPosterMembershipToRippledGroups,
                // re-join the banned poster). Cover both representations: the users_banned
                // table (authoritative, no expiry) and a legacy collection='Banned' membership.
                ->whereNotExists(fn ($q) => $q->from('users_banned as ub')
                    ->where('ub.userid', $msg->fromuser)
                    ->whereColumn('ub.groupid', 'g.id'))
                ->whereNotExists(fn ($q) => $q->from('memberships as mb')
                    ->where('mb.userid', $msg->fromuser)
                    ->whereColumn('mb.groupid', 'g.id')
                    ->where('mb.collection', 'Banned'))
                ->get();

            $n = 0;
            foreach ($targetGroups as $g) {
                $inserted = DB::table('messages_groups')->insertOrIgnore([[
                    'msgid' => $msgid,
                    'groupid' => $g->id,
                    'collection' => $collection,
                    'approvedat' => $immediateApprove ? now() : null,
                    'arrival' => now(),
                    'autoreposts' => 0,
                    'msgtype' => $msg->type,
                    'rippled_in' => 1,
                ]]);
                $n += $inserted;
            }
            if ($n > 0) {
                $stats['rippled_in'] += $n;
                // §15/§16 instrumentation: count groups a post was rippled into.
                try {
                    // Atomic-counter upsert without raw SQL, matching the pattern already used
                    // in UnifiedDigestService: increment() is itself atomic (UPDATE ... SET
                    // count = count + n), so an existing row is safe; a missing row falls through
                    // to an INSERT, and if a concurrent writer wins that race the duplicate-key
                    // error is caught and the increment retried. No count is lost. NOT upsert()
                    // (which emits count = values(count) and would REPLACE the counter) and NOT
                    // incrementOrCreate() (firstOrCreate + a separate increment, i.e. read-then-
                    // write, which can drop counts between the two statements).
                    $today = now()->toDateString();
                    $updated = DB::table('rippling_event_metrics')
                        ->where('day', $today)
                        ->where('event', 'rippled_in')
                        ->increment('count', $n);

                    if ($updated === 0) {
                        try {
                            DB::table('rippling_event_metrics')->insert([
                                'day' => $today,
                                'event' => 'rippled_in',
                                'count' => $n,
                            ]);
                        } catch (\Throwable) {
                            DB::table('rippling_event_metrics')
                                ->where('day', $today)
                                ->where('event', 'rippled_in')
                                ->increment('count', $n);
                        }
                    }
                } catch (\Throwable $e) {
                    // best-effort; never affect the expander
                }
            }

            // The poster becomes a member of every group their post has rippled into, exactly
            // as if they had posted there directly. Backfills any rippled-in group they're not
            // yet on (idempotent), so it also catches posts rippled before this existed.
            $this->addPosterMembershipToRippledGroups($msgid, $stats);
        } catch (\Throwable $e) {
            $stats['errors']++;
            Log::warning("ripple: ripple-into-groups failed for msg {$msgid}: {$e->getMessage()}");
        }
    }

    /**
     * Best-effort: computes and stores the "quicker to get to" moderator note for a freshly
     * rippled-in (msgid,groupid) pair. Silently no-ops (leaves the columns NULL) when the
     * routing/KNN calls fail, the group is unreachable within the routing horizon, or
     * quicker is false — a missing note simply means the notice line is not shown. Never
     * throws: a failure here must never break the expander or the caller's insert loop.
     */
    /**
     * Add the poster as a member of every group their post has rippled into (role Member,
     * collection Approved), marked rippled=1. Email settings come from the poster's home/origin
     * group membership, except immediate (-1) is downgraded to daily (24) so an unrequested
     * membership never starts a flood of immediate mail (a no-email 0 or daily 24 home setting is
     * preserved). Existing memberships - including a Banned row - are left untouched (INSERT IGNORE
     * + NOT EXISTS), and a group whose most recent join was a ripple-join the poster then LEFT is
     * never re-joined ("most recent join wins"; an ordinary last membership they left does not block
     * rippling).
     * Writes a memberships_history row (rippled=1) so abuse detection still runs while the per-group
     * welcome is suppressed, and sends one bundled intro email per post. Best-effort: never breaks
     * the expander.
     */
    private function addPosterMembershipToRippledGroups(int $msgid, array &$stats): void
    {
        try {
            $msg = DB::table('messages')->where('id', $msgid)->first(['fromuser']);
            $posterId = $msg->fromuser ?? null;
            if (!$posterId) {
                return;
            }

            // Email settings = the poster's settings on their home group: the earliest-arrival
            // group on this message where they're already a member. Fall back to the same
            // defaults addMembership uses if (unexpectedly) no such membership exists.
            $home = DB::table('messages_groups as mg')
                ->select('m.emailfrequency', 'm.eventsallowed', 'm.volunteeringallowed')
                // The userid predicate belongs in the ON clause, not the WHERE:
                // it scopes the JOIN, so a message_groups row with no matching
                // membership contributes nothing rather than being filtered out
                // afterwards. Same rows either way for an inner join, but the
                // golden compares the clause it sits in.
                ->join('memberships as m', function ($j) use ($posterId) {
                    $j->on('m.groupid', '=', 'mg.groupid')
                      ->where('m.userid', $posterId);
                })
                ->where('mg.msgid', $msgid)
                ->orderBy('mg.arrival')
                ->limit(1)
                ->first();
            // Email frequency: preserve the poster's home-group setting, but DOWNGRADE ONLY
            // immediate (-1) to daily (24). A rippled-into group is a lower-priority, unrequested
            // membership, so we never start a flood of immediate emails from it - but we also never
            // silently start emailing a no-email (0) member, nor change a daily (24) member. Events
            // and volunteering are copied verbatim: they are one-email-per-user roundups with their
            // own cadence guard, so leaving them at the home setting adds no extra emails.
            $homeFreq = $home->emailfrequency ?? 24;
            $emailfrequency = ((int) $homeFreq === -1) ? 24 : $homeFreq;
            $eventsallowed = $home->eventsallowed ?? 1;
            $volunteeringallowed = $home->volunteeringallowed ?? 1;

            // Groups this post has rippled into where the poster has no membership row yet AND
            // which the poster has not "rippled in then left". Only a group whose MOST RECENT
            // Group/Joined log is a ripple-join (text='Rippled') that the poster then LEFT is a
            // durable "do not ripple me back here" signal ("most recent join wins"); a group they
            // last joined manually/ordinarily and then left must NOT block rippling. (The post
            // itself is also pulled from such groups by pullRippledPostsFromLeftGroups; here we only
            // gate the membership.)
            $targets = DB::table('messages_groups as mg')
                ->select('mg.groupid')
                ->where('mg.msgid', $msgid)
                ->where('mg.rippled_in', 1)
                // Not already a member.
                ->whereNotExists(fn ($q) => $q->from('memberships as m')
                    ->where('m.userid', $posterId)
                    ->whereColumn('m.groupid', 'mg.groupid'))
                // Not previously rippled-in and then left. The inner pair is
                // what makes this mean "the LATEST Joined log for this group
                // was a ripple, AND they have since Left": the NOT EXISTS on
                // lj2 pins lj to the most recent Joined, and the EXISTS on ll
                // requires a later Leave. Flattening either one changes the
                // question from "did they leave after we last auto-joined
                // them" to something much weaker.
                ->whereNotExists(fn ($q) => $q->from('logs as lj')
                    ->where('lj.user', $posterId)
                    ->whereColumn('lj.groupid', 'mg.groupid')
                    ->where('lj.type', 'Group')
                    ->where('lj.subtype', 'Joined')
                    ->where('lj.text', 'Rippled')
                    ->whereNotExists(fn ($q2) => $q2->from('logs as lj2')
                        ->whereColumn('lj2.user', 'lj.user')
                        ->whereColumn('lj2.groupid', 'lj.groupid')
                        ->where('lj2.type', 'Group')
                        ->where('lj2.subtype', 'Joined')
                        ->whereColumn('lj2.id', '>', 'lj.id'))
                    ->whereExists(fn ($q2) => $q2->from('logs as ll')
                        ->whereColumn('ll.user', 'lj.user')
                        ->whereColumn('ll.groupid', 'lj.groupid')
                        ->where('ll.type', 'Group')
                        ->where('ll.subtype', 'Left')
                        ->whereColumn('ll.id', '>', 'lj.id')))
                // Never re-join a poster to a group they are banned from.
                ->whereNotExists(fn ($q) => $q->from('users_banned as ub')
                    ->where('ub.userid', $posterId)
                    ->whereColumn('ub.groupid', 'mg.groupid'))
                ->get()
                ->all();

            $addedThisCall = 0;
            foreach ($targets as $t) {
                $added = DB::table('memberships')->insertOrIgnore([[
                    'userid' => $posterId,
                    'groupid' => $t->groupid,
                    'role' => 'Member',
                    'collection' => 'Approved',
                    'emailfrequency' => $emailfrequency,
                    'eventsallowed' => $eventsallowed,
                    'volunteeringallowed' => $volunteeringallowed,
                    'rippled' => 1,
                    'added' => now(),
                ]]);
                if ($added > 0) {
                    $addedThisCall++;
                    $stats['memberships_added'] = ($stats['memberships_added'] ?? 0) + 1;
                    // memberships_history with rippled=1: abuse detection still runs (processingrequired=1),
                    // but MembershipsProcessingService reads rippled to SUPPRESS the per-group welcome -
                    // a single bundled intro email (RippleIntroMail) is sent below instead.
                    DB::table('memberships_history')->insert([
                        'userid' => $posterId,
                        'groupid' => $t->groupid,
                        'collection' => 'Approved',
                        'processingrequired' => 1,
                        'rippled' => 1,
                    ]);
                    // Log the join with a rippling-specific reason. V1 addMembership logs a
                    // Group/Joined entry whose text is 'Manual' (clicked join) or 'Auto'; we use
                    // 'Rippled' so the modlog - and MembershipsProcessingService, which reads
                    // Group/Joined logs - can tell a rippled-in auto-join apart from those (and the
                    // 'seen on many groups' spam check excludes text='Rippled').
                    // byuser is NULL: the system joined them off their post rippling in, no actor.
                    DB::table('logs')->insert([
                        'timestamp' => now(),
                        'type' => 'Group',
                        'subtype' => 'Joined',
                        'user' => $posterId,
                        'byuser' => null,
                        'groupid' => $t->groupid,
                        'text' => 'Rippled',
                    ]);
                }
            }

            // One bundled intro email per post, the first time the poster is actually auto-joined
            // anywhere off this post. Explains what happened, the email defaults we applied, and how
            // to change them. Replaces the per-group welcome storm (suppressed above).
            if ($addedThisCall > 0) {
                $this->maybeSendRippleIntro($posterId, $msgid);
            }
        } catch (\Throwable $e) {
            Log::warning("ripple: add-poster-membership failed for msg {$msgid}: {$e->getMessage()}");
        }
    }

    /**
     * Send the bundled "your post is reaching nearby communities" intro email at most once per
     * post. Claims the send atomically via rippling_reach.ripple_intro_sent (0 -> 1) so it fires
     * exactly once no matter how many ticks/groups the post ripples into. Best-effort: a spool
     * failure never breaks the expander (the spooler has its own durable retry).
     */
    private function maybeSendRippleIntro(int $posterId, int $msgid): void
    {
        // Atomic claim: only the run that flips 0 -> 1 gets to send. No row (e.g. backfill path)
        // => nothing to claim here => no send (the backfill command sends those).
        $claimed = DB::table('rippling_reach')
            ->where('msgid', $msgid)
            ->where('ripple_intro_sent', 0)
            ->update(['ripple_intro_sent' => 1]);
        if ($claimed < 1) {
            return;
        }

        try {
            $user = \App\Models\User::find($posterId);
            if (!$user || !$user->email_preferred) {
                return;
            }
            $message = \App\Models\Message::find($msgid);

            // Each rippled-into community's own welcome text (groups.welcomemail), so the one
            // bundled intro carries what each community wanted to say - instead of a separate
            // per-group welcome email (which MembershipsProcessingService suppresses for rippled
            // joins). Limited to the rippled groups the poster is now a member of that have a
            // welcome configured and are live here.
            $welcomeGroups = DB::table('messages_groups as mg')
                ->select([
                    new Alias(new Coalesce('g.namefull', 'g.nameshort'), 'name'),
                    'g.welcomemail as welcome',
                ])
                ->join('groups as g', 'g.id', '=', 'mg.groupid')
                ->join('memberships as m', function ($j) use ($posterId) {
                    $j->on('m.groupid', '=', 'g.id')->where('m.userid', $posterId);
                })
                ->where('mg.msgid', $msgid)
                ->where('mg.rippled_in', 1)
                ->where('m.rippled', 1)
                ->where('g.onhere', 1)
                ->whereNotNull('g.welcomemail')
                ->where('g.welcomemail', '<>', '')
                ->orderBy('mg.arrival')
                ->get()
                ->map(static fn ($r) => ['name' => $r->name, 'welcome' => $r->welcome])
                ->all();

            app(\App\Services\EmailSpoolerService::class)
                ->spool(new \App\Mail\Ripple\RippleIntroMail($user, $message, $welcomeGroups));
        } catch (\Throwable $e) {
            Log::warning("ripple: intro email failed for msg {$msgid}: {$e->getMessage()}");
        }
    }

    /**
     * Leaving a group the post was RIPPLED into also pulls the POST from that group (the product
     * decision: leaving a group you were rippled into means "I want nothing to do with this group",
     * not just "stop my membership"). Soft-deletes (deleted=1) every rippled-in messages_groups row
     * whose author's MOST RECENT Group/Joined log for that group is a ripple-join (text='Rippled')
     * that they then LEFT (a later Group/Left log) - "most recent join wins". An author whose last
     * join was ordinary, or who manually rejoined after a rippled leave, is left alone (matching
     * sites A/B) - so a fresh ripple into a group they once normally-left is not immediately pulled
     * back out. Audits each removal with a Message/Deleted log. Idempotent (only touches deleted=0
     * rows); the membership re-join and any future re-ripple are blocked by the same
     * most-recent-join-wins rule. Best-effort: never breaks the run.
     */
    private function pullRippledPostsFromLeftGroups(bool $dryRun, array &$stats, ?int $onlyMsgid = null): void
    {
        try {
            // Scope: only --msgid restricts this (controlled single-post testing). Deliberately NOT
            // area-scoped - a poster who left a rippled-into group must have their post pulled even
            // after the post's origin group leaves the trial (its origin then falls outside the
            // current area), otherwise the copy is stranded in a group they explicitly opted out of.
            // Drive from RECENT Group/Left events, not from every rippled copy. The old
            // shape scanned all rippled_in=1 rows (10k+ and growing daily) and ran the
            // nested logs subquery per row — O(all rippled copies ever), which crept past
            // 80s and hung every tick once the experiment had rippled enough. Leaves are
            // the trigger and are rare, so we start from the (index-supported, via
            // logs.timestamp_2) recent Left logs and only touch a rippled copy when its
            // poster actually left that group. Cost is now bounded by leave volume, not by
            // the rippled-copy population. This per-tick run only needs to cover leaves
            // since the last successful run (seconds ago); a 2-day window is a generous
            // safety margin covering brief stalls while keeping the scan fast (~3s vs the
            // unbounded original's 80s+, which hung every tick). Idempotent — a copy
            // already pulled (deleted=1) is simply skipped.
            $cutoff = now()->subDays(2);

            $rows = DB::table('logs as ll')
                ->select(['mg.msgid', 'mg.groupid', 'm.fromuser'])
                ->distinct()
                ->join('messages_groups as mg', function ($j) {
                    $j->on('mg.groupid', '=', 'll.groupid')
                      ->where('mg.rippled_in', 1)
                      ->where('mg.deleted', 0);
                })
                ->join('messages as m', function ($j) {
                    $j->on('m.id', '=', 'mg.msgid')
                      ->on('m.fromuser', '=', 'll.user');
                })
                ->where('ll.type', 'Group')
                ->where('ll.subtype', 'Left')
                ->where('ll.timestamp', '>=', $cutoff)
                ->when($onlyMsgid !== null, fn ($q) => $q->where('mg.msgid', $onlyMsgid))
                ->whereExists(function ($q) {
                    $q->from('logs as lj')
                      ->whereColumn('lj.user', 'll.user')
                      ->whereColumn('lj.groupid', 'll.groupid')
                      ->where('lj.type', 'Group')
                      ->where('lj.subtype', 'Joined')
                      ->where('lj.text', 'Rippled')
                      ->whereColumn('lj.id', '<', 'll.id')
                      ->whereNotExists(function ($q2) {
                          $q2->from('logs as lj2')
                             ->whereColumn('lj2.user', 'lj.user')
                             ->whereColumn('lj2.groupid', 'lj.groupid')
                             ->where('lj2.type', 'Group')
                             ->where('lj2.subtype', 'Joined')
                             ->whereColumn('lj2.id', '>', 'lj.id');
                      });
                })
                ->get();

            if ($rows->isEmpty()) {
                return;
            }

            if ($dryRun) {
                $stats['pulled_on_leave'] += $rows->count();
                return;
            }

            foreach ($rows as $r) {
                $n = DB::table('messages_groups')
                    ->where('msgid', $r->msgid)
                    ->where('groupid', $r->groupid)
                    ->where('rippled_in', 1)
                    ->where('deleted', 0)
                    ->update(['deleted' => 1]);
                if ($n > 0) {
                    DB::table('logs')->insert([
                        'timestamp' => now(),
                        'type' => 'Message',
                        'subtype' => 'Deleted',
                        'user' => $r->fromuser,
                        'byuser' => null,
                        'groupid' => $r->groupid,
                        'msgid' => $r->msgid,
                        'text' => 'Rippling: removed on leave',
                    ]);
                    $stats['pulled_on_leave']++;
                }
            }
        } catch (\Throwable $e) {
            $stats['errors']++;
            Log::warning("ripple: pull-on-leave failed: {$e->getMessage()}");
        }
    }

    /**
     * Union the isochrone WKT with the origin group's area when the isochrone already
     * covers >= 90% of that area, so the stored reach polygon fills in the whole group
     * rather than leaving a thin uncovered sliver at the edge.
     *
     * The "origin group" is the earliest-arrival group for the post (the group the post
     * was originally submitted to). Its area is groups.polyindex (COALESCE(poly, polyofficial)),
     * skipping the (0,0) point sentinel.
     *
     * If anything goes wrong (bad geometry, routing query failure, etc.) the method
     * returns the original WKT unchanged — it must never throw.
     */
    private function unionWithOriginGroupArea(int $msgid, string $wkt): string
    {
        try {
            $groupWkt = $this->originGroupWkt($msgid);
            if ($groupWkt === null) {
                return $wkt;
            }

            // ST_Intersection of two polygons that touch along a line or at a
            // point yields a GEOMETRYCOLLECTION, and ST_Area on that throws
            // error 3516. CaseWhen evaluates lazily, so guarding on the geometry
            // type means ST_Area only ever sees polygonal input; a NULL frac
            // simply fails the >= 0.90 test below and the WKT passes through
            // unchanged - the same outcome the exception path produced, minus
            // the exception.
            $result = $this->intersectionFraction(
                new StGeomFromText(Value::of($wkt), self::SRID),
                new StGeomFromText(Value::of($groupWkt), self::SRID)
            );

            if ($result !== null && ($result->frac ?? 0) >= 0.90 && !empty($result->u)) {
                return $result->u;
            }

            return $wkt;
        } catch (\Throwable $e) {
            // Retry once with ST_Buffer(geom, 0) geometry repair to handle invalid polygons.
            try {
                $groupWkt = $this->originGroupWkt($msgid);
                if ($groupWkt === null) {
                    return $wkt;
                }

                $result = $this->intersectionFraction(
                    new StBuffer(new StGeomFromText(Value::of($wkt), self::SRID), 0),
                    new StBuffer(new StGeomFromText(Value::of($groupWkt), self::SRID), 0)
                );

                if ($result !== null && ($result->frac ?? 0) >= 0.90 && !empty($result->u)) {
                    return $result->u;
                }
            } catch (\Throwable $e2) {
                Log::warning("ripple: unionWithOriginGroupArea retry failed for msg {$msgid}: {$e2->getMessage()}");
            }

            return $wkt;
        }
    }

    /**
     * The origin group's own polygon WKT for a post: the earliest-arrival group on
     * this message with a real (non-degenerate, non-POINT) polyindex - the group the
     * post was originally submitted to. Null if none qualifies.
     */
    private function originGroupWkt(int $msgid): ?string
    {
        $groupRow = DB::table('messages_groups as mg')
            ->select(new Alias(new StAsText('g.polyindex'), 'group_wkt'))
            ->join('groups as g', 'g.id', '=', 'mg.groupid')
            ->where('mg.msgid', $msgid)
            ->where('mg.deleted', 0)
            ->whereNotNull('g.polyindex')
            ->where(new Comparison(new StGeometryType('g.polyindex'), '<>', Value::of('POINT')))
            ->orderBy('mg.arrival')
            ->limit(1)
            ->first();

        return ($groupRow !== null && !empty($groupRow->group_wkt)) ? $groupRow->group_wkt : null;
    }

    /**
     * The overlap fraction (intersection area / $grp area) and the WKT union of two
     * geometries, via a two-level from-less derived table: the innermost SELECT just
     * carries $iso/$grp as named columns so ST_Intersection only has to be computed
     * once and is reused by both the CASE/ST_Area fraction and the ST_Union.
     */
    private function intersectionFraction(mixed $iso, mixed $grp): ?object
    {
        $inner = DB::query()->select([new Alias($iso, 'iso'), new Alias($grp, 'grp')]);
        $mid = DB::query()->fromSub($inner, 's')
            ->select([new Alias(new StIntersection('iso', 'grp'), 'inter'), 'iso', 'grp']);

        return DB::query()->fromSub($mid, 't')
            ->select([
                new Alias(
                    (new CaseWhen())
                        ->when(new In(new StGeometryType('inter'), [Value::of('POLYGON'), Value::of('MULTIPOLYGON')]))
                        ->then(new Arithmetic(new StArea('inter'), '/', new NullIf(new StArea('grp'), 0))),
                    'frac'
                ),
                new Alias(new StAsText(new StUnion('iso', 'grp')), 'u'),
            ])
            ->first();
    }

    /**
     * Advance a post where polygon and bounds cannot share one UPDATE. MySQL
     * error 1713 is about the UNDO record, which holds the OLD values of every
     * updated column - and polygon + outer_bound are both SPATIAL-indexed, so
     * their old geometries are logged in full. Together they can exceed the
     * 16KB undo page even when each alone fits (the posts stuck since late
     * July: old polygon ~7KB + old outer_bound ~19KB; verified each column
     * updates fine alone, and simplifying the NEW polygon - the first fix -
     * cannot touch old-value size at all). Split so each statement carries
     * only one spatial column. The bounds lag the polygon by one statement,
     * which is acceptable for a post that otherwise never advances; if the
     * bounds statement still fails, keep the fresh polygon with stale bounds
     * rather than failing the whole advance.
     */
    private function advanceSplitForUndoLog(string $wkt, callable $advanceValues, int $msgid): void
    {
        // The advance UPDATE without the bounds columns.
        DB::table('rippling_reach')->where('msgid', $msgid)->update($advanceValues($wkt));

        $boundsSet = $this->boundsSetSql($wkt);

        if ($boundsSet === []) {
            return;
        }

        try {
            try {
                DB::table('rippling_reach')->where('msgid', $msgid)
                    ->update(['updated_at' => now()] + $boundsSet);
            } catch (\Throwable $e) {
                DB::table('rippling_reach')->where('msgid', $msgid)
                    ->update(['updated_at' => now()] + $this->boundsEnvelopeSql($wkt));
            }
        } catch (\Throwable $e) {
            Log::warning('ripple: split advance stored polygon but bounds update failed', [
                'msgid' => $msgid,
                'error' => substr($e->getMessage(), 0, 200),
            ]);

            return;
        }

        Log::info('ripple: advance split to fit undo log', ['msgid' => $msgid]);
    }

    /**
     * True when the failure is MySQL error 1713, "Undo log record is too big" -
     * the row image for the polygon update cannot fit an undo record. Checked
     * via the driver's structured errorInfo where available; the message
     * fallback matches the specific 1713 phrase, which cannot collide with
     * polygon coordinate digits the way a bare error-number match would.
     */
    private function isUndoLogTooBig(\Throwable $e): bool
    {
        for ($t = $e; $t !== null; $t = $t->getPrevious()) {
            if ($t instanceof \PDOException && isset($t->errorInfo[1]) && (int) $t->errorInfo[1] === 1713) {
                return true;
            }
        }

        return str_contains(strtolower($e->getMessage()), 'undo log record is too big');
    }

    /**
     * One Douglas-Peucker simplification pass over a polygon WKT, with a
     * ST_Buffer(geom, 0) repair (plain ST_Simplify can emit self-intersecting
     * output). Returns the simplified WKT only when it is still polygonal and
     * actually smaller; null tells the caller to try a coarser tolerance.
     */
    private function simplifyPolygonWkt(string $wkt, float $tolerance): ?string
    {
        try {
            // A pure spatial-function computation with no table behind it - a from-less
            // SELECT (no FROM clause emitted) renders and executes fine.
            $row = DB::query()
                ->select(new Alias(
                    new StAsText(new StBuffer(new StSimplify(
                        new StGeomFromText(Value::of($wkt), self::SRID), $tolerance
                    ), 0)),
                    'w'
                ))
                ->first();
            $out = $row->w ?? null;

            if ($out !== null
                && (str_starts_with($out, 'POLYGON') || str_starts_with($out, 'MULTIPOLYGON'))
                && strlen($out) < strlen($wkt)) {
                return $out;
            }
        } catch (\Throwable $e) {
            // Fall through - the caller tries the next tolerance.
        }

        return null;
    }

    /**
     * Run $store($wkt); if MySQL rejects it with error 1713 (undo log record
     * too big - seen once reach polygons grow into the multi-megabyte range,
     * which left posts permanently stuck: every expand run retried the same
     * oversized UPDATE and failed), progressively simplify the polygon and
     * retry, so the post stores a slightly coarser reach instead of never
     * advancing. Returns the WKT actually stored, which the caller must use
     * for anything downstream (group targeting reads the stored polygon).
     * Non-1713 failures propagate unchanged, as does 1713 if even the
     * coarsest simplification cannot fit.
     */
    private function storeWithUndoLogShrink(callable $store, string $wkt, int $msgid): string
    {
        try {
            $store($wkt);

            return $wkt;
        } catch (\Throwable $e) {
            if (!$this->isUndoLogTooBig($e)) {
                throw $e;
            }
        }

        // The geometry is tagged SRID 3857 but its coordinates are lon/lat
        // DEGREES (a site-wide quirk - see the routing/spatial services, which
        // carry the same mislabel). Tolerances must therefore be degree-scale:
        // at UK latitudes 0.0003 is roughly 25m, 0.002 roughly 160m, 0.01
        // roughly 800m. A metre-scale ladder (40/160/1000) exceeds the whole
        // polygon's extent, and MySQL's ST_Simplify just returns NULL for
        // every rung - which is how the first version of this fix silently
        // never simplified anything.
        foreach ([0.0003, 0.002, 0.01] as $tolerance) {
            $shrunk = $this->simplifyPolygonWkt($wkt, $tolerance);

            if ($shrunk === null) {
                continue;
            }

            try {
                $store($shrunk);

                Log::info('ripple: polygon simplified to fit undo log', [
                    'msgid' => $msgid,
                    'tolerance' => $tolerance,
                    'bytes_before' => strlen($wkt),
                    'bytes_after' => strlen($shrunk),
                ]);

                return $shrunk;
            } catch (\Throwable $e) {
                if (!$this->isUndoLogTooBig($e)) {
                    throw $e;
                }
            }
        }

        // Every rung either failed to simplify or still would not fit. Say so
        // before rethrowing - the first version of this ladder failed silently
        // on every rung and looked identical to never having run.
        Log::warning('ripple: undo-log shrink exhausted all tolerances', [
            'msgid' => $msgid,
            'bytes' => strlen($wkt),
        ]);

        throw $e;
    }

    /**
     * The cached schedule entry for a target tick: the one with the largest `tick`
     * number ≤ target (so a higher tick whose polygon was filtered out falls back to
     * the most-grown reach available), or the first entry if none qualify. Indexing by
     * tick number — not array position — survives filtered/empty-polygon ticks.
     *
     * @param array<int,array{tick:int,wkt:string}> $ticks
     */
    /**
     * Backfill: re-derive EVERY active post's stored reach under the current
     * algorithm - fine no-smoothing polygon, slim schedule with per-tick
     * reachable_group_ids - and retract what the new targeting no longer covers:
     * rippled-in copies, and the ripple-created memberships that existed only for
     * them (via retractOutOfReachCopies' existing rules: latest-join-was-Rippled,
     * no other live post, held reaches untouched).
     *
     * Mirrors recomputeReach's safety properties: updated_at is preserved so the
     * reach mailer never reconsiders the row, writes are one row per statement
     * (Galera-safe), and a routing failure skips the row for a later run rather
     * than degrading it. Idempotent: a second run finds nothing left to change.
     * The budget used is the post's CURRENT tick re-read from a fresh schedule,
     * so a post mid-expansion keeps its place in the hazard timetable.
     *
     * Resumable and shardable: by default only rows the new algorithm has not yet
     * touched are candidates - reachable_group_ids IS NULL, which is exactly the
     * pre-gate population (init/advance now populate it) - so each run continues
     * where the last stopped and a drained run finds nothing. $all overrides that
     * for a full re-sweep (e.g. after a later algorithm change). $shardCount/$shardIndex
     * partition candidates by msgid % shardCount so disjoint shards run in parallel;
     * total routing load ~= shards, so keep it within the routing server's headroom.
     *
     * @return array{candidates:int,updated:int,skipped:int,would_retract_groups:int,pulled_out_of_reach:int,memberships_removed:int}
     */
    public function backfillReach(
        bool $dryRun = false,
        int $limit = 500,
        ?int $onlyMsgid = null,
        ?int $shardCount = null,
        ?int $shardIndex = null,
        bool $all = false
    ): array {
        $stats = [
            'candidates' => 0, 'updated' => 0, 'skipped' => 0,
            'would_retract_groups' => 0, 'pulled_out_of_reach' => 0,
            'memberships_removed' => 0, 'pulled_on_removal' => 0, 'skipped_terminal' => 0,
        ];

        $rows = DB::table('rippling_reach')
            ->select(['msgid', 'lat', 'lng', 'tick', 'rejected_groups', 'status'])
            ->whereIn('status', ['expanding', 'stopped', 'done']) // held = frozen for moderation
            ->when(!$all, fn ($q) => $q->whereNull('reachable_group_ids'))
            ->when($shardCount !== null && $shardCount > 1,
                fn ($q) => $q->where(new Arithmetic('msgid', '%', $shardCount), '=', (int) $shardIndex))
            ->when($onlyMsgid !== null, fn ($q) => $q->where('msgid', $onlyMsgid))
            ->orderBy('msgid')
            ->limit($limit)
            ->get();

        foreach ($rows as $row) {
            $stats['candidates']++;
            try {
                $schedule = $this->reach->computeSchedule((float) $row->lat, (float) $row->lng);
                if ($schedule === null || empty($schedule['ticks'])) {
                    $stats['skipped']++;
                    continue; // routing unreachable/off-graph - safe to retry later
                }
                $ticks = $schedule['ticks'];
                $tick = min(max((int) $row->tick, 1), count($ticks));
                $entry = $this->entryForTick($ticks, $tick);
                $tickGeom = $this->resolveTickGeometry($entry, (float) $row->lat, (float) $row->lng);
                if ($tickGeom === null) {
                    $stats['skipped']++;
                    continue;
                }
                $tickWkt = $tickGeom['wkt'];
                $ids = $this->tickReachableIds($entry, $schedule);

                if ($dryRun) {
                    // Preview the retraction the new ids would drive (ids-based only;
                    // the tighter polygon can retract further copies on the live run).
                    if (!empty($ids)) {
                        $stats['would_retract_groups'] += DB::table('messages_groups')
                            ->where('msgid', $row->msgid)
                            ->where('rippled_in', 1)
                            ->where('deleted', 0)
                            ->whereNotIn('groupid', $ids)
                            ->count();
                    }
                    $stats['updated']++;
                    continue;
                }

                $storeWkt = $this->unionWithOriginGroupArea((int) $row->msgid, $tickWkt);
                // Polygon + derived bounds in ONE statement; envelope retry on throw.
                // updated_at omitted - see ReachBoundsService::sync()'s docblock for why
                // the raw form's self-assignment was always a no-op.
                $backfillValues = [
                    'polygon' => new StGeomFromText(Value::of($storeWkt), self::SRID),
                    'schedule' => json_encode($ticks),
                    'reachable_group_ids' => json_encode($ids),
                    'total_freeglers' => (int) $schedule['total_freeglers'],
                    'max_drive_min' => $schedule['max_drive_min'],
                ];
                $boundsSet = $this->boundsSetSql($storeWkt);
                try {
                    DB::table('rippling_reach')->where('msgid', $row->msgid)->update($backfillValues + $boundsSet);
                } catch (\Throwable $e) {
                    if ($boundsSet === []) {
                        throw $e;
                    }
                    DB::table('rippling_reach')->where('msgid', $row->msgid)
                        ->update($backfillValues + $this->boundsEnvelopeSql($storeWkt));
                }
                // Secondary "out of area" rejection clips must survive the rewrite
                // (the clip statement shrinks polygon and NULLs inner_bound atomically).
                $this->reapplyClips((int) $row->msgid, $row->rejected_groups ?? null);
                // Routing-provided bounds upgrade the columns after the clips.
                if ($tickGeom['outer'] !== null) {
                    $this->bounds->sync((int) $row->msgid, $tickGeom['outer'], $tickGeom['inner']);
                }
                // Retract copies (and their ripple-only memberships) the new reach no
                // longer covers - polygon-based always, ids-based when the gate is on.
                $this->retractOutOfReachCopies((int) $row->msgid, false, $stats);
                $stats['updated']++;
            } catch (\Throwable $e) {
                Log::warning("ripple: backfillReach failed for msg {$row->msgid}: {$e->getMessage()}");
                $stats['skipped']++;
            }
        }

        return $stats;
    }

    /**
     * The geometry for a schedule tick: ['wkt' => string, 'outer' => ?string,
     * 'inner' => ?string]. Full schedules (old servers / rows stored before the slim
     * form) carry the polygon inline with no bounds; slim schedules (polygons=0) fetch
     * a point-form catchment at the tick's drive-time, which also ships the routing
     * server's sandwich bounds (ReachService::catchmentGeometry). Null when the entry
     * is unusable or the routing server is unreachable - callers skip the row and
     * retry on a later run.
     */
    private function resolveTickGeometry(?array $entry, float $lat, float $lng): ?array
    {
        if ($entry === null) {
            return null;
        }
        if (!empty($entry['wkt'])) {
            return ['wkt' => (string) $entry['wkt'], 'outer' => null, 'inner' => null];
        }
        $driveMin = (float) ($entry['drive_min'] ?? 0);
        if ($driveMin <= 0) {
            return null;
        }
        return $this->reach->catchmentGeometry($lat, $lng, $driveMin);
    }

    /**
     * The reachable-group ids to store for the row's CURRENT tick: the per-tick set
     * when the schedule carries one (slim form), else the schedule's max-extent set
     * (older servers). Stored on rippling_reach so retraction tests copies against
     * the current extent.
     *
     * @return int[]
     */
    private function tickReachableIds(?array $entry, array $schedule): array
    {
        return $this->tickReachableIdsOrNull($entry, $schedule) ?? [];
    }

    /** As tickReachableIds, but null when neither source has a set (gate unavailable). */
    private function tickReachableIdsOrNull(?array $entry, array $schedule): ?array
    {
        if (is_array($entry['reachable_group_ids'] ?? null)) {
            return array_map('intval', $entry['reachable_group_ids']);
        }
        $top = $schedule['reachable_group_ids'] ?? null;
        return is_array($top) ? array_map('intval', $top) : null;
    }

    /** The per-tick ids as JSON for a COALESCE update, or null to keep the stored set. */
    private function tickReachableIdsJson(?array $entry): ?string
    {
        return is_array($entry['reachable_group_ids'] ?? null)
            ? json_encode(array_map('intval', $entry['reachable_group_ids']))
            : null;
    }

    private function entryForTick(array $ticks, int $target): ?array
    {
        $best = null;
        foreach ($ticks as $entry) {
            if ((int) ($entry['tick'] ?? 0) <= $target) {
                $best = $entry;
            }
        }

        return $best ?? ($ticks[0] ?? null);
    }

    /**
     * Re-subtract every secondary-group rejection from a post's reach after the polygon has
     * been rewritten from the cached schedule (which is clip-unaware). Mirrors the Go
     * ClipReachForRejectedGroup partial-clip; never deletes the row (advanceDue must not
     * resurrect it via initialiseNew), so a fully-clipped post keeps its last partial reach.
     *
     * @param string|null $rejectedGroupsJson JSON array of rejected group ids, or null.
     */
    private function reapplyClips(int $msgid, ?string $rejectedGroupsJson): void
    {
        if ($rejectedGroupsJson === null) {
            return;
        }
        $gids = json_decode($rejectedGroupsJson, true);
        if (!is_array($gids) || empty($gids)) {
            return;
        }
        // The polygon SHRINKS here: a stale inner bound could cheap-accept viewers in
        // the clipped-out area, so it is NULLed in the SAME statement. The outer bound
        // is left stale-loose (safe — the MBR/exact tests still decide correctly).
        $ready = $this->bounds->ready();
        foreach ($gids as $gid) {
            $update = ['mr.polygon' => new StDifference('mr.polygon', 'g.polyindex')];
            if ($ready) {
                $update['mr.inner_bound'] = null;
            }

            DB::table('rippling_reach as mr')
                ->join('groups as g', function ($j) use ($gid) {
                    $j->where('g.id', (int) $gid);
                })
                ->where('mr.msgid', $msgid)
                ->whereNotNull('g.polyindex')
                ->where(new Comparison(new StGeometryType('g.polyindex'), '<>', Value::of('POINT')))
                ->where(new StIntersects('mr.polygon', 'g.polyindex'))
                ->whereNot(new StWithin('mr.polygon', 'g.polyindex'))
                ->update($update);
        }
    }

    /**
     * Blur a poster's origin by ~400m (BLUR_USER) before it drives the reach polygon, so the
     * reach is no more precise than the location Freegle exposes elsewhere. Same algorithm and
     * geodesic engine (App\Support\GreatCircle) as the legacy V1 PHP Utils::blur / Go utils.Blur:
     * a deterministic, location-derived direction (so the reach doesn't jitter across recomputes)
     * and a final 4-dp round.
     *
     * @return array{0:float,1:float} [lat, lng]
     */
    private function blurOrigin(float $lat, float $lng): array
    {
        // Guard against invalid stored coordinates so GreatCircle can't yield NaN.
        if ($lat > 90 || $lat < -90 || $lng > 180 || $lng < -180) {
            $lat = 53.945;  // centre of Britain (Dunsop Bridge), as utils.Blur falls back to
            $lng = -2.5209;
        }

        $dir = ($lat * 1000 + $lng * 1000) % 360;            // deterministic per location (V1 parity)
        $pos = GreatCircle::getPositionByDistance(self::BLUR_USER, $dir, $lat, $lng);

        return [round($pos['lat'], 4), round($pos['lng'], 4)];
    }

    private function inActiveHours(): bool
    {
        $hour = (int) now()->format('G');
        $start = (int) config('freegle.ripple.active_start_hour', 6);
        $end = (int) config('freegle.ripple.active_end_hour', 23);

        return $hour >= $start && $hour < $end;
    }

    /**
     * #9 observability: one structured line per expansion event. Rolled up by the
     * later metrics job; for now it makes the engine's behaviour visible in Loki.
     */
    /**
     * Distinct-replier count for a post: distinct users with an Interested chat reply
     * (chat_messages.refmsgid = msgid). Drives the reply-saturation stop (extent-governor T1.1).
     */
    private function distinctReplierCount(int $msgid): int
    {
        return (int) DB::table('chat_messages')
            ->where('refmsgid', $msgid)
            ->where('type', 'Interested')
            ->distinct()
            ->count('userid');
    }

    /**
     * Whether a post has a TERMINAL outcome (Taken/Received/Withdrawn) and so has left the
     * browsable set and must not ripple any further. 'Repost' is deliberately NOT terminal -
     * a reposted item is still active. Checked against messages_outcomes (the source of truth)
     * rather than messages_spatial, which the messages:update-spatial-index cron lags behind, so
     * a just-taken post can still appear spatial for a tick or two after the outcome is recorded.
     */
    private function hasTerminalOutcome(int $msgid): bool
    {
        // ->exists() rather than selecting a literal and testing for null:
        // the raw form's "SELECT 1 ... LIMIT 1" is the hand-rolled version of
        // exactly this question, and a literal in a select list can only be
        // expressed through DB::raw, which is a raw site itself.
        return DB::table('messages_outcomes')
            ->where('msgid', $msgid)
            ->whereIn('outcome', [
                \App\Models\MessageOutcome::OUTCOME_TAKEN,
                \App\Models\MessageOutcome::OUTCOME_RECEIVED,
                \App\Models\MessageOutcome::OUTCOME_WITHDRAWN,
            ])
            ->exists();
    }

    private function logEvent(int|string $msgid, string $kind, int $tick, array $entry): void
    {
        Log::info('ripple:reach', [
            'msgid' => (int) $msgid,
            'kind' => $kind,
            'tick' => $tick,
            'drive_min' => $entry['drive_min'] ?? null,
            'cumulative_users' => $entry['cumulative_users'] ?? null,
        ]);
    }
}
