<?php

namespace App\Services\Ripple;

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

    public function __construct(private ReachService $reach)
    {
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
        $this->removeStaleAndRetract($dryRun, $stats, $onlyMsgid, $withinPolyWkt);
        // 1b. Pull rippled-in posts from any group whose poster has actively left it, so a
        //     leave removes the poster's post from that group (not just their membership).
        $this->pullRippledPostsFromLeftGroups($dryRun, $stats, $onlyMsgid, $withinPolyWkt);

        // 2. Initialise reach for posts new to messages_spatial.
        $this->initialiseNew($dryRun, $limit, $stats, $onlyMsgid, $withinPolyWkt);

        // 3. Advance reach for posts whose next tick is due — active hours only.
        if ($this->inActiveHours()) {
            $this->advanceDue($dryRun, $limit, $stats, $onlyMsgid, $withinPolyWkt);
        }

        return $stats;
    }

    /**
     * Stop-and-retract for every post that has left messages_spatial — rejected/removed on its
     * origin group, withdrawn, expired or deleted (Taken/Received stay in messages_spatial and
     * are intentionally excluded). For each such post we drop its rippling_reach row (which both
     * stops further expansion and lets ripple:release-replies treat the post as gone, releasing
     * any held replies) and retract every rippled-in copy (see retractRippledCopiesForRemovedPost).
     *
     * Scope-aware: a scoped run restricts removal to the in-scope subset (onlyMsgid, or reach
     * origin within withinPolyWkt — the same filter advanceDue uses), so the group experiment
     * retracts correctly. $stats['removed'] keeps its meaning: reach rows dropped.
     */
    private function removeStaleAndRetract(bool $dryRun, array &$stats, ?int $onlyMsgid = null, ?string $withinPolyWkt = null): void
    {
        try {
            $scopeSql = '';
            $params = [];
            if ($onlyMsgid !== null) {
                $scopeSql = ' AND mr.msgid = ?';
                $params[] = $onlyMsgid;
            } elseif ($withinPolyWkt !== null) {
                $scopeSql = ' AND ST_Contains(ST_GeomFromText(?, ' . self::SRID . '), ST_SRID(POINT(mr.lng, mr.lat), ' . self::SRID . '))';
                $params[] = $withinPolyWkt;
            }

            $stale = DB::select(
                'SELECT mr.msgid AS msgid
                 FROM rippling_reach mr
                 LEFT JOIN messages_spatial ms ON ms.msgid = mr.msgid
                 WHERE ms.msgid IS NULL' . $scopeSql,
                $params
            );
            if (empty($stale)) {
                return;
            }
            $msgids = array_map(static fn ($r) => (int) $r->msgid, $stale);

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
            $n = DB::affectingStatement(
                'UPDATE messages_groups SET deleted = 1
                 WHERE msgid = ? AND groupid = ? AND rippled_in = 1 AND deleted = 0',
                [$msgid, $groupid]
            );
            if ($n < 1) {
                continue;
            }
            $stats['pulled_on_removal']++;
            DB::table('logs')->insert([
                'timestamp' => now(),
                'type' => 'Message',
                'subtype' => 'Deleted',
                'user' => $posterId,
                'byuser' => null,
                'groupid' => $groupid,
                'msgid' => $msgid,
                'text' => 'Rippling: removed on origin removal',
            ]);

            if (!$posterId) {
                continue;
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
                continue;
            }
            // Only a ripple-join (rippled=1) is removed; an organic membership is never touched.
            $removed = DB::table('memberships')
                ->where('userid', $posterId)
                ->where('groupid', $groupid)
                ->where('rippled', 1)
                ->delete();
            if ($removed > 0) {
                $stats['memberships_removed']++;
            }
        }
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

        $rows = DB::select(
            'SELECT ms.msgid AS msgid,
                    ANY_VALUE(ST_Y(ms.point)) AS lat,
                    ANY_VALUE(ST_X(ms.point)) AS lng,
                    MIN(ms.arrival) AS arrival
             FROM messages_spatial ms
             LEFT JOIN rippling_reach mr ON mr.msgid = ms.msgid
             WHERE mr.msgid IS NULL' . $scopeSql . $cutoffSql . $satSql . '
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

        $scheduleByKey = []; // "lat,lng" => parsed schedule | null
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

                $schedule = $scheduleByKey[$blurredByRow[$i]['key']] ?? null;
                if ($schedule === null) {
                    // Routing unreachable or origin off-graph — retry next run.
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
                    $storeWkt = $this->unionWithOriginGroupArea((int) $row->msgid, $entry['wkt']);
                    DB::statement(
                        'INSERT INTO rippling_reach
                           (msgid, lat, lng, polygon, arrival, mode, tick, total_ticks,
                            total_freeglers, max_drive_min, schedule, next_expansion_at, status,
                            created_at, updated_at)
                         VALUES (?, ?, ?, ST_GeomFromText(?, ' . self::SRID . '), ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                        [
                            $row->msgid, $lat, $lng, $storeWkt, $arrival,
                            $this->reach->mode(), $tick, $total,
                            $schedule['total_freeglers'], $schedule['max_drive_min'],
                            json_encode($schedule['ticks']), $next, $status,
                        ]
                    );
                    $this->rippleIntoNewGroups((int) $row->msgid, $storeWkt, $stats);
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
            ->when($withinPolyWkt !== null, fn ($q) => $q->whereRaw(
                'ST_Contains(ST_GeomFromText(?, ' . self::SRID . '), ST_SRID(POINT(lng, lat), ' . self::SRID . '))',
                [$withinPolyWkt]
            ))
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
                    $storeWkt = $this->unionWithOriginGroupArea((int) $row->msgid, $entry['wkt']);
                    DB::statement(
                        'UPDATE rippling_reach
                         SET polygon = ST_GeomFromText(?, ' . self::SRID . '),
                             tick = ?, next_expansion_at = ?, status = ?, updated_at = NOW()
                         WHERE msgid = ?',
                        [$storeWkt, $target, $next, $status, $row->msgid]
                    );
                    // The polygon was just overwritten from the cached schedule, which does NOT
                    // include any secondary-group rejection clips. Re-subtract every rejected
                    // group so a secondary "out of area" rejection survives expansion (#9).
                    $this->reapplyClips((int) $row->msgid, $row->rejected_groups ?? null);
                    $this->rippleIntoNewGroups((int) $row->msgid, $storeWkt, $stats);
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
    private function rippleIntoNewGroups(int $msgid, string $reachWkt, array &$stats): void
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

            $n = DB::affectingStatement(
                "INSERT IGNORE INTO messages_groups (msgid, groupid, collection, approvedat, arrival, autoreposts, msgtype, rippled_in)
                 SELECT ?, g.id, '$collection', $approvedAt, NOW(), 0, m.type, 1
                 FROM `groups` g
                 CROSS JOIN messages m
                 WHERE m.id = ?
                   AND g.publish = 1
                   AND g.type = 'Freegle'
                   AND g.onhere = 1
                   AND g.nameshort NOT LIKE '%playground%'
                   AND g.polyindex IS NOT NULL
                   AND ST_GeometryType(g.polyindex) <> 'POINT'
                   AND ST_Intersects(g.polyindex, ST_GeomFromText(?, " . self::SRID . "))
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
                       WHERE lj.user = m.fromuser AND lj.groupid = g.id
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
                   )",
                [$msgid, $msgid, $reachWkt, $msgid]
            );
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
                   )",
                [$msgid, $posterId, $posterId]
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
    private function pullRippledPostsFromLeftGroups(bool $dryRun, array &$stats, ?int $onlyMsgid = null, ?string $withinPolyWkt = null): void
    {
        try {
            // Scope-aware so this runs under a scoped (group-experiment) run too: restrict to the
            // chosen post, or to posts whose origin point falls inside the area polygon.
            $scopeSql = '';
            $params = [];
            if ($onlyMsgid !== null) {
                $scopeSql = ' AND mg.msgid = ?';
                $params[] = $onlyMsgid;
            } elseif ($withinPolyWkt !== null) {
                $scopeSql = ' AND ST_Contains(ST_GeomFromText(?, ' . self::SRID . '), ST_SRID(POINT(m.lng, m.lat), ' . self::SRID . '))';
                $params[] = $withinPolyWkt;
            }

            $rows = DB::select(
                "SELECT mg.msgid, mg.groupid, m.fromuser
                 FROM messages_groups mg
                 JOIN messages m ON m.id = mg.msgid
                 WHERE mg.rippled_in = 1 AND mg.deleted = 0" . $scopeSql . "
                   AND EXISTS (
                       SELECT 1 FROM logs lj
                       WHERE lj.user = m.fromuser AND lj.groupid = mg.groupid
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
                 GROUP BY mg.msgid, mg.groupid, m.fromuser",
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

            $result = DB::selectOne(
                'SELECT ST_Area(ST_Intersection(iso, grp)) / NULLIF(ST_Area(grp), 0) AS frac,
                        ST_AsText(ST_Union(iso, grp)) AS u
                 FROM (SELECT ST_GeomFromText(?, ' . self::SRID . ') AS iso,
                              ST_GeomFromText(?, ' . self::SRID . ') AS grp) t',
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
                    'SELECT ST_Area(ST_Intersection(iso, grp)) / NULLIF(ST_Area(grp), 0) AS frac,
                            ST_AsText(ST_Union(iso, grp)) AS u
                     FROM (SELECT ST_Buffer(ST_GeomFromText(?, ' . self::SRID . '), 0) AS iso,
                                  ST_Buffer(ST_GeomFromText(?, ' . self::SRID . '), 0) AS grp) t',
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
        foreach ($gids as $gid) {
            DB::statement(
                'UPDATE rippling_reach mr JOIN `groups` g ON g.id = ?
                 SET mr.polygon = ST_Difference(mr.polygon, g.polyindex)
                 WHERE mr.msgid = ? AND g.polyindex IS NOT NULL
                   AND ST_GeometryType(g.polyindex) <> \'POINT\'
                   AND ST_Intersects(mr.polygon, g.polyindex)
                   AND NOT ST_Within(mr.polygon, g.polyindex)',
                [(int) $gid, $msgid]
            );
        }
    }

    /**
     * Blur a poster's origin by ~400m (BLUR_USER) before it drives the reach polygon, so the
     * reach is no more precise than the location Freegle exposes elsewhere. Same algorithm and
     * geodesic engine (App\Support\GreatCircle) as iznik-server Utils::blur / Go utils.Blur:
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
        ]);
    }
}
