<?php

namespace App\Services\FirstReply;

use Illuminate\Support\Facades\DB;

/**
 * Daily counters for the first-reply work.
 *
 * Same shape as rippling_event_metrics so the sysadmin dashboards read both the
 * same way. Deliberately counters rather than rows: the question these answer is
 * "is this doing anything, and is it doing more or less of it than last week",
 * which does not need per-event detail. Who was scouted and which prompts were
 * sent are already recorded properly in firstreply_scouts and
 * firstreply_prompts_sent.
 *
 * Every write is best-effort. Instrumentation that can break the thing it is
 * measuring is worse than no instrumentation.
 */
class Metrics
{
    public function record(string $event, int $count = 1): void
    {
        if ($count <= 0) {
            return;
        }

        // The inner catch is not belt-and-braces: two workers can both find no
        // row and both insert, and the loser must still land its count rather
        // than lose it to a duplicate key. By then the row exists.
        $today = now()->toDateString();

        try {
            $updated = DB::table('firstreply_event_metrics')
                ->where('day', $today)
                ->where('event', $event)
                ->increment('count', $count);

            if ($updated === 0) {
                try {
                    DB::table('firstreply_event_metrics')->insert([
                        'day' => $today,
                        'event' => $event,
                        'count' => $count,
                    ]);
                } catch (\Throwable) {
                    DB::table('firstreply_event_metrics')
                        ->where('day', $today)
                        ->where('event', $event)
                        ->increment('count', $count);
                }
            }
        } catch (\Throwable) {
            // Deliberately silent. See the class comment.
        }
    }
}
