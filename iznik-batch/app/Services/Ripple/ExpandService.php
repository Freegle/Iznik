<?php

namespace App\Services\Ripple;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The rippling-out reach engine.
 *
 * Maintains one messages_reach row per active post (the subset of
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
            'removed' => 0, 'skipped' => 0, 'errors' => 0,
        ];

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
        $count = (int) DB::table('messages_reach as mr')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('messages_spatial as ms')
                    ->whereColumn('ms.msgid', 'mr.msgid');
            })->count();

        if (!$dryRun && $count > 0) {
            DB::statement(
                'DELETE mr FROM messages_reach mr
                 LEFT JOIN messages_spatial ms ON ms.msgid = mr.msgid
                 WHERE ms.msgid IS NULL'
            );
        }

        return $count;
    }

    private function initialiseNew(bool $dryRun, int $limit, array &$stats): void
    {
        $rows = DB::select(
            'SELECT ms.msgid AS msgid,
                    ANY_VALUE(ST_Y(ms.point)) AS lat,
                    ANY_VALUE(ST_X(ms.point)) AS lng,
                    MIN(ms.arrival) AS arrival
             FROM messages_spatial ms
             LEFT JOIN messages_reach mr ON mr.msgid = ms.msgid
             WHERE mr.msgid IS NULL
             GROUP BY ms.msgid
             LIMIT ?',
            [$limit]
        );

        foreach ($rows as $row) {
            try {
                $schedule = $this->reach->computeSchedule((float) $row->lat, (float) $row->lng);
                if ($schedule === null) {
                    // Routing unreachable or origin off-graph — retry next run.
                    $stats['skipped']++;
                    continue;
                }

                $arrival = Carbon::parse($row->arrival);
                $total = count($schedule['ticks']);

                // Start at the tick appropriate for how long the post has already
                // been live (so back-filled posts get their correct reach at once,
                // not the tiny initial one).
                $elapsedHours = $arrival->diffInMinutes(now()) / 60.0;
                $tick = min($this->reach->tickForElapsedHours($elapsedHours), $total);
                $entry = $schedule['ticks'][$tick - 1];
                $next = $this->reach->nextExpansionAfter($arrival, $tick);
                $status = $next === null ? 'done' : 'expanding';

                if (!$dryRun) {
                    DB::statement(
                        'INSERT INTO messages_reach
                           (msgid, lat, lng, polygon, arrival, mode, tick, total_ticks,
                            total_freeglers, max_drive_min, schedule, next_expansion_at, status,
                            created_at, updated_at)
                         VALUES (?, ?, ?, ST_GeomFromText(?, ' . self::SRID . '), ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                        [
                            $row->msgid, $row->lat, $row->lng, $entry['wkt'], $arrival,
                            $this->reach->mode(), $tick, $total,
                            $schedule['total_freeglers'], $schedule['max_drive_min'],
                            json_encode($schedule['ticks']), $next, $status,
                        ]
                    );
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
        $rows = DB::table('messages_reach')
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

                $arrival = Carbon::parse($row->arrival);
                $elapsedHours = $arrival->diffInMinutes(now()) / 60.0;
                $total = min((int) $row->total_ticks, count($ticks));
                $target = min($this->reach->tickForElapsedHours($elapsedHours), $total);

                if ($target <= (int) $row->tick) {
                    // Not actually due for a new tick yet — reschedule and move on.
                    if (!$dryRun) {
                        $next = $this->reach->nextExpansionAfter($arrival, (int) $row->tick);
                        DB::table('messages_reach')->where('msgid', $row->msgid)->update([
                            'next_expansion_at' => $next,
                            'status' => $next === null ? 'done' : 'expanding',
                            'updated_at' => now(),
                        ]);
                    }
                    $stats['skipped']++;
                    continue;
                }

                $entry = $ticks[$target - 1];
                $next = $this->reach->nextExpansionAfter($arrival, $target);
                $status = $next === null ? 'done' : 'expanding';

                if (!$dryRun) {
                    DB::statement(
                        'UPDATE messages_reach
                         SET polygon = ST_GeomFromText(?, ' . self::SRID . '),
                             tick = ?, next_expansion_at = ?, status = ?, updated_at = NOW()
                         WHERE msgid = ?',
                        [$entry['wkt'], $target, $next, $status, $row->msgid]
                    );
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
