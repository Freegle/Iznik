<?php

namespace App\Services\Ripple;

use App\Services\MessageSpatialService;
use App\Support\GreatCircle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

    /** Compact cell-set form of the reach polygon (plans/2026-08-24-rippling-reach-raster-storage.md). */
    private CellSetService $cellSets;

    public function __construct(
        private ReachService $reach,
        ?ReachBoundsService $bounds = null,
        ?DensityService $density = null,
        ?GroupRippleOptOut $optOut = null,
        ?RippleReplyService $replies = null,
        ?CellSetService $cellSets = null
    ) {
        $this->bounds = $bounds ?? new ReachBoundsService();
        $this->density = $density ?? new DensityService();
        $this->optOut = $optOut ?? new GroupRippleOptOut();
        $this->replies = $replies ?? new RippleReplyService(new ReachQueryService());
        $this->cellSets = $cellSets ?? new CellSetService();
    }

    /**
     * SQL fragment (leading " AND ...") excluding the communities that have opted out of
     * the given rippling direction, or '' when none have. Every id is a DB int, so the
     * inline list cannot inject — same shape as the reachable-gate clause below.
     */
    private function optOutClause(string $column, string $direction): string
    {
        $ids = $this->optOut->excludedGroupIds($direction);
        if (empty($ids)) {
            return '';
        }

        return ' AND ' . $column . ' NOT IN (' . implode(',', $ids) . ')';
    }

    /**
     * The overflow rings in compact cell-set form for storage
     * (plans/2026-08-24-rippling-reach-raster-storage.md), or null when no
     * lane applied or nothing could be rasterised. Mirrors overflowJson's
     * "one place, so every write path encodes identically" discipline - and
     * the same warning the overflow_bounds migration left for anyone adding
     * a column here: write EVERY path or the column is worthless.
     *
     * Same nesting and same JSON paths as overflow_bounds, each ring's WKT
     * replaced by base64 cell bytes; the non-geometry members
     * (fairness_budget_min, bbox) are deliberately not mirrored - they are
     * scalars read from overflow_bounds, and copying them would be two
     * places to drift.
     *
     * A reused schedule carries its predecessor's cells across verbatim
     * (initialiseNew's reuse read), so a reuse costs no rasterise calls at
     * all. Best-effort throughout: a lane that will not rasterise is simply
     * absent, and spatial-go falls back to parsing that lane's WKT.
     */
    private function overflowCellsJson(?array $schedule): ?string
    {
        $carried = $schedule['overflow_cells'] ?? null;
        if (is_array($carried) && ! empty($carried)) {
            return json_encode($carried);
        }

        $bounds = $schedule['overflow_bounds'] ?? null;
        if (! is_array($bounds) || empty($bounds)) {
            return null;
        }

        // The scalars (fairness_budget_min, bbox) ride along: this document
        // is their only home - the reuse guard needs fairness_budget_min and
        // the digest's bbox prefilter needs bbox. Rows written before the
        // legacy drop lack them and degrade safely (reuse recomputes; the
        // digest widens its prefilter).
        $out = [];
        foreach ($bounds as $lane => $rings) {
            if (! is_array($rings)) {
                $out[(string) $lane] = $rings; // fairness_budget_min, a scalar
                continue;
            }
            if ($lane === 'bbox') {
                $out['bbox'] = $rings; // four floats, not a lane
                continue;
            }
            $converted = [];
            foreach ($rings as $band => $wkt) {
                if (! is_string($wkt) || $wkt === '') {
                    continue;
                }
                $cells = $this->cellSets->rasterize($wkt);
                if ($cells !== null) {
                    $converted[(string) $band] = base64_encode($cells);
                }
            }
            if (! empty($converted)) {
                $out[(string) $lane] = $converted;
            }
        }

        return empty($out) ? null : json_encode($out);
    }

    /**
     * SET-clause fragment (+ its params) deriving the sandwich bounds from the SAME
     * polygon WKT being written, so polygon and bounds land in ONE statement — no
     * timing window in which a new polygon has stale bounds.
     *
     * @return array{0:string,1:array<int,string>}
     */
    private function boundsSetSql(string $storeWkt): array
    {
        $poly = 'ST_GeomFromText(?, ' . self::SRID . ')';

        return [
            ', outer_bound = ' . ReachBoundsService::outerExpr($poly)
            . ', inner_bound = ' . ReachBoundsService::innerExpr($poly),
            [$storeWkt, $storeWkt],
        ];
    }

    /**
     * As boundsSetSql, but the envelope fallback for polygons whose derivation THROWS
     * (~94% of production polygons are technically invalid): the MBR still finds the
     * row, the exact polygon decides. Never a degenerate POINT for an open post — that
     * would prune it from the browse R-tree.
     *
     * @return array{0:string,1:array<int,string>}
     */
    private function boundsEnvelopeSql(string $storeWkt): array
    {
        return [
            ', outer_bound = ST_Envelope(ST_GeomFromText(?, ' . self::SRID . ')), inner_bound = NULL',
            [$storeWkt],
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

        $q = DB::table('rippling_reach')
            // Current footprint, for the crosspost-breadth stat: the stored
            // grid, counted via the spatial server's groups-intersecting
            // answer. Absent (retired rows) the stat skips the before-count.
            ->select(['msgid', 'lat', 'lng', 'tick', 'total_freeglers', 'rejected_groups', 'status', 'max_minutes_cap', 'polygon_cells'])
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
            $tickGeom = $this->resolveTickGeometry($entry, (float) $row->lat, (float) $row->lng, (int) $row->msgid);
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
            if (($row->polygon_cells ?? null) !== null) {
                $stats['groups_before'] += $this->countCrosspostGroupsFromCells($row->polygon_cells);
            }
            $stats['groups_after'] += $this->countCrosspostGroups($storeWkt);

            if ($dryRun) {
                $stats['shrunk']++;
                continue;
            }

            // `updated_at = updated_at` preserves the timestamp (suppresses the ON
            // UPDATE auto-bump) so the reach mailer never reconsiders this row.
            // Polygon + derived bounds in ONE statement; envelope retry on throw.
            [$boundsSet, $boundsParams] = $this->boundsSetSql($storeWkt);
            // recomputeReach genuinely RE-DERIVES the schedule, so the overflow rings change
            // with it and have to be rewritten here. (advanceDue does not: it only moves the
            // tick pointer along an already-stored schedule, and the rings belong to the reach
            // as a whole rather than to a tick, so there is nothing for it to update.)
            // The rings' cell-set form rides the SAME statement as the reach
            // grid, so the two can never describe different shapes. The grid
            // is the stored reach, bound as a plain parameter, and a failed
            // rasterise SKIPS the row (this pass SHRINKS - writing a row
            // whose new, smaller reach nobody can read would admit people
            // the cap just excluded).
            $ovCellsSet = ', overflow_cells = ?';
            if ($this->gridRetired((int) $row->msgid)) {
                // Labels + union threshold answer everything the grid did;
                // stop re-materialising it (NULL drains the blob) and skip
                // the rasterise round trip. The spatial index removes the
                // row and containment is served from the stored label.
                $cells = null;
            } else {
                $cells = $this->cellSets->rasterize($storeWkt);
                if ($cells === null) {
                    $stats['skipped']++;
                    continue;
                }
            }
            // Anchored on updated_at so the SET clause is never empty.
            $gridSet = ', polygon_cells = ?';
            $shrinkSql = fn (string $set): string => 'UPDATE rippling_reach
                    SET updated_at = updated_at' . $gridSet . $set . ',
                        schedule = ?, reachable_group_ids = ?, total_freeglers = ?, max_drive_min = ?'
                        . $ovCellsSet . '
                  WHERE msgid = ?';
            $shrinkTail = [
                json_encode($ticks),
                json_encode($this->tickReachableIds($entry, $schedule)),
                (int) $schedule['total_freeglers'],
                $schedule['max_drive_min'],
                $this->overflowCellsJson($schedule),
                $row->msgid,
            ];
            $gridLead = [$cells];
            try {
                // keep-raw: UPDATE with derived-bounds SQL expressions in SET - the builder cannot render these
                DB::statement($shrinkSql($boundsSet), array_merge($gridLead, $boundsParams, $shrinkTail));
            } catch (\Throwable $e) {
                [$envSet, $envParams] = $this->boundsEnvelopeSql($storeWkt);
                // keep-raw: envelope-fallback variant of the same spatial UPDATE
                DB::statement($shrinkSql($envSet), array_merge($gridLead, $envParams, $shrinkTail));
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
    /**
     * countCrosspostGroups for a stored grid: which groups the reach touches,
     * asked of the spatial server, then narrowed by the SAME predicate the SQL
     * form applies.
     *
     * The narrowing is the point. The spatial server's groups index selects on
     * publish=1 AND listable=1 only (dataset_groups.go), while the SQL form
     * additionally requires type='Freegle' and onhere=1, excludes
     * playground-named groups, excludes POINT-geometry sentinels and honours
     * the ripple-in opt-out. Counting the raw answer would make
     * groups_before and groups_after in ripple:recompute-reach's output two
     * different populations, so the audience-cap effectiveness figure an
     * operator reads would be measuring the wrong difference.
     *
     * @param string $cells encoded cell set
     */
    private function countCrosspostGroupsFromCells(string $cells): int
    {
        $touching = $this->cellSets->groupsIntersecting($cells);
        if ($touching === null || $touching === []) {
            return 0;
        }
        $ids = array_map(static fn ($g) => (int) $g['id'], $touching);

        // keep-raw: the opt-out clause is an SQL fragment built by
        // GroupRippleOptOut, and ST_GeometryType is a spatial predicate - the
        // builder can render neither.
        $row = DB::selectOne(
            "SELECT COUNT(*) AS c
             FROM `groups` g
             WHERE g.id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")
               AND g.publish = 1
               AND g.type = 'Freegle'
               AND g.onhere = 1
               AND g.nameshort NOT LIKE '%playground%'
               AND g.polyindex IS NOT NULL
               AND ST_GeometryType(g.polyindex) <> 'POINT'"
            . $this->optOutClause('g.id', GroupRippleOptOut::DIRECTION_IN),
            $ids
        );

        return (int) ($row->c ?? 0);
    }

    private function countCrosspostGroups(string $wkt): int
    {
        if ($wkt === '') {
            return 0;
        }
        // keep-raw: ST_Intersects/ST_GeometryType against a WKT literal - spatial predicates the builder cannot render
        $row = DB::selectOne(
            "SELECT COUNT(*) AS c
             FROM `groups` g
             WHERE g.publish = 1
               AND g.type = 'Freegle'
               AND g.onhere = 1
               AND g.nameshort NOT LIKE '%playground%'
               AND g.polyindex IS NOT NULL
               AND ST_GeometryType(g.polyindex) <> 'POINT'
               AND ST_Intersects(g.polyindex, ST_GeomFromText(?, " . self::SRID . "))"
            . $this->optOutClause('g.id', GroupRippleOptOut::DIRECTION_IN),
            [$wkt]
        );
        return (int) ($row->c ?? 0);
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
            $scopeSql = '';
            $params = [];
            if ($onlyMsgid !== null) {
                $scopeSql = ' AND mr.msgid = ?';
                $params[] = $onlyMsgid;
            }

            $stale = DB::select(
                'SELECT mr.msgid AS msgid
                 FROM rippling_reach mr
                 LEFT JOIN messages_spatial ms ON ms.msgid = mr.msgid
                 WHERE ms.msgid IS NULL AND mr.status <> \'held\'' . $scopeSql,
                $params
            );

            $absent = array_map(static fn ($r) => (int) $r->msgid, $stale);

            // Absent from messages_spatial does not mean gone. The index job can be
            // down, or die between its delete and add passes - and historically its age
            // pass deleted ~3,000 still-qualifying posts at the end of every run off
            // their dead memberships' arrivals (retracted-copy tombstones; fixed in
            // removeOldMessages alongside this check). Treating each absence as "the
            // post has gone" deleted the reach row, retracted the post's copies from
            // every group it had rippled into (leaving MORE tombstones, feeding the
            // loop), and then initialiseNew built the whole thing again from scratch -
            // routing searches and a large polygon write to the cluster's write node,
            // per post. On production that was about 85% of all initialisation work:
            // 11,656 initialisations in one day against 1,635 genuinely new posts, with
            // 8,802 reach rows dropped.
            //
            // So rather than trust the index, ask the tables it is built from whether each
            // of these posts is supposed to be in it. A post that no longer qualifies has
            // really gone and is removed now; one that still qualifies is left alone.
            $msgids = $this->confirmGenuinelyGone($absent);

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
                DB::table('rippling_reach')->where('msgid', $msgid)->delete();
                $stats['removed']++;
            }
        } catch (\Throwable $e) {
            $stats['errors']++;
            Log::warning("ripple: remove-stale-and-retract failed: {$e->getMessage()}");
        }
    }

    /**
     * Of the reach rows whose post is missing from the spatial index, which posts have
     * genuinely gone?
     *
     * Asks the tables the index is built from, rather than waiting to see whether the
     * absence sticks. That is an exact answer instead of a guess, it needs nothing
     * remembered between runs, and a post that really has been withdrawn stops rippling
     * straight away instead of a quarter of an hour later.
     *
     * @param  int[]  $absent
     * @return int[]
     */
    private function confirmGenuinelyGone(array $absent): array
    {
        if (empty($absent)) {
            return [];
        }

        $alive = array_flip(MessageSpatialService::stillQualifyForIndex($absent));

        $gone = [];
        foreach ($absent as $msgid) {
            if (!isset($alive[$msgid])) {
                $gone[] = $msgid;
            }
        }

        if ($blips = count($absent) - count($gone)) {
            Log::info('ripple: posts missing from the spatial index but still live, left alone', [
                'blips' => $blips,
                'gone' => count($gone),
            ]);
        }

        return $gone;
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
     * deliberately Pending for per-group moderation. Freezing is one-way: nothing clears
     * 'held' (FreezeReachIfOriginPending in iznik-server-go's microvolunteering package is
     * the only writer, and freezes precisely so that re-approving a copy cannot re-reach and
     * re-notify), so a frozen post stays outside this pass for the rest of its life.
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
            $scopeSql = '';
            $params = ['Approved'];
            if ($onlyMsgid !== null) {
                $scopeSql = ' AND mr.msgid = ?';
                $params[] = $onlyMsgid;
            }

            $orphaned = DB::select(
                'SELECT DISTINCT mr.msgid AS msgid
                   FROM rippling_reach mr
                   JOIN messages_groups mg
                     ON mg.msgid = mr.msgid AND mg.rippled_in = 1 AND mg.deleted = 0
                  WHERE mr.status <> \'held\' AND NOT EXISTS (
                          SELECT 1 FROM messages_groups o
                           WHERE o.msgid = mr.msgid AND o.rippled_in = 0
                             AND o.deleted = 0 AND o.collection = ?
                        )' . $scopeSql,
                $params
            );
            if (empty($orphaned)) {
                return;
            }
            $msgids = array_map(static fn ($r) => (int) $r->msgid, $orphaned);

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
        // "Which groups does the reach still intersect" is answered by the
        // spatial server on the same lattice the reach is stored on
        // (CellSetService::groupsIntersecting). Failure retracts NOTHING -
        // over-coverage for one more pass is visible and self-heals;
        // retracting on a guess is not.
        $rows = $this->outOfReachRippledGroupsFromCells($msgid);
        if ($rows === null) {
            $stats['retract_check_unavailable'] = ($stats['retract_check_unavailable'] ?? 0) + 1;

            return 0;
        }

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
     * The cells-only form of retractOutOfReachCopies' candidate query: the
     * post's rippled-in groups that its current grid no longer intersects
     * (plus, with the reachable gate on, groups outside the node-reachable
     * set - the same JSON rule as the SQL form, applied here in PHP). Returns
     * null when the question cannot be answered (no cells, spatial down), and
     * the caller retracts nothing.
     *
     * @return array<int,object>|null rows shaped {id: groupid}
     */
    private function outOfReachRippledGroupsFromCells(int $msgid): ?array
    {
        $cols = ['polygon_cells', 'reachable_group_ids'];
        $rr = DB::table('rippling_reach')
            ->where('msgid', $msgid)
            ->where('status', '<>', 'held')
            ->first($cols);
        if ($rr === null) {
            return [];
        }

        // The cells arm: which groups the current grid still intersects. A
        // RETIRED row has no grid, so only the reachable-ids arm below can
        // retract for it - the ids ARE part of the reach record and need no
        // geometry. With neither arm answerable, retract nothing.
        $stillCovered = null;
        if (($rr->polygon_cells ?? null) !== null) {
            $intersecting = $this->cellSets->groupsIntersecting($rr->polygon_cells);
            if ($intersecting !== null) {
                $stillCovered = [];
                foreach ($intersecting as $g) {
                    $stillCovered[(int) $g['id']] = true;
                }
            }
        }

        $reachable = null;
        if ($this->reachableGateEnabled() && is_string($rr->reachable_group_ids)) {
            $decoded = json_decode($rr->reachable_group_ids, true);
            // Same rule as the SQL form: an EMPTY set means "gate could not
            // compute" and must never retract anything.
            if (is_array($decoded) && $decoded !== []) {
                $reachable = array_fill_keys(array_map('intval', $decoded), true);
            }
        }

        $rippled = DB::table('messages_groups as mg')
            ->join('groups as g', 'g.id', '=', 'mg.groupid')
            ->where('mg.msgid', $msgid)
            ->where('mg.rippled_in', 1)
            ->where('mg.deleted', 0)
            ->whereNotNull('g.polyindex')
            ->pluck('g.id');

        if ($stillCovered === null && $reachable === null) {
            // Neither arm can answer (no grid and no usable ids set): the
            // caller retracts nothing rather than guessing.
            return null;
        }

        $rows = [];
        foreach ($rippled as $gid) {
            $gid = (int) $gid;
            $gone = ($stillCovered !== null && !isset($stillCovered[$gid]))
                || ($reachable !== null && !isset($reachable[$gid]));
            if ($gone) {
                $rows[] = (object) ['id' => $gid];
            }
        }

        return $rows;
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
        $n = DB::affectingStatement(
            'UPDATE messages_groups SET deleted = 1
             WHERE msgid = ? AND groupid = ? AND rippled_in = 1 AND deleted = 0',
            [$msgid, $groupid]
        );
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
        $cutoffSql = '';
        $satSql = '';
        $params = [];
        $scopeSql = '';
        if ($onlyMsgid !== null) {
            // A single chosen post (controlled test) targets its msgid directly and bypasses the
            // arrival cutoff AND the reply-saturation stop — the chosen post may predate go-live or
            // already be saturated, and selecting nothing would be a surprising no-op for an
            // explicit one-post request.
            $scopeSql = ' AND ms.msgid = ?';
            $params[] = $onlyMsgid;
        } else {
            // An area scope is an ADDITIONAL filter on top of normal behaviour: the go-live arrival
            // cutoff still applies, so an area run ripples only the recent (post-cutoff) posts inside
            // the polygon rather than the whole historical backlog there.
            if ($withinPolyWkt !== null) {
                $scopeSql = ' AND ST_Contains(ST_GeomFromText(?, ' . self::SRID . '), ms.point)';
                $params[] = $withinPolyWkt;
            }
            if (!empty($enabledAt)) {
                $cutoffSql = ' AND ms.arrival >= ?';
                $params[] = $enabledAt;
            }
            // Reply-saturation stop (extent-governor T1.1): a post that already has >= threshold
            // distinct repliers never starts rippling - it has enough interest without reach.
            // 0 disables. Applies to normal and scoped (experiment) runs alike.
            $satStop = (int) config('freegle.ripple.reply_saturation_stop', 5);
            if ($satStop > 0) {
                $satSql = " AND (SELECT COUNT(DISTINCT cm.userid) FROM chat_messages cm
                                  WHERE cm.refmsgid = ms.msgid AND cm.type = 'Interested') < ?";
                $params[] = $satStop;
            }
        }
        $params[] = $limit;

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
        $optOutSql = empty($outOptOut)
            ? ''
            : ' AND (ms.groupid IS NULL OR ms.groupid NOT IN (' . implode(',', $outOptOut) . '))';

        // Candidate source: live posts with NO reach row yet (anti-join).
        // keep-raw: ANY_VALUE + the ST_X/ST_Y spatial accessors on a GROUP BY the builder cannot render
        $rows = DB::select(
            'SELECT ms.msgid AS msgid,
                    ANY_VALUE(ST_Y(ms.point)) AS lat,
                    ANY_VALUE(ST_X(ms.point)) AS lng,
                    MIN(ms.arrival) AS arrival
             FROM messages_spatial ms
             LEFT JOIN rippling_reach mr ON mr.msgid = ms.msgid
             WHERE mr.msgid IS NULL' . $scopeSql . $cutoffSql . $satSql . $optOutSql . '
             GROUP BY ms.msgid
             LIMIT ?',
            $params
        );

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
            // The rings' cell-set form must be carried across a reuse too, so
            // a reused row costs NO rasterise calls: it inherits the cells
            // that were built for the row it is copied from. Rebuilding the
            // reused schedule from only ticks/total/max_drive - which is what
            // happened before - leaves the column NULL on every reused row,
            // and reuse is commonest exactly where posts cluster. That is the
            // mechanism that left density_band NULL on ~89% of rows.
            // keep-raw: row-constructor `(lat, lng) IN ((?,?),(?,?)...)` - the builder cannot render a tuple IN
            $existing = DB::select(
                'SELECT lat, lng, schedule, total_freeglers, max_drive_min, max_minutes_cap, overflow_cells
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
                $storedCap = $e->max_minutes_cap === null ? null : (float) $e->max_minutes_cap;
                if ($storedCap === null || abs($storedCap - $ceiling) > 0.001) {
                    continue;
                }
                $ticks = json_decode($e->schedule, true);
                if (!is_array($ticks) || empty($ticks)) {
                    continue;
                }
                $reusedOverflowCells = ! empty($e->overflow_cells)
                    ? json_decode($e->overflow_cells, true)
                    : null;

                // A fairness ring computed under a DIFFERENT weight is not this post's ring,
                // however co-located the two posts are - the same argument as the reach-budget
                // guard above. Recompute rather than inherit a stretch nobody asked for.
                // The budget scalar lives in the cells document (see overflowCellsJson); a
                // pre-drop cells doc has no scalar, so a cells-only row with a fairness ring
                // and no recorded budget is recomputed rather than trusted.
                if (is_array($reusedOverflowCells) && isset($reusedOverflowCells['fairness'])) {
                    $storedBudget = isset($reusedOverflowCells['fairness_budget_min'])
                        ? (float) $reusedOverflowCells['fairness_budget_min']
                        : null;
                    $wantBudget = $this->reach->fairnessBudgetMinutes($ceiling);
                    if ($storedBudget === null || $wantBudget === null
                        || abs($storedBudget - $wantBudget) > 0.001) {
                        continue;
                    }
                }

                $scheduleByKey[$k] = [
                    'ticks' => $ticks,
                    'total_freeglers' => (int) $e->total_freeglers,
                    'max_drive_min' => (float) $e->max_drive_min,
                    'overflow_cells' => is_array($reusedOverflowCells) ? $reusedOverflowCells : null,
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
                    $tickGeom = $this->resolveTickGeometry($entry, (float) $lat, (float) $lng, (int) $row->msgid);
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
                    // The rings' cell-set form rides the SAME statement as the
                    // reach grid, so the two can never describe different
                    // shapes. The grid is the ONLY stored reach, carried as a
                    // plain bind in the same statement as the derived bounds.
                    // All the spatial algebra still happens - union, bounds
                    // derivation - but on the SCRATCH WKT parameter, which is
                    // never stored.
                    $overflowCellsJson = $this->overflowCellsJson($schedule);
                    $poly = 'ST_GeomFromText(?, ' . self::SRID . ')';
                    $initSql = function (string $outerExpr, string $innerExpr): string {
                        return 'INSERT INTO rippling_reach
                           (msgid, lat, lng, polygon_cells, outer_bound, inner_bound, arrival, mode, tick, total_ticks,
                            total_freeglers, max_drive_min, schedule, reachable_group_ids,
                            next_expansion_at, status, density_band, density_radius_miles, max_minutes_cap,
                            overflow_cells, created_at, updated_at)
                         VALUES (?, ?, ?, ?, ' . $outerExpr . ', ' . $innerExpr . ', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                         ON DUPLICATE KEY UPDATE
                            lat = VALUES(lat), lng = VALUES(lng), polygon_cells = VALUES(polygon_cells),
                            outer_bound = VALUES(outer_bound), inner_bound = VALUES(inner_bound),
                            arrival = VALUES(arrival), mode = VALUES(mode), tick = VALUES(tick),
                            total_ticks = VALUES(total_ticks), total_freeglers = VALUES(total_freeglers),
                            max_drive_min = VALUES(max_drive_min), schedule = VALUES(schedule),
                            reachable_group_ids = VALUES(reachable_group_ids),
                            next_expansion_at = VALUES(next_expansion_at), status = VALUES(status),
                            density_band = VALUES(density_band),
                            density_radius_miles = VALUES(density_radius_miles),
                            max_minutes_cap = VALUES(max_minutes_cap),
                            overflow_cells = VALUES(overflow_cells),
                            updated_at = NOW()';
                    };
                    $initTail = [
                        $arrival, $this->reach->mode(), $tick, $total,
                        $schedule['total_freeglers'], $schedule['max_drive_min'],
                        json_encode($schedule['ticks']),
                        json_encode($this->tickReachableIds($entry, $schedule)),
                        $next, $status,
                        $cap['band'], $cap['radius_miles'], $ceiling,
                        $overflowCellsJson,
                    ];
                    $initStore = function (string $wkt) use ($initSql, $initTail, $row, $lat, $lng, $poly): void {
                        // The grid is the only stored reach at birth (the label
                        // lands moments later), so a failed rasterise must FAIL
                        // this store (the post keeps its previous state and is
                        // retried next sweep) - it can never write a row whose
                        // reach nobody can read.
                        $cells = $this->cellSets->rasterize($wkt);
                        if ($cells === null) {
                            throw new \RuntimeException('rasterise failed; reach store left for the next pass');
                        }
                        $head = [$row->msgid, $lat, $lng, $cells];
                        try {
                            // keep-raw: upsert with ST_GeomFromText/derived-bounds SQL expressions in the column list - the builder cannot render these
                            DB::statement(
                                $initSql(ReachBoundsService::outerExpr($poly), ReachBoundsService::innerExpr($poly)),
                                array_merge($head, [$wkt, $wkt], $initTail)
                            );
                        } catch (\Throwable $e) {
                            // keep-raw: envelope-fallback variant of the same spatial upsert
                            DB::statement(
                                $initSql('ST_Envelope(' . $poly . ')', 'NULL'),
                                array_merge($head, [$wkt], $initTail)
                            );
                        }
                    };
                    $initStore($storeWkt);
                    // Reach-engine labels: computed once here at the maximum budget,
                    // never recomputed as the reach grows. Best-effort (readers fall
                    // back to the stored cells; the backfill command retries).
                    $this->reach->storeReachLabels(
                        (int) $row->msgid, $lat, $lng,
                        (float) ($schedule['max_drive_min'] ?? 0)
                    );
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
        // Named columns, NOT select * : this table's rows average ~600KB
        // (polygon) with schedule JSON, max_polygon and the sandwich bounds on
        // top, so an unqualified fetch of a 500-row due batch materialises
        // gigabytes in one fetchAll. That was the 21-28 memory-exhaustion
        // fatals a day from 2026-08-12 (argv-attributed to ripple:expand):
        // the density resize + re-init churn made due batches big enough to
        // blow the 1GB limit. schedule - the one big column each advance
        // genuinely needs - is fetched per row inside the loop, so at most one
        // row's schedule is in memory at a time. This list must cover every
        // $row-> use to the END of this function - rejected_groups is read
        // ~60 lines down for the secondary-reject clip, and leaving it out
        // silently skipped the clip (caught by the two clip tests in CI).
        $rows = DB::table('rippling_reach')
            ->select(['msgid', 'lat', 'lng', 'tick', 'min_tick', 'total_ticks', 'arrival', 'rejected_groups'])
            ->addSelect(DB::raw('reach_labels IS NOT NULL AS has_labels'))
            ->addSelect('origin_union_secs')
            ->where('status', 'expanding')
            ->whereNotNull('next_expansion_at')
            ->where('next_expansion_at', '<=', now())
            ->when($onlyMsgid !== null, fn ($q) => $q->where('msgid', $onlyMsgid))
            // keep-raw: ST_Contains/ST_GeomFromText are spatial functions the builder cannot render
            ->when($withinPolyWkt !== null, fn ($q) => $q->whereRaw(
                'ST_Contains(ST_GeomFromText(?, ' . self::SRID . '), ST_SRID(POINT(lng, lat), ' . self::SRID . '))',
                [$withinPolyWkt]
            ))
            ->limit($limit)
            ->get();

        foreach ($rows as $row) {
            try {
                $schedule = DB::table('rippling_reach')->where('msgid', $row->msgid)->value('schedule');
                $ticks = json_decode($schedule, true);
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
                    $tickGeom = $this->resolveTickGeometry($entry, (float) $row->lat, (float) $row->lng, (int) $row->msgid);
                    if ($tickGeom === null) {
                        // Routing unreachable - keep the previous polygon and retry this
                        // tick on the next run (next_expansion_at is already due).
                        $stats['skipped']++;
                        continue;
                    }
                    $tickWkt = $tickGeom['wkt'];
                    // Retired rows (label + union threshold stored) do not
                    // re-derive the >=90% coverage test geometrically every
                    // tick - the stored threshold IS that answer. The union
                    // itself still applies to the scratch WKT where active,
                    // because the group-crossing test and the bounds below
                    // still consume it.
                    $retired = $this->rowRetired($row);
                    $storeWkt = $retired
                        ? $this->unionByThreshold((int) $row->msgid, $tickWkt, (float) ($entry['drive_min'] ?? 0) * 60, $row->origin_union_secs ?? null)
                        : $this->unionWithOriginGroupArea((int) $row->msgid, $tickWkt);
                    // Grid + derived bounds in ONE statement (no stale-bounds
                    // window); envelope retry if the derivation throws on
                    // pathological geometry. The stored reach is the grid,
                    // bound as a plain parameter; the WKT is scratch for the
                    // derived bounds only. The old undo-log split/shrink
                    // machinery is gone with the polygons - a ~23KB grid plus
                    // ~19KB bounds cannot approach the 16KB-per-column undo
                    // page problem megabyte polygons had.
                    $gridSet = ', polygon_cells = ?';
                    $advanceSql = fn (string $set): string => 'UPDATE rippling_reach
                         SET updated_at = NOW()' . $gridSet . $set . ',
                             reachable_group_ids = COALESCE(?, reachable_group_ids),
                             tick = ?, next_expansion_at = ?, status = ?
                         WHERE msgid = ?';
                    $advanceTail = [$this->tickReachableIdsJson($entry), $target, $next, $status, $row->msgid];
                    $advanceStore = function (string $wkt) use ($advanceSql, $advanceTail, $retired): void {
                        if ($retired) {
                            // Labels + union threshold answer everything the
                            // grid did; drain it and skip the rasterise.
                            $cells = null;
                        } else {
                            // A failed rasterise fails the advance: the post
                            // keeps its previous reach and is retried next
                            // sweep. Never write a reach nobody can read.
                            $cells = $this->cellSets->rasterize($wkt);
                            if ($cells === null) {
                                throw new \RuntimeException('rasterise failed; advance left for the next pass');
                            }
                        }
                        $lead = [$cells];
                        [$boundsSet, $boundsParams] = $this->boundsSetSql($wkt);
                        try {
                            // keep-raw: UPDATE with derived-bounds SQL expressions in SET - the builder cannot render these
                            DB::statement($advanceSql($boundsSet), array_merge($lead, $boundsParams, $advanceTail));
                        } catch (\Throwable $e) {
                            [$envSet, $envParams] = $this->boundsEnvelopeSql($wkt);
                            // keep-raw: envelope-fallback variant of the same spatial UPDATE
                            DB::statement($advanceSql($envSet), array_merge($lead, $envParams, $advanceTail));
                        }
                    };
                    $advanceStore($storeWkt);
                    if (!$retired) {
                        // The polygon was just overwritten from the cached schedule, which does NOT
                        // include any secondary-group rejection clips. Re-subtract every rejected
                        // group so a secondary "out of area" rejection survives expansion (#9).
                        // (The clip statement shrinks polygon and NULLs inner_bound atomically.)
                        // Retired rows skip this: there is no grid to clip, and the
                        // rejection is enforced by the label evaluator (rejected_groups).
                        $this->reapplyClips((int) $row->msgid, $row->rejected_groups ?? null);
                    }
                    // Routing-provided bounds (tighter than the derived ones) upgrade the
                    // columns AFTER the clips, verified against the FINAL stored polygon.
                    // Retired rows take the direct write: the verify/fallback dance reads
                    // the grid this row no longer has, and every probe of it would waste
                    // three round trips to conclude nothing (adversarial review 2026-08-28).
                    if ($tickGeom['outer'] !== null) {
                        $this->bounds->sync((int) $row->msgid, $tickGeom['outer'], $tickGeom['inner'], $retired);
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

            // A TrashNothing item cross-posted to several groups is one message, so it
            // ripples like any other. Copies predating that are still in the database and
            // would each ripple on their own account, reaching people once per copy, so a
            // message sharing its post id with another live one sits out until
            // tn:merge-crossposts has collapsed the set. Self-limiting: once a set is
            // merged there is nothing to match and this never fires again.
            $sharesTnPostId = DB::table('messages')
                ->join('messages as other', function ($join) {
                    $join->on('other.tnpostid', '=', 'messages.tnpostid')
                        ->whereColumn('other.id', '!=', 'messages.id')
                        ->whereNull('other.deleted');
                })
                ->where('messages.id', $msgid)
                ->whereNotNull('messages.tnpostid')
                ->where('messages.tnpostid', '!=', '')
                ->exists();

            if ($sharesTnPostId) {
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
            // cheap spatial-index prefilter; this is an AND gate on top. IDs are
            // server-sourced int64s, cast via (int) so they can't inject. Empty set or gate
            // off => clause omitted => unchanged behaviour (fall back to polygon only).
            $reachableGate = ($this->reachableGateEnabled() && !empty($reachableGroupIds))
                ? ' AND g.id IN (' . implode(',', array_map('intval', $reachableGroupIds)) . ')'
                : '';

            // Ripple-IN opt-out (groups.settings.rippling.in): never crosspost into a community
            // that has switched rippling off. The `%playground%` name test below predates this
            // and stays as belt-and-braces for a playground community created before anyone gives
            // it the setting; the setting is the deliberate, per-community mechanism (set by
            // ripple:opt-out), and the only one that also covers ripple-OUT (see initialiseNew).
            $inOptOut = $this->optOutClause('g.id', GroupRippleOptOut::DIRECTION_IN);

            // keep-raw: ST_Intersects/ST_GeometryType plus the correlated logs/ban NOT EXISTS arms the builder cannot render
            $targetGroups = DB::select(
                "SELECT g.id
                 FROM `groups` g
                 WHERE g.publish = 1
                   AND g.type = 'Freegle'
                   AND g.onhere = 1
                   AND g.nameshort NOT LIKE '%playground%'" . $inOptOut . "
                   AND g.polyindex IS NOT NULL
                   AND ST_GeometryType(g.polyindex) <> 'POINT'
                   AND ST_Intersects(g.polyindex, ST_GeomFromText(?, " . self::SRID . "))" . $reachableGate . "
                   AND NOT EXISTS (
                       SELECT 1 FROM messages_groups mg WHERE mg.msgid = ? AND mg.groupid = g.id
                   )
                   AND NOT EXISTS (
                       -- Suppress re-rippling only when the poster's MOST RECENT Group/Joined log
                       -- for this group is a ripple-join (text='Rippled') AND they then LEFT it -
                       -- i.e. the membership they last opted out of was a rippled one. Most recent
                       -- join wins: the NOT EXISTS lj2 makes lj the latest Joined, so a later
                       -- manual/ordinary join (then leave) means they treated it as a normal group
                       -- and rippling is NOT blocked; ll.id > lj.id requires the leave to follow
                       -- that ripple-join. Sites B/C apply the identical rule.
                       SELECT 1 FROM logs lj
                       WHERE lj.user = ? AND lj.groupid = g.id
                         AND lj.type = 'Group' AND lj.subtype = 'Joined' AND lj.text = 'Rippled'
                         AND NOT EXISTS (
                             SELECT 1 FROM logs lj2
                             WHERE lj2.user = lj.user AND lj2.groupid = lj.groupid
                               AND lj2.type = 'Group' AND lj2.subtype = 'Joined'
                               AND lj2.id > lj.id
                         )
                         AND EXISTS (
                             SELECT 1 FROM logs ll
                             WHERE ll.user = lj.user AND ll.groupid = lj.groupid
                               AND ll.type = 'Group' AND ll.subtype = 'Left'
                               AND ll.id > lj.id
                         )
                   )
                   AND NOT EXISTS (
                       -- A ban is an explicit mod ejection: it withdraws the poster's live posts
                       -- and (modern ban) deletes their membership while recording a users_banned
                       -- row. Never ripple a poster's post into a group they are banned from - that
                       -- would silently re-insert the post (and, via addPosterMembershipToRippledGroups,
                       -- re-join the banned poster). Cover both representations: the users_banned
                       -- table (authoritative, no expiry) and a legacy collection='Banned' membership.
                       SELECT 1 FROM users_banned ub
                       WHERE ub.userid = ? AND ub.groupid = g.id
                   )
                   AND NOT EXISTS (
                       SELECT 1 FROM memberships mb
                       WHERE mb.userid = ? AND mb.groupid = g.id AND mb.collection = 'Banned'
                   )",
                [$reachWkt, $msgid, $msg->fromuser, $msg->fromuser, $msg->fromuser]
            );

            $n = 0;
            foreach ($targetGroups as $g) {
                $inserted = DB::affectingStatement(
                    "INSERT IGNORE INTO messages_groups
                        (msgid, groupid, collection, approvedat, arrival, autoreposts, msgtype, rippled_in)
                     VALUES (?, ?, '$collection', $approvedAt, NOW(), 0, ?, 1)",
                    [$msgid, $g->id, $msg->type]
                );
                $n += $inserted;
            }
            if ($n > 0) {
                $stats['rippled_in'] += $n;
                // §15/§16 instrumentation: count groups a post was rippled into.
                try {
                    DB::statement(
                        'INSERT INTO rippling_event_metrics (day, event, count) VALUES (CURDATE(), ?, ?) '
                        . 'ON DUPLICATE KEY UPDATE count = count + ?',
                        ['rippled_in', $n, $n]
                    );
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
            $home = DB::selectOne(
                'SELECT m.emailfrequency, m.eventsallowed, m.volunteeringallowed
                 FROM messages_groups mg
                 JOIN memberships m ON m.groupid = mg.groupid AND m.userid = ?
                 WHERE mg.msgid = ?
                 ORDER BY mg.arrival ASC
                 LIMIT 1',
                [$posterId, $msgid]
            );
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
            $targets = DB::select(
                "SELECT mg.groupid
                 FROM messages_groups mg
                 WHERE mg.msgid = ? AND mg.rippled_in = 1
                   AND NOT EXISTS (
                       SELECT 1 FROM memberships m WHERE m.userid = ? AND m.groupid = mg.groupid
                   )
                   AND NOT EXISTS (
                       SELECT 1 FROM logs lj
                       WHERE lj.user = ? AND lj.groupid = mg.groupid
                         AND lj.type = 'Group' AND lj.subtype = 'Joined' AND lj.text = 'Rippled'
                         AND NOT EXISTS (
                             SELECT 1 FROM logs lj2
                             WHERE lj2.user = lj.user AND lj2.groupid = lj.groupid
                               AND lj2.type = 'Group' AND lj2.subtype = 'Joined'
                               AND lj2.id > lj.id
                         )
                         AND EXISTS (
                             SELECT 1 FROM logs ll
                             WHERE ll.user = lj.user AND ll.groupid = lj.groupid
                               AND ll.type = 'Group' AND ll.subtype = 'Left'
                               AND ll.id > lj.id
                         )
                   )
                   AND NOT EXISTS (
                       -- Never re-join a poster to a group they are banned from. A ban deletes
                       -- their membership and withdraws their posts; site A already stops the post
                       -- rippling in, but this guards the membership backfill independently (it
                       -- runs for every already-rippled group, incl. pre-guard ones). No expiry.
                       SELECT 1 FROM users_banned ub
                       WHERE ub.userid = ? AND ub.groupid = mg.groupid
                   )",
                [$msgid, $posterId, $posterId, $posterId]
            );

            $addedThisCall = 0;
            foreach ($targets as $t) {
                $added = DB::affectingStatement(
                    "INSERT IGNORE INTO memberships
                        (userid, groupid, role, collection, emailfrequency, eventsallowed, volunteeringallowed, rippled, added)
                     VALUES (?, ?, 'Member', 'Approved', ?, ?, ?, 1, NOW())",
                    [$posterId, $t->groupid, $emailfrequency, $eventsallowed, $volunteeringallowed]
                );
                if ($added > 0) {
                    $addedThisCall++;
                    $stats['memberships_added'] = ($stats['memberships_added'] ?? 0) + 1;
                    // memberships_history with rippled=1: abuse detection still runs (processingrequired=1),
                    // but MembershipsProcessingService reads rippled to SUPPRESS the per-group welcome -
                    // a single bundled intro email (RippleIntroMail) is sent below instead.
                    DB::statement(
                        "INSERT INTO memberships_history (userid, groupid, collection, processingrequired, rippled)
                         VALUES (?, ?, 'Approved', 1, 1)",
                        [$posterId, $t->groupid]
                    );
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
        $claimed = DB::affectingStatement(
            'UPDATE rippling_reach SET ripple_intro_sent = 1 WHERE msgid = ? AND ripple_intro_sent = 0',
            [$msgid]
        );
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
            $welcomeGroups = array_map(
                static fn ($r) => ['name' => $r->name, 'welcome' => $r->welcome],
                DB::select(
                    "SELECT COALESCE(g.namefull, g.nameshort) AS name, g.welcomemail AS welcome
                     FROM messages_groups mg
                     JOIN `groups` g ON g.id = mg.groupid
                     JOIN memberships m ON m.groupid = g.id AND m.userid = ?
                     WHERE mg.msgid = ? AND mg.rippled_in = 1 AND m.rippled = 1
                       AND g.onhere = 1 AND g.welcomemail IS NOT NULL AND g.welcomemail <> ''
                     ORDER BY mg.arrival ASC",
                    [$posterId, $msgid]
                )
            );

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
            $scopeSql = '';
            $params = [now()->subDays(2)->toDateTimeString()];
            if ($onlyMsgid !== null) {
                $scopeSql = ' AND mg.msgid = ?';
                $params[] = $onlyMsgid;
            }

            $rows = DB::select(
                "SELECT DISTINCT mg.msgid, mg.groupid, m.fromuser
                 FROM logs ll
                 JOIN messages_groups mg ON mg.groupid = ll.groupid AND mg.rippled_in = 1 AND mg.deleted = 0
                 JOIN messages m ON m.id = mg.msgid AND m.fromuser = ll.user
                 WHERE ll.type = 'Group' AND ll.subtype = 'Left' AND ll.timestamp >= ?" . $scopeSql . "
                   AND EXISTS (
                       SELECT 1 FROM logs lj
                       WHERE lj.user = ll.user AND lj.groupid = ll.groupid
                         AND lj.type = 'Group' AND lj.subtype = 'Joined' AND lj.text = 'Rippled'
                         AND lj.id < ll.id
                         AND NOT EXISTS (
                             SELECT 1 FROM logs lj2
                             WHERE lj2.user = lj.user AND lj2.groupid = lj.groupid
                               AND lj2.type = 'Group' AND lj2.subtype = 'Joined'
                               AND lj2.id > lj.id
                         )
                   )",
                $params
            );

            if (empty($rows)) {
                return;
            }

            if ($dryRun) {
                $stats['pulled_on_leave'] += count($rows);
                return;
            }

            foreach ($rows as $r) {
                $n = DB::affectingStatement(
                    'UPDATE messages_groups SET deleted = 1
                     WHERE msgid = ? AND groupid = ? AND rippled_in = 1 AND deleted = 0',
                    [$r->msgid, $r->groupid]
                );
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
            $groupRow = DB::selectOne(
                'SELECT ST_AsText(g.polyindex) AS group_wkt
                 FROM messages_groups mg
                 JOIN `groups` g ON g.id = mg.groupid
                 WHERE mg.msgid = ? AND mg.deleted = 0
                   AND g.polyindex IS NOT NULL
                   AND ST_GeometryType(g.polyindex) <> \'POINT\'
                 ORDER BY mg.arrival ASC
                 LIMIT 1',
                [$msgid]
            );

            if ($groupRow === null || empty($groupRow->group_wkt)) {
                return $wkt;
            }

            $groupWkt = $groupRow->group_wkt;

            // ST_Intersection of two polygons that touch along a line or at a
            // point yields a GEOMETRYCOLLECTION, and ST_Area on that throws
            // error 3516. CASE evaluates lazily, so guarding on the geometry
            // type means ST_Area only ever sees polygonal input; a NULL frac
            // simply fails the >= 0.90 test below and the WKT passes through
            // unchanged - the same outcome the exception path produced, minus
            // the exception.
            $result = DB::selectOne(
                'SELECT CASE WHEN ST_GeometryType(inter) IN (\'POLYGON\', \'MULTIPOLYGON\')
                             THEN ST_Area(inter) / NULLIF(ST_Area(grp), 0)
                        END AS frac,
                        ST_AsText(ST_Union(iso, grp)) AS u
                 FROM (SELECT ST_Intersection(iso, grp) AS inter, iso, grp
                       FROM (SELECT ST_GeomFromText(?, ' . self::SRID . ') AS iso,
                                    ST_GeomFromText(?, ' . self::SRID . ') AS grp) s) t',
                [$wkt, $groupWkt]
            );

            if ($result !== null && ($result->frac ?? 0) >= 0.90 && !empty($result->u)) {
                return $result->u;
            }

            return $wkt;
        } catch (\Throwable $e) {
            // Retry once with ST_Buffer(geom, 0) geometry repair to handle invalid polygons.
            try {
                $groupRow = DB::selectOne(
                    'SELECT ST_AsText(g.polyindex) AS group_wkt
                     FROM messages_groups mg
                     JOIN `groups` g ON g.id = mg.groupid
                     WHERE mg.msgid = ? AND mg.deleted = 0
                       AND g.polyindex IS NOT NULL
                       AND ST_GeometryType(g.polyindex) <> \'POINT\'
                     ORDER BY mg.arrival ASC
                     LIMIT 1',
                    [$msgid]
                );

                if ($groupRow === null || empty($groupRow->group_wkt)) {
                    return $wkt;
                }

                $groupWkt = $groupRow->group_wkt;

                $result = DB::selectOne(
                    'SELECT CASE WHEN ST_GeometryType(inter) IN (\'POLYGON\', \'MULTIPOLYGON\')
                                 THEN ST_Area(inter) / NULLIF(ST_Area(grp), 0)
                            END AS frac,
                            ST_AsText(ST_Union(iso, grp)) AS u
                     FROM (SELECT ST_Intersection(iso, grp) AS inter, iso, grp
                           FROM (SELECT ST_Buffer(ST_GeomFromText(?, ' . self::SRID . '), 0) AS iso,
                                        ST_Buffer(ST_GeomFromText(?, ' . self::SRID . '), 0) AS grp) s) t',
                    [$wkt, $groupWkt]
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
                fn ($q) => $q->whereRaw('msgid % ? = ?', [$shardCount, (int) $shardIndex]))
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
                $tickGeom = $this->resolveTickGeometry($entry, (float) $row->lat, (float) $row->lng, (int) $row->msgid);
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
                        $ph = implode(',', array_fill(0, count($ids), '?'));
                        $stats['would_retract_groups'] += (int) DB::selectOne(
                            "SELECT COUNT(*) AS n FROM messages_groups
                              WHERE msgid = ? AND rippled_in = 1 AND deleted = 0
                                AND groupid NOT IN ({$ph})",
                            array_merge([$row->msgid], $ids)
                        )->n;
                    }
                    $stats['updated']++;
                    continue;
                }

                $storeWkt = $this->unionWithOriginGroupArea((int) $row->msgid, $tickWkt);
                // Grid + derived bounds in ONE statement; envelope retry on
                // throw. This pass RE-DERIVES (it may shrink), so a failed
                // rasterise skips the row rather than writing a reach nobody
                // can read.
                [$boundsSet, $boundsParams] = $this->boundsSetSql($storeWkt);
                if ($this->gridRetired((int) $row->msgid)) {
                    $cells = null;
                } else {
                    $cells = $this->cellSets->rasterize($storeWkt);
                    if ($cells === null) {
                        $stats['skipped']++;
                        continue;
                    }
                }
                $gridSet = ', polygon_cells = ?';
                $lead = [$cells];
                $backfillSql = fn (string $set): string => 'UPDATE rippling_reach
                        SET updated_at = updated_at' . $gridSet . $set . ',
                            schedule = ?, reachable_group_ids = ?,
                            total_freeglers = ?, max_drive_min = ?
                      WHERE msgid = ?';
                $backfillTail = [
                    json_encode($ticks),
                    json_encode($ids),
                    (int) $schedule['total_freeglers'],
                    $schedule['max_drive_min'],
                    $row->msgid,
                ];
                try {
                    // keep-raw: UPDATE with ST_GeomFromText/derived-bounds SQL expressions in SET - the builder cannot render these
                    DB::statement($backfillSql($boundsSet), array_merge($lead, $boundsParams, $backfillTail));
                } catch (\Throwable $e) {
                    [$envSet, $envParams] = $this->boundsEnvelopeSql($storeWkt);
                    // keep-raw: envelope-fallback variant of the same spatial UPDATE
                    DB::statement($backfillSql($envSet), array_merge($lead, $envParams, $backfillTail));
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
    private function resolveTickGeometry(?array $entry, float $lat, float $lng, ?int $msgid = null): ?array
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

        // Prefer the post's own stored labels. They already encode arrival times, so the
        // routing server can answer the whole tick without a fresh search over the road
        // network - the difference between a call that needs one of the eight compute
        // slots and one that does not. Expansion is the workload that saturates those
        // slots, so taking it off them is the point (see ReachService::tickFromLabels).
        if ($msgid !== null && $this->tickFromLabelsOk()) {
            $fromLabels = $this->reach->tickFromLabels($msgid, $driveMin);
            if ($fromLabels !== null) {
                return $fromLabels;
            }
        }

        // No labels, or a routing server that cannot serve them yet: the gated catchment
        // still answers, slower. Every reason tickFromLabels returns null is a reason
        // this post has to keep working.
        return $this->reach->catchmentGeometry($lat, $lng, $driveMin, $this->coarseTickGeometryOk($entry));
    }

    /**
     * Whether to serve ticks from stored labels rather than a fresh catchment.
     *
     * Off by default. The group set it returns decides who a post reaches, so it has to
     * be shown to match the polygon path's on a production sample before it does - the
     * fixture parity tests (TestReachTickGroupsMatchTheLiveSearch and its siblings) are
     * necessary, not sufficient. RIPPLE_TICK_FROM_LABELS=true once that sample is clean.
     */
    private function tickFromLabelsOk(): bool
    {
        return (bool) config('freegle.ripple.tick_from_labels', false);
    }

    /**
     * Whether this tick can be served by the cheap region-scale catchment.
     *
     * Everything expansion does with the geometry is region-scale - the sandwich bounds,
     * the union with the origin group's area, and the ST_Intersects that picks out the
     * groups the reach now touches - with one caveat, which is what this decides.
     *
     * The coarse outline is drawn on cells at least as big as the finest the exact path
     * would use, so it can sit outside the exact one by about a cell along the boundary.
     * On its own that could hand a group to ST_Intersects that the exact outline would
     * have missed. But when the reachable gate is on AND this tick carries its own set of
     * road-reachable group ids, that ST_Intersects is only a spatial-index prefilter: the
     * gate ANDs the exact set on top, and a superset prefilter intersected with an exact
     * set is exact (see rippleIntoNewGroups). Without the gate the outline IS the answer,
     * so we pay for the exact one - which is the same rule the gate itself follows, and
     * keeps the groups a post reaches identical either way.
     *
     * @param  array<string, mixed>  $entry
     */
    private function coarseTickGeometryOk(array $entry): bool
    {
        if (!config('freegle.ripple.coarse_tick_geometry', true)) {
            return false;
        }

        return $this->reachableGateEnabled() && !empty($entry['reachable_group_ids']);
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

        // The clip is pure grid arithmetic - fetch the row's grid, subtract
        // each rejecting group's rasterised area, write the survivor back
        // with the inner bound NULLed in the same statement (a stale inner
        // could cheap-accept viewers in the clipped-out area; the outer stays
        // a valid superset). On any failure the row is left UNCLIPPED and
        // logged: over-reaching into a rejecting group is visible and retried
        // next tick, where a wrong grid would silently misgate replies
        // everywhere.
        $this->reapplyClipsCellsOnly($msgid, array_map('intval', $gids));
    }

    /**
     * reapplyClips for the cells-only era: one read of the row's grid, one
     * Subtract per rejecting group (each group's area rasterised once per run,
     * cached), one write. A reach left wholly inside the union of its
     * rejecting groups keeps an EMPTY grid rather than being deleted here -
     * the advance that called this just wrote the row and owns its lifecycle;
     * an empty grid admits nobody, which is the correct behaviour for a post
     * every rejecting group has squeezed out.
     *
     * @param array<int,int> $gids
     */
    private function reapplyClipsCellsOnly(int $msgid, array $gids): void
    {
        $row = DB::table('rippling_reach')->where('msgid', $msgid)->first(['polygon_cells']);
        if ($row === null || $row->polygon_cells === null) {
            return;
        }

        // subtractEncoded, NEVER decode(): decode's memory follows the covered
        // AREA (one array entry per set cell), and the rejecting group's area
        // here can be a county - ~10M cells, ~1GB of PHP arrays. Six
        // ripple:expand OOMs in three hours on the first post-drop evening
        // (2026-08-26) came from exactly this loop; the streaming subtract
        // holds only the run boundaries.
        $cells = (string) $row->polygon_cells;
        $clipped = false;
        foreach ($gids as $gid) {
            $gwkt = DB::table('groups')->where('id', $gid)->value(DB::raw('ST_AsText(polyindex)'));
            if (!is_string($gwkt) || $gwkt === '' || str_starts_with($gwkt, 'POINT')) {
                continue;
            }
            $groupCells = $this->rasterizedGroupCells($gid, $gwkt);
            if ($groupCells === null) {
                Log::warning('ripple: reapplyClips could not rasterise rejecting group; its clip skipped this tick', [
                    'msgid' => $msgid, 'gid' => $gid,
                ]);
                continue;
            }
            $next = $this->cellSets->subtractEncoded($cells, $groupCells);
            if ($next === null) {
                Log::warning('ripple: reapplyClips subtract failed; that clip skipped this tick', [
                    'msgid' => $msgid, 'gid' => $gid,
                ]);
                continue;
            }
            $cells = $next;
            $clipped = true;
        }
        if (!$clipped) {
            return;
        }

        DB::table('rippling_reach')->where('msgid', $msgid)->update([
            'polygon_cells' => $cells,
            'inner_bound' => null,
        ]);
    }

    /**
     * Grid retirement, per row: once a post has BOTH its stored label and its
     * road-native union threshold (origin_union_secs, including -1 = never),
     * the label evaluator answers everything the current-reach grid did -
     * membership, the origin-group union, rejections - so the writers above
     * stop materialising the grid (NULL drains the blob) and skip the
     * rasterise round trip. Rows without the threshold keep their grid: it
     * still carries the union those members depend on. False on any doubt.
     */
    /** gridRetired from an already-fetched row - no query in the hot loop. */
    private function rowRetired(object $row): bool
    {
        return !empty($row->has_labels) && ($row->origin_union_secs ?? null) !== null;
    }

    /**
     * The origin-group union for a RETIRED row: the stored threshold decides
     * (no per-tick ST_Intersection/ST_Area coverage recompute - that is what
     * origin_union_secs replaced); only the ST_Union itself still runs, and
     * only while the union is active, because the scratch WKT still feeds the
     * group-crossing test and the sandwich bounds.
     */
    private function unionByThreshold(int $msgid, string $wkt, float $budgetSecs, $unionSecs): string
    {
        if ($unionSecs === null || (float) $unionSecs < 0 || $budgetSecs < (float) $unionSecs) {
            return $wkt;
        }
        try {
            $groupRow = DB::selectOne(
                'SELECT ST_AsText(g.polyindex) AS group_wkt
                 FROM messages_groups mg
                 JOIN `groups` g ON g.id = mg.groupid
                 WHERE mg.msgid = ? AND mg.deleted = 0
                   AND g.polyindex IS NOT NULL
                   AND ST_GeometryType(g.polyindex) <> \'POINT\'
                 ORDER BY mg.arrival ASC
                 LIMIT 1',
                [$msgid]
            );
            if ($groupRow === null || empty($groupRow->group_wkt)) {
                return $wkt;
            }
            $result = DB::selectOne(
                'SELECT ST_AsText(ST_Union(ST_GeomFromText(?, ' . self::SRID . '), ST_GeomFromText(?, ' . self::SRID . '))) AS u',
                [$wkt, $groupRow->group_wkt]
            );

            return !empty($result->u) ? $result->u : $wkt;
        } catch (\Throwable $e) {
            Log::warning("ripple: unionByThreshold failed for msg {$msgid}: {$e->getMessage()}");

            return $wkt;
        }
    }

    private function gridRetired(int $msgid): bool
    {
        try {
            return DB::table('rippling_reach')
                ->where('msgid', $msgid)
                ->whereNotNull('reach_labels')
                ->whereNotNull('origin_union_secs')
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /** Per-request cache: a rejecting group's own area is expensive to rasterise and does not change within one run. */
    private array $groupCellsCache = [];

    /**
     * Rasterise (and cache) one group's polyindex WKT into cells, reused across
     * every post this run clips against it. Only a SUCCESS is cached: caching a
     * transient rasterise failure would silently disable cells-clipping for
     * every later post rejected by the same group for the rest of the run, long
     * after the spatial server recovered - a failed lookup stays eligible for
     * retry on the next post instead.
     */
    private function rasterizedGroupCells(int $gid, string $wkt): ?string
    {
        if (isset($this->groupCellsCache[$gid])) {
            return $this->groupCellsCache[$gid];
        }
        $bytes = $this->cellSets->rasterize($wkt);
        if ($bytes !== null) {
            $this->groupCellsCache[$gid] = $bytes;
        }

        return $bytes;
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
        return (int) (DB::selectOne(
            "SELECT COUNT(DISTINCT userid) AS n FROM chat_messages WHERE refmsgid = ? AND type = 'Interested'",
            [$msgid]
        )->n ?? 0);
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
        return DB::selectOne(
            'SELECT 1 AS x FROM messages_outcomes WHERE msgid = ? AND outcome IN (?, ?, ?) LIMIT 1',
            [
                $msgid,
                \App\Models\MessageOutcome::OUTCOME_TAKEN,
                \App\Models\MessageOutcome::OUTCOME_RECEIVED,
                \App\Models\MessageOutcome::OUTCOME_WITHDRAWN,
            ]
        ) !== null;
    }

    private function logEvent(int|string $msgid, string $kind, int $tick, array $entry): void
    {
        Log::info('ripple:reach', [
            'msgid' => (int) $msgid,
            'kind' => $kind,
            'tick' => $tick,
            'drive_min' => $entry['drive_min'] ?? null,
            'cumulative_users' => $entry['cumulative_users'] ?? null,
            // Memory growth diagnostics: ripple:expand accumulated its way to
            // the 1GB limit over hundreds of advances on 2026-08-26 (post-drop
            // evening) with no single poison post - the per-advance curve is
            // what identifies WHICH advances leak and how fast. Cheap (two
            // ints) and load-bearing until that is understood; see the
            // incident notes on PR #1420.
            'mem_mb' => (int) (memory_get_usage(true) / 1048576),
            'mem_peak_mb' => (int) (memory_get_peak_usage(true) / 1048576),
        ]);
    }
}
