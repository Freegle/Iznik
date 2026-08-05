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

        try {
            DB::statement(
                'INSERT INTO firstreply_event_metrics (day, event, count) VALUES (CURDATE(), ?, ?)
                 ON DUPLICATE KEY UPDATE count = count + ?',
                [$event, $count, $count]
            );
        } catch (\Throwable) {
            // Deliberately silent. See the class comment.
        }
    }
}
