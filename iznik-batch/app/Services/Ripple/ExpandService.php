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
 * Immediate mails (PR B) and held-reply release (PR C) bolt onto this same
 * per-tick loop later.
 */
class ExpandService
{
    /**
     * The wall-clock moment this run must stop taking new rows, or null when
     * unboxed. Set per process() call from freegle.ripple.expand_time_box_seconds;
     * see the comment there for why runs are bounded (the single-instance lock's
     * TTL can never be tuned above an open-ended run length).
     */
    private ?Carbon $runDeadline = null;

    /**
     * Whether this run's time box has expired. Row loops call this at their row
     * boundary: cheap, and a row in flight always completes (no partial writes).
     */
    private function pastRunDeadline(): bool
    {
        return $this->runDeadline !== null && now()->greaterThan($this->runDeadline);
    }

    private const SRID = 3857;

    /** Metres to blur a poster's origin before it drives the reach (matches Utils::BLUR_USER). */
    private const BLUR_USER = 400;

    /** Maintains the sandwich-bounds columns alongside every polygon write. */
    private ReachBoundsService $bounds;

    /** Chooses each post's reach budget from how thinly freeglers are spread around it. */
    private DensityService $density;

    /** Compact cell-set form of the reach polygon (plans/2026-08-24-rippling-reach-raster-storage.md). */
    private CellSetService $cellSets;

    public function __construct(
        private ReachService $reach,
        ?ReachBoundsService $bounds = null,
        ?DensityService $density = null,
        ?CellSetService $cellSets = null
    ) {
        $this->bounds = $bounds ?? new ReachBoundsService;
        $this->density = $density ?? new DensityService;
        $this->cellSets = $cellSets ?? new CellSetService;
    }

    /**
     * Bump a per-day rippling counter. Best-effort: instrumentation never affects the run.
     */
    private function recordEvent(string $event, int $by = 1): void
    {
        try {
            DB::statement(
                'INSERT INTO rippling_event_metrics (day, event, count) VALUES (CURDATE(), ?, ?) '
                .'ON DUPLICATE KEY UPDATE count = count + ?',
                [$event, $by, $by]
            );
        } catch (\Throwable $e) {
            // best-effort; never affect the expander
        }
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
        $poly = 'ST_GeomFromText(?, '.self::SRID.')';

        return [
            ', outer_bound = '.ReachBoundsService::outerExpr($poly)
            .', inner_bound = '.ReachBoundsService::innerExpr($poly),
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
            ', outer_bound = ST_Envelope(ST_GeomFromText(?, '.self::SRID.')), inner_bound = NULL',
            [$storeWkt],
        ];
    }

    /**
     * @return array{initialized:int,expanded:int,completed:int,removed:int,skipped:int,errors:int}
     */
    /**
     * @param  int|null  $onlyMsgid  Restrict the whole run to one message ID (controlled testing).
     * @param  string|null  $withinPolyWkt  Restrict the whole run to posts whose origin point falls within
     *                                      this WKT polygon (SRID self::SRID) — the area test (e.g. ripple
     *                                      the recent posts near Edinburgh). The go-live arrival cutoff
     *                                      still applies (an area scope filters where, not when).
     */
    public function process(bool $dryRun = false, int $limit = 500, ?int $onlyMsgid = null, ?string $withinPolyWkt = null): array
    {
        $stats = [
            'initialized' => 0, 'expanded' => 0, 'completed' => 0,
            'removed' => 0, 'skipped' => 0, 'errors' => 0, 'timeboxed' => 0,
        ];

        // Time-box the run BELOW the command's single-instance lock TTL (3600s in
        // ExpandCommand), or the guard defeats itself: a backlogged run is limit x one
        // catchment each, late-tick catchments cost 4-6s plus compute-gate waits, and on
        // 2026-08-30 a full run exceeded the hour - the lock expired mid-run, the
        // every-minute schedule admitted another run at each expiry, and the stack grew to
        // match the routing server's 8 gate slots (zero goodput, 7,300 rows overdue).
        // TTL-chasing cannot win because run length is open-ended under backlog; bounding
        // the RUN can. On expiry the row loops below exit at the next row boundary, the
        // lock releases in the command's finally, and the next minute's tick resumes where
        // this one stopped - unprocessed rows simply stay due. 0 disables (tests/one-offs).
        $box = (int) config('freegle.ripple.expand_time_box_seconds', 2700);
        $this->runDeadline = $box !== 0 ? now()->addSeconds($box) : null;

        // A scoped run ($onlyMsgid or $withinPolyWkt) targets a chosen subset of posts (controlled/area
        // testing): init, advance AND retraction are all restricted to the same subset.
        $scoped = $onlyMsgid !== null || $withinPolyWkt !== null;

        // Master activation switch. While rippling is globally disabled an UNSCOPED run does nothing
        // (no reach computed, nothing rippled). A SCOPED run is still allowed through while global is
        // off, for controlled/area testing. The unscoped cron is also unscheduled when off
        // (routes/console.php); this gate is defence-in-depth.
        if (! config('freegle.ripple.enabled') && ! $scoped) {
            return $stats;
        }

        // 1. Stop-and-retract for posts that have left the browsable set — rejected, withdrawn,
        //    expired or deleted (Taken/Received stay in messages_spatial and are excluded): drop
        //    the post's reach row, which both stops further expansion and lets
        //    ripple:release-replies treat the post as gone.
        // Retraction is deliberately NOT area-scoped (only --msgid restricts it).
        $this->removeStaleAndRetract($dryRun, $stats, $onlyMsgid);

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
     * tick. It deliberately does not bump updated_at — so it generates no mail
     * (sendReachDigests only scans recently-updated rows, and the
     * rippling_reach_notified ledger blocks re-notification regardless) and
     * never retracts visibility already given at the wider reach (we shrink
     * future reach, we don't claw back).
     *
     * No-op unless the cap is active. Only rows whose pool (total_freeglers)
     * exceeds the cap are candidates — nothing else can be over it. Galera-safe:
     * one row per UPDATE.
     *
     * @return array{candidates:int, shrunk:int, skipped:int}
     */
    public function recomputeReach(bool $dryRun = false, int $limit = 1000, ?int $onlyMsgid = null): array
    {
        $stats = ['candidates' => 0, 'shrunk' => 0, 'skipped' => 0];

        $target = (int) config('freegle.ripple.extent.target_users', 0);
        if (! config('freegle.ripple.extent.enabled') || $target <= 0) {
            return $stats; // cap not active — there is nothing smaller to shrink to
        }

        $q = DB::table('rippling_reach')
            ->select(['msgid', 'lat', 'lng', 'tick', 'total_freeglers', 'status', 'max_minutes_cap'])
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

            $storeWkt = $tickWkt;

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
                    SET updated_at = updated_at'.$gridSet.$set.',
                        schedule = ?, total_freeglers = ?, max_drive_min = ?'
                        .$ovCellsSet.'
                  WHERE msgid = ?';
            $shrinkTail = [
                json_encode($ticks),
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

            // Routing-provided bounds upgrade the columns, verified
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
     * Stop-and-retract for every post that has left messages_spatial - rejected, withdrawn,
     * expired or deleted (Taken/Received stay in messages_spatial and are intentionally
     * excluded). For each such post we drop its rippling_reach row, which both stops further
     * expansion and lets ripple:release-replies treat the post as gone, releasing any held
     * replies.
     *
     * Scope: only --msgid restricts this (controlled single-post testing). It is intentionally
     * NOT area-scoped - a post that has genuinely gone is retracted regardless of whether its
     * origin is still inside the current run's target area. $stats['removed'] = reach rows
     * dropped.
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
                 WHERE ms.msgid IS NULL AND mr.status <> \'held\''.$scopeSql,
                $params
            );

            $absent = array_map(static fn ($r) => (int) $r->msgid, $stale);

            // Absent from messages_spatial does not mean gone. The index job can be
            // down, or die between its delete and add passes - and historically its age
            // pass deleted ~3,000 still-qualifying posts at the end of every run (fixed
            // in removeOldMessages alongside this check). Treating each absence as "the
            // post has gone" deleted the reach row and retracted the post everywhere it
            // had rippled (feeding the same churn back into the index), and then
            // initialiseNew built the whole thing again from scratch - routing searches
            // and a large polygon write to the cluster's write node, per post. On
            // production that was about 85% of all initialisation work: 11,656
            // initialisations in one day against 1,635 genuinely new posts, with 8,802
            // reach rows dropped.
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

                return;
            }

            foreach ($msgids as $msgid) {
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
            if (! isset($alive[$msgid])) {
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
     * When each reposted post first went live, for posts whose reach should carry on across a
     * member's own repost.
     *
     * The repost turns the post back into a draft, which removes every copy and then the reach
     * row, so there is nothing left to resume from; the post's log is the only record of how
     * long it has been live. Walked in order, a "run" of the post starts at its first Received
     * and continues through reposts that come within repost_keeps_reach_days of it last being
     * live (Received, Approved, Autoapproved or Autoreposted). A longer gap starts a new run.
     * The run starts at its first approval, or at its first Received on a community that does
     * not moderate and so logs no approval.
     *
     * @param  int[]  $msgids
     * @return array<int, Carbon> msgid => start, only for posts reposted within the current run
     */
    private function repostCarriedArrivals(array $msgids): array
    {
        $days = (int) config('freegle.ripple.repost_keeps_reach_days', 7);
        if ($days <= 0 || empty($msgids)) {
            return [];
        }

        $events = DB::table('logs')
            ->whereIn('msgid', $msgids)
            ->where('type', 'Message')
            ->whereIn('subtype', ['Received', 'Approved', 'Autoapproved', 'Autoreposted', 'Repost'])
            ->orderBy('msgid')
            ->orderBy('timestamp')
            ->orderBy('id')
            ->get(['msgid', 'subtype', 'timestamp'])
            ->groupBy('msgid');

        $carried = [];
        foreach ($events as $msgid => $list) {
            $received = null;
            $approved = null;
            $lastLive = null;
            $repostedInRun = false;

            foreach ($list as $e) {
                $at = Carbon::parse($e->timestamp);
                if ($e->subtype === 'Repost') {
                    if ($lastLive === null || $lastLive->lt($at->copy()->subDays($days))) {
                        // Not live for too long: this repost begins a new run.
                        $received = null;
                        $approved = null;
                        $repostedInRun = false;
                    } else {
                        $repostedInRun = true;
                    }
                    continue;
                }

                $lastLive = $at;
                if ($e->subtype === 'Received') {
                    $received ??= $at;
                } elseif (!$repostedInRun && in_array($e->subtype, ['Approved', 'Autoapproved'], true)) {
                    // Only the approval that first put the run live; a re-approval after a
                    // repost is exactly the restart this undoes.
                    $approved ??= $at;
                }
            }

            $start = $approved ?? $received;
            if ($repostedInRun && $start !== null) {
                $carried[(int) $msgid] = $start;
            }
        }

        return $carried;
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
                $scopeSql = ' AND ST_Contains(ST_GeomFromText(?, '.self::SRID.'), ms.point)';
                $params[] = $withinPolyWkt;
            }
            if (! empty($enabledAt)) {
                $cutoffSql = ' AND ms.arrival >= ?';
                $params[] = $enabledAt;
            }
            // Reply-saturation stop (extent-governor T1.1): a post that already has >= threshold
            // distinct repliers never starts rippling - it has enough interest without reach.
            // 0 disables. Applies to normal and scoped runs alike.
            $satStop = (int) config('freegle.ripple.reply_saturation_stop', 5);
            if ($satStop > 0) {
                $satSql = " AND (SELECT COUNT(DISTINCT cm.userid) FROM chat_messages cm
                                  WHERE cm.refmsgid = ms.msgid AND cm.type = 'Interested') < ?";
                $params[] = $satStop;
            }
        }
        $params[] = $limit;

        // Candidate source: live posts with NO reach row yet (anti-join).
        // keep-raw: ANY_VALUE + the ST_X/ST_Y spatial accessors on a GROUP BY the builder cannot render
        $rows = DB::select(
            'SELECT ms.msgid AS msgid,
                    ANY_VALUE(ST_Y(ms.point)) AS lat,
                    ANY_VALUE(ST_X(ms.point)) AS lng,
                    MIN(ms.arrival) AS arrival
             FROM messages_spatial ms
             LEFT JOIN rippling_reach mr ON mr.msgid = ms.msgid
             WHERE mr.msgid IS NULL'.$scopeSql.$cutoffSql.$satSql.'
             GROUP BY ms.msgid
             LIMIT ?',
            $params
        );

        // A member's own repost is re-approved as if new, so without this its reach would start
        // again at tick 1 and hide it from people it had already reached.
        $carried = $this->repostCarriedArrivals(array_map(fn ($r) => (int) $r->msgid, $rows));
        foreach ($rows as $row) {
            $start = $carried[(int) $row->msgid] ?? null;
            if ($start !== null && $row->arrival !== null && $start->lt(Carbon::parse($row->arrival))) {
                $row->arrival = $start->format('Y-m-d H:i:s');
            }
        }

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
            $blurredByRow[$i] = ['lat' => $lat, 'lng' => $lng, 'key' => $lat.','.$lng];
            $distinctOrigins[$lat.','.$lng] = ['lat' => $lat, 'lng' => $lng];
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
        if (config('freegle.ripple.reuse_reach', true) && ! empty($distinctOrigins)) {
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
                 WHERE schedule IS NOT NULL AND (lat, lng) IN ('.$placeholders.')',
                $reuseParams
            );
            foreach ($existing as $e) {
                // Rebuild the same 4dp key the distinctOrigins map uses, so PHP-float and DB-double
                // string formatting agree (both sides are round(...,4)).
                $k = round((float) $e->lat, 4).','.round((float) $e->lng, 4);
                if (! isset($distinctOrigins[$k]) || isset($scheduleByKey[$k])) {
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
                if (! is_array($ticks) || empty($ticks)) {
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
        // DO NOT parallelise this loop: the rippling_reach writes must stay single-writer and in order.
        foreach ($rows as $i => $row) {
            if ($this->pastRunDeadline()) {
                // Time box expired (see process()): stop at the row boundary; the
                // unprocessed posts remain uninitialised and the next tick takes them.
                $stats['timeboxed'] += 1;
                Log::info('ripple:expand initialiseNew time-boxed; next tick resumes', [
                    'initialized' => $stats['initialized'],
                ]);
                break;
            }
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

                if (! $dryRun) {
                    $tickGeom = $this->resolveTickGeometry($entry, (float) $lat, (float) $lng);
                    if ($tickGeom === null) {
                        // Routing unreachable mid-run - leave the post for the next pass
                        // rather than storing a reach with no polygon.
                        $stats['skipped']++;

                        continue;
                    }
                    $tickWkt = $tickGeom['wkt'];
                    $storeWkt = $tickWkt;
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
                    $poly = 'ST_GeomFromText(?, '.self::SRID.')';
                    $initSql = function (string $outerExpr, string $innerExpr): string {
                        return 'INSERT INTO rippling_reach
                           (msgid, lat, lng, polygon_cells, outer_bound, inner_bound, arrival, mode, tick, total_ticks,
                            total_freeglers, max_drive_min, schedule,
                            next_expansion_at, status, density_band, density_radius_miles, max_minutes_cap,
                            overflow_cells, created_at, updated_at)
                         VALUES (?, ?, ?, ?, '.$outerExpr.', '.$innerExpr.', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                         ON DUPLICATE KEY UPDATE
                            lat = VALUES(lat), lng = VALUES(lng), polygon_cells = VALUES(polygon_cells),
                            outer_bound = VALUES(outer_bound), inner_bound = VALUES(inner_bound),
                            arrival = VALUES(arrival), mode = VALUES(mode), tick = VALUES(tick),
                            total_ticks = VALUES(total_ticks), total_freeglers = VALUES(total_freeglers),
                            max_drive_min = VALUES(max_drive_min), schedule = VALUES(schedule),
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
                                $initSql('ST_Envelope('.$poly.')', 'NULL'),
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
        // $row-> use to the END of this function.
        $rows = DB::table('rippling_reach')
            ->select(['msgid', 'lat', 'lng', 'tick', 'min_tick', 'total_ticks', 'arrival'])
            ->addSelect(DB::raw('reach_labels IS NOT NULL AS has_labels'))
            ->addSelect('origin_union_secs')
            ->where('status', 'expanding')
            ->whereNotNull('next_expansion_at')
            ->where('next_expansion_at', '<=', now())
            ->when($onlyMsgid !== null, fn ($q) => $q->where('msgid', $onlyMsgid))
            // keep-raw: ST_Contains/ST_GeomFromText are spatial functions the builder cannot render
            ->when($withinPolyWkt !== null, fn ($q) => $q->whereRaw(
                'ST_Contains(ST_GeomFromText(?, '.self::SRID.'), ST_SRID(POINT(lng, lat), '.self::SRID.'))',
                [$withinPolyWkt]
            ))
            ->limit($limit)
            ->get();

        // The expensive step per row is a single /v1/catchment round trip
        // (~3-4s at late ticks), and issuing them one at a time from this
        // single runner made the drain rate BE that round-trip time while the
        // routing server's compute slots sat idle (observed after the rock36
        // resize: 12 slots, ~8 advances/min). Each chunk therefore runs in
        // three passes: plan every row serially (all the stop checks with
        // their small writes, unchanged), fetch the planned catchments
        // CONCURRENTLY (read-only; compute_concurrency at a time, deliberately
        // below the routing gate's slot count so schedule computes and digest
        // calls never queue behind a drain), then apply serially. The
        // single-writer Galera discipline is untouched: nothing writes during
        // the fan-out, and the apply pass writes in the same order and shape
        // as the old single loop.
        $chunkSize = max(1, (int) config('freegle.ripple.compute_concurrency', 8));
        $deadlineHit = false;
        foreach ($rows->chunk($chunkSize) as $chunkRows) {
            if ($deadlineHit) {
                break;
            }

            // ── Pass A: plan serially. The stop/reschedule branches (with their
            // small writes) complete here; a row that survives into $plans has
            // had NO writes yet, so a deadline can drop planned rows safely -
            // they simply stay due for the next run.
            $plans = [];
            foreach ($chunkRows as $row) {
                if ($this->pastRunDeadline()) {
                    // Time box expired: stop cleanly at the row boundary so the lock
                    // releases and the next tick resumes. The remaining rows stay due,
                    // including any planned-but-unapplied rows in this chunk.
                    $stats['timeboxed'] += 1;
                    Log::info('ripple:expand advanceDue time-boxed; next tick resumes', [
                        'processed' => $stats['expanded'] + $stats['completed'] + $stats['skipped'],
                    ]);
                    $deadlineHit = true;
                    break;
                }
                try {
                    $schedule = DB::table('rippling_reach')->where('msgid', $row->msgid)->value('schedule');
                    $ticks = json_decode($schedule, true);
                    if (! is_array($ticks) || empty($ticks)) {
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
                        if (! $dryRun) {
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
                    // keeps rippling for a tick or two after the outcome is recorded.
                    if ($this->hasTerminalOutcome((int) $row->msgid)) {
                        if (! $dryRun) {
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
                        if (! $dryRun) {
                            $next = $this->reach->nextExpansionAfter($arrival, (int) $row->tick, $total);
                            DB::table('rippling_reach')->where('msgid', $row->msgid)->where('status', '<>', 'held')->update([
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

                    $plans[] = [
                        'row' => $row,
                        'entry' => $entry,
                        'target' => $target,
                        'next' => $next,
                        'status' => $next === null ? 'done' : 'expanding',
                    ];
                } catch (\Throwable $e) {
                    $stats['errors']++;
                    Log::warning("ripple: advance failed for msg {$row->msgid}: {$e->getMessage()}");
                }
            }

            if ($deadlineHit) {
                break;
            }

            // ── Fan-out: fetch this chunk's catchments concurrently. Only the
            // HTTP case prefetches; entries carrying a literal wkt (and the
            // degenerate drive_min<=0 case) fall through to resolveTickGeometry
            // in the apply pass. Dry runs never touch geometry at all. A null
            // result is stored so the apply pass sees the same "routing
            // unreachable - retry next sweep" signal the serial call gave.
            if (! $dryRun) {
                $jobs = [];
                $jobPlanIdx = [];
                foreach ($plans as $i => $plan) {
                    $planEntry = $plan['entry'];
                    if (empty($planEntry['wkt']) && (float) ($planEntry['drive_min'] ?? 0) > 0) {
                        $jobs[] = [
                            'lat' => (float) $plan['row']->lat,
                            'lng' => (float) $plan['row']->lng,
                            'minutes' => (float) $planEntry['drive_min'],
                            'coarse' => false,
                        ];
                        $jobPlanIdx[] = $i;
                    }
                }
                if (! empty($jobs)) {
                    $geoms = $this->reach->catchmentGeometriesBatch($jobs);
                    foreach ($jobPlanIdx as $j => $i) {
                        $plans[$i]['geom'] = $geoms[$j] ?? null;
                    }
                }
            }

            // ── Pass B: apply serially - the one DB writer, same statements
            // and per-row ordering as the old single loop.
            foreach ($plans as $plan) {
                $row = $plan['row'];
                $entry = $plan['entry'];
                $target = $plan['target'];
                $next = $plan['next'];
                $status = $plan['status'];
                try {
                    if (! $dryRun) {
                        $tickGeom = array_key_exists('geom', $plan)
                            ? $plan['geom']
                            : $this->resolveTickGeometry($entry, (float) $row->lat, (float) $row->lng);
                        if ($tickGeom === null) {
                            // Routing unreachable - keep the previous polygon and retry this
                            // tick on the next run (next_expansion_at is already due).
                            $stats['skipped']++;

                            continue;
                        }
                        $tickWkt = $tickGeom['wkt'];
                        // Retired rows (label + union threshold stored) do not
                        // re-derive the >=90% coverage test geometrically every
                        // tick - the stored threshold IS that answer.
                        $retired = $this->rowRetired($row);
                        $storeWkt = $tickWkt;
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
                         SET updated_at = NOW()'.$gridSet.$set.',
                             tick = ?, next_expansion_at = ?, status = ?
                         WHERE msgid = ?';
                        $advanceTail = [$target, $next, $status, $row->msgid];
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
                        // Routing-provided bounds (tighter than the derived ones) upgrade the
                        // columns, verified against the stored polygon.
                        // Retired rows take the direct write: the verify/fallback dance reads
                        // the grid this row no longer has, and every probe of it would waste
                        // three round trips to conclude nothing (adversarial review 2026-08-28).
                        if ($tickGeom['outer'] !== null) {
                            $this->bounds->sync((int) $row->msgid, $tickGeom['outer'], $tickGeom['inner'], $retired);
                        }
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
    }

    /**
     * The cached schedule entry for a target tick: the one with the largest `tick`
     * number ≤ target (so a higher tick whose polygon was filtered out falls back to
     * the most-grown reach available), or the first entry if none qualify. Indexing by
     * tick number — not array position — survives filtered/empty-polygon ticks.
     *
     * @param  array<int,array{tick:int,wkt:string}>  $ticks
     */
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
        if (! empty($entry['wkt'])) {
            return ['wkt' => (string) $entry['wkt'], 'outer' => null, 'inner' => null];
        }
        $driveMin = (float) ($entry['drive_min'] ?? 0);
        if ($driveMin <= 0) {
            return null;
        }

        return $this->reach->catchmentGeometry($lat, $lng, $driveMin, false);
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
     * Grid retirement, per row: once a post has BOTH its stored label and its
     * road-native union threshold (origin_union_secs, including -1 = never),
     * the label evaluator answers everything the current-reach grid did, so
     * the writers above stop materialising the grid (NULL drains the blob)
     * and skip the rasterise round trip. Rows without the threshold keep
     * their grid: it still carries the reach test the label alone cannot
     * answer. False on any doubt.
     */
    /** gridRetired from an already-fetched row - no query in the hot loop. */
    private function rowRetired(object $row): bool
    {
        return ! empty($row->has_labels) && ($row->origin_union_secs ?? null) !== null;
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
     * True when the post has a live Approved copy on a group it was posted to directly (not
     * rippled into). Same test as FreezeReachIfOriginPending in iznik-server-go.
     */
    private function originIsApproved(int $msgid): bool
    {
        return DB::table('messages_groups')
            ->where('msgid', $msgid)
            ->where('rippled_in', 0)
            ->where('deleted', 0)
            ->where('collection', \App\Models\MessageGroup::COLLECTION_APPROVED)
            ->exists();
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
