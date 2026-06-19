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
    public function process(bool $dryRun = false, int $limit = 500): array
    {
        $stats = [
            'initialized' => 0, 'expanded' => 0, 'completed' => 0,
            'removed' => 0, 'skipped' => 0, 'errors' => 0, 'rippled_in' => 0,
        ];

        // Master activation switch. While rippling is disabled, do nothing: no reach is computed and
        // nothing is rippled into new groups. The cron is also unscheduled when off (routes/console.php),
        // so this is defence-in-depth that also covers a manual `artisan ripple:expand`.
        if (!config('freegle.ripple.enabled')) {
            return $stats;
        }

        // 1. Drop reach for posts that have left the browsable set (taken/withdrawn).
        $stats['removed'] = $this->removeStale($dryRun);

        // 2. Initialise reach for posts new to messages_spatial.
        $this->initialiseNew($dryRun, $limit, $stats);

        // 3. Advance reach for posts whose next tick is due — active hours only.
        if ($this->inActiveHours()) {
            $this->advanceDue($dryRun, $limit, $stats);
        }

        return $stats;
    }

    private function removeStale(bool $dryRun): int
    {
        if ($dryRun) {
            return (int) DB::table('rippling_reach as mr')
                ->whereNotExists(function ($q) {
                    $q->select(DB::raw(1))
                        ->from('messages_spatial as ms')
                        ->whereColumn('ms.msgid', 'mr.msgid');
                })->count();
        }

        // Single DELETE then ROW_COUNT() so the reported figure is exactly what was
        // deleted (a separate COUNT then DELETE can drift — messages:update-spatial-index
        // mutates messages_spatial concurrently).
        DB::statement(
            'DELETE mr FROM rippling_reach mr
             LEFT JOIN messages_spatial ms ON ms.msgid = mr.msgid
             WHERE ms.msgid IS NULL'
        );

        return (int) (DB::selectOne('SELECT ROW_COUNT() AS n')->n ?? 0);
    }

    private function initialiseNew(bool $dryRun, int $limit, array &$stats): void
    {
        $rows = DB::select(
            'SELECT ms.msgid AS msgid,
                    ANY_VALUE(ST_Y(ms.point)) AS lat,
                    ANY_VALUE(ST_X(ms.point)) AS lng,
                    MIN(ms.arrival) AS arrival
             FROM messages_spatial ms
             LEFT JOIN rippling_reach mr ON mr.msgid = ms.msgid
             WHERE mr.msgid IS NULL
             GROUP BY ms.msgid
             LIMIT ?',
            [$limit]
        );

        foreach ($rows as $row) {
            try {
                if ($row->arrival === null) {
                    // Without arrival we cannot place the post on its hazard schedule.
                    Log::warning("ripple: null arrival for msg {$row->msgid}, skipping");
                    $stats['skipped']++;
                    continue;
                }

                // Blur the poster's origin (~400m, BLUR_USER) before computing the reach, so
                // the reach polygon and its stored centre are no more precise than the
                // location Freegle already exposes elsewhere (the Go API blurs displayed post
                // locations identically). Avoids the reach polygon becoming a precise
                // location oracle for the poster (#privacy). Deterministic per location.
                [$lat, $lng] = $this->blurOrigin((float) $row->lat, (float) $row->lng);

                $schedule = $this->reach->computeSchedule($lat, $lng);
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
                    DB::statement(
                        'INSERT INTO rippling_reach
                           (msgid, lat, lng, polygon, arrival, mode, tick, total_ticks,
                            total_freeglers, max_drive_min, schedule, next_expansion_at, status,
                            created_at, updated_at)
                         VALUES (?, ?, ?, ST_GeomFromText(?, ' . self::SRID . '), ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                        [
                            $row->msgid, $lat, $lng, $entry['wkt'], $arrival,
                            $this->reach->mode(), $tick, $total,
                            $schedule['total_freeglers'], $schedule['max_drive_min'],
                            json_encode($schedule['ticks']), $next, $status,
                        ]
                    );
                    $this->rippleIntoNewGroups((int) $row->msgid, $entry['wkt'], $stats);
                }

                $stats['initialized']++;
                $this->logEvent($row->msgid, 'init', $tick, $entry);
            } catch (\Throwable $e) {
                $stats['errors']++;
                Log::warning("ripple: init failed for msg {$row->msgid}: {$e->getMessage()}");
            }
        }
    }

    private function advanceDue(bool $dryRun, int $limit, array &$stats): void
    {
        $rows = DB::table('rippling_reach')
            ->where('status', 'expanding')
            ->whereNotNull('next_expansion_at')
            ->where('next_expansion_at', '<=', now())
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
                    DB::statement(
                        'UPDATE rippling_reach
                         SET polygon = ST_GeomFromText(?, ' . self::SRID . '),
                             tick = ?, next_expansion_at = ?, status = ?, updated_at = NOW()
                         WHERE msgid = ?',
                        [$entry['wkt'], $target, $next, $status, $row->msgid]
                    );
                    $this->rippleIntoNewGroups((int) $row->msgid, $entry['wkt'], $stats);
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
     * Inserts a fresh-Pending messages_groups row (collection forced to 'Pending', so the
     * existing moderation pipeline — ContentCheck/AutoApprove/visibility — treats it as a
     * new arrival), idempotently (INSERT IGNORE + NOT EXISTS on the existing (msgid,groupid)
     * rows, so the origin group and already-rippled groups are never touched or duplicated).
     */
    private function rippleIntoNewGroups(int $msgid, string $reachWkt, array &$stats): void
    {
        try {
            $n = DB::affectingStatement(
                "INSERT IGNORE INTO messages_groups (msgid, groupid, collection, arrival, autoreposts, msgtype, rippled_in)
                 SELECT ?, g.id, 'Pending', NOW(), 0, m.type, 1
                 FROM `groups` g
                 CROSS JOIN messages m
                 WHERE m.id = ?
                   AND g.publish = 1
                   AND g.type = 'Freegle'
                   AND g.onhere = 1
                   AND g.polyindex IS NOT NULL
                   AND ST_GeometryType(g.polyindex) <> 'POINT'
                   AND ST_Intersects(g.polyindex, ST_GeomFromText(?, " . self::SRID . "))
                   AND NOT EXISTS (
                       SELECT 1 FROM messages_groups mg WHERE mg.msgid = ? AND mg.groupid = g.id
                   )",
                [$msgid, $msgid, $reachWkt, $msgid]
            );
            if ($n > 0) {
                $stats['rippled_in'] += $n;
            }
        } catch (\Throwable $e) {
            $stats['errors']++;
            Log::warning("ripple: ripple-into-groups failed for msg {$msgid}: {$e->getMessage()}");
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
