<?php

namespace App\Console\Commands\AI;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class UpdateAIImageUsageCountsCommand extends Command
{
    protected $signature = 'ai:usage-counts:update';

    protected $description = 'Update usage_count on ai_images from messages_attachments';

    public function handle(): int
    {
        // Step 1: Precompute all AI attachment counts into a temp table (one scan).
        DB::statement('DROP TEMPORARY TABLE IF EXISTS tmp_ai_usage_counts');
        DB::statement("
            CREATE TEMPORARY TABLE tmp_ai_usage_counts (
                externaluid VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci PRIMARY KEY,
                cnt INT NOT NULL
            ) AS
            SELECT ma.externaluid, COUNT(*) AS cnt
            FROM messages_attachments ma
            WHERE JSON_EXTRACT(ma.externalmods, '$.ai') = TRUE
              AND ma.externaluid IS NOT NULL
              AND ma.externaluid != ''
            GROUP BY ma.externaluid
        ");

        // Step 2: Update one row at a time via JOIN, skipping rows where
        // the count hasn't changed. Single-row updates lock for microseconds
        // and don't interfere with concurrent operations.
        $totalUpdated = 0;
        $totalSkipped = 0;

        try {
            // lazyById() uses cursor-based pagination (WHERE id > lastId) rather
            // than LIMIT/OFFSET, so concurrent INSERT/DELETE on ai_images cannot
            // cause rows to be skipped or seen twice during iteration.
            $rows = DB::table('ai_images')
                ->whereNotNull('externaluid')
                ->where('externaluid', '!=', '')
                ->select('id')
                ->lazyById(500);

            foreach ($rows as $row) {
                $affected = DB::update("
                    UPDATE ai_images ai
                    LEFT JOIN tmp_ai_usage_counts t ON t.externaluid = ai.externaluid
                    SET ai.usage_count = COALESCE(t.cnt, 0)
                    WHERE ai.id = ?
                      AND ai.usage_count != COALESCE(t.cnt, 0)
                ", [$row->id]);

                if ($affected > 0) {
                    $totalUpdated++;
                } else {
                    $totalSkipped++;
                }
            }
        } finally {
            DB::statement('DROP TEMPORARY TABLE IF EXISTS tmp_ai_usage_counts');
        }

        $this->info("Updated {$totalUpdated} AI images, skipped {$totalSkipped} unchanged.");

        return Command::SUCCESS;
    }
}
