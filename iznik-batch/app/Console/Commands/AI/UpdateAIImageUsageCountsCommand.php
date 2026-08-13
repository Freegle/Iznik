<?php

namespace App\Console\Commands\AI;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Keep ai_images.usage_count in step with how many posts actually use each AI image.
 *
 * This used to do a full recompute every hour: a JSON_EXTRACT scan of all 31.6M rows of
 * messages_attachments into a temp table, then a single-row UPDATE for each of the 50.6k
 * ai_images rows. That is 24 full scans and ~1.2M UPDATE statements a day - built on the
 * cluster's write node, and replicated to every other node - to move a counter whose
 * measured net change is about 8 rows an hour.
 *
 * So the full scan now runs once a night, and the hourly run only looks at attachments
 * added since the last one. That works because the "this is an AI image" flag is written
 * when the attachment row is inserted (MessageIllustrationsService inserts externalmods
 * as {"ai": true}; nothing sets it later), so a new use can never hide behind an id the
 * cursor has already passed.
 *
 * Counts can only go DOWN behind the cursor's back - a message is purged, or an image is
 * rotated (which rewrites externalmods and drops the ai flag). Those are corrected by the
 * nightly full run, so a decrement can lag by up to a day. usage_count is a review aid in
 * the AI image tool, not a number anything acts on, and the same figure was already up to
 * an hour stale.
 */
class UpdateAIImageUsageCountsCommand extends Command
{
    protected $signature = 'ai:usage-counts:update
                            {--full : Recompute every count from a full scan instead of applying the incremental delta}';

    protected $description = 'Update usage_count on ai_images from messages_attachments';

    // Where the incremental run remembers how far it has read. One row in the existing
    // config table, so there is no schema step.
    private const CURSOR_KEY = 'ai.usage_counts_cursor';

    public function handle(): int
    {
        // The highest attachment id that exists right now. Both paths work strictly up to
        // this id and then store it, so a row inserted while we run is left for the next
        // run rather than being counted twice or missed.
        $upTo = (int) DB::table('messages_attachments')->max('id');

        $cursor = $this->readCursor();

        if ($this->option('full') || $cursor === null) {
            if ($cursor === null && !$this->option('full')) {
                $this->info('No cursor stored yet - doing a full recompute to establish one.');
            }

            $this->fullRecompute($upTo);
        } else {
            $this->applyDelta($cursor, $upTo);
        }

        $this->writeCursor($upTo);

        return Command::SUCCESS;
    }

    /**
     * The original hourly job, now the nightly one: rebuild every count from scratch. This
     * is also what corrects any drift the incremental path cannot see (purged messages,
     * rotated images).
     */
    private function fullRecompute(int $upTo): void
    {
        // Step 1: Precompute all AI attachment counts into a temp table (one scan).
        // keep-raw: CREATE TEMPORARY TABLE ... AS SELECT is DDL the query builder does
        // not render. Unchanged from the version that has run hourly for months, bar the
        // id ceiling that pairs the scan with the stored cursor.
        DB::statement('DROP TEMPORARY TABLE IF EXISTS tmp_ai_usage_counts');
        DB::statement("
            CREATE TEMPORARY TABLE tmp_ai_usage_counts (
                externaluid VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci PRIMARY KEY,
                cnt INT NOT NULL
            ) AS
            SELECT ma.externaluid, COUNT(*) AS cnt
            FROM messages_attachments ma
            WHERE ma.id <= ?
              AND JSON_EXTRACT(ma.externalmods, '$.ai') = TRUE
              AND ma.externaluid IS NOT NULL
              AND ma.externaluid != ''
            GROUP BY ma.externaluid
        ", [$upTo]);

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
                // keep-raw: UPDATE ... LEFT JOIN against a temporary table, where the
                // join miss must set the count to 0. Unchanged from the original.
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

        $this->info("Full recompute: updated {$totalUpdated} AI images, skipped {$totalSkipped} unchanged.");
    }

    /**
     * Add the uses that arrived since the last run: a primary-key range over the handful
     * of attachments inserted in the last hour, instead of a scan of all 31.6M rows.
     */
    private function applyDelta(int $cursor, int $upTo): void
    {
        if ($upTo <= $cursor) {
            $this->info('No new attachments since the last run.');

            return;
        }

        $newUses = DB::table('messages_attachments')
            ->where('id', '>', $cursor)
            ->where('id', '<=', $upTo)
            ->whereRaw("JSON_EXTRACT(externalmods, '\$.ai') = TRUE")
            ->whereNotNull('externaluid')
            ->where('externaluid', '!=', '')
            ->groupBy('externaluid')
            ->selectRaw('externaluid, COUNT(*) AS cnt')
            ->get();

        $updated = 0;

        foreach ($newUses as $use) {
            // Resolve to ids and increment one row at a time: a multi-row UPDATE is a
            // certification risk on Galera, and nothing guarantees an externaluid appears
            // in ai_images only once.
            $ids = DB::table('ai_images')->where('externaluid', $use->externaluid)->pluck('id');

            foreach ($ids as $id) {
                DB::table('ai_images')->where('id', $id)->increment('usage_count', (int) $use->cnt);
                $updated++;
            }
        }

        $this->info("Incremental: {$updated} AI images gained uses from attachments " . ($cursor + 1) . "-{$upTo}.");
    }

    private function readCursor(): ?int
    {
        $value = DB::table('config')->where('key', self::CURSOR_KEY)->value('value');

        return $value === null || $value === '' ? null : (int) $value;
    }

    private function writeCursor(int $upTo): void
    {
        DB::table('config')->upsert(
            [['key' => self::CURSOR_KEY, 'value' => (string) $upTo]],
            ['key'],
            ['value'],
        );
    }
}
