<?php

namespace App\Console\Commands\Items;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recompute items.popularity from messages_items.
 *
 * popularity is meant to be the number of messages that have used an item, but the
 * increment was missing from ItemService::linkToMessage(), so the column froze at whatever
 * an old backfill left behind. On live that is 2,421 of 3,605,071 rows carrying any value,
 * summing to ~47,000 against millions of posts.
 *
 * The forward fix is in ItemService. This puts the history back, so the column is right now
 * rather than only from the day the fix ships.
 *
 * Idempotent: it sets popularity to the counted value rather than adding to it, so it is
 * safe to re-run and safe to run while the forward increment is live (a row touched
 * mid-pass is corrected on the next pass, and the drift is one post).
 *
 * Walks the id space in chunks so nothing takes a long lock. messages_items is large, so
 * the count comes from one grouped query per chunk rather than a correlated subquery per
 * row.
 *
 *   php artisan items:backfill-popularity --dry-run
 *   php artisan items:backfill-popularity --chunk=5000
 */
class BackfillItemPopularityCommand extends Command
{
    protected $signature = 'items:backfill-popularity
                            {--chunk=5000 : Item ids to process per pass}
                            {--limit=0    : Stop after this many items (0 = all)}
                            {--dry-run    : Report what would change without writing}';

    protected $description = 'Recompute items.popularity from messages_items';

    public function handle(): int
    {
        $chunk  = max(1, (int) $this->option('chunk'));
        $limit  = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');

        $maxId = (int) DB::table('items')->max('id');
        if ($maxId === 0) {
            $this->info('No items.');
            return Command::SUCCESS;
        }

        if ($dryRun) {
            $this->warn('[DRY RUN] nothing will be written');
        }

        $this->info("Backfilling items.popularity up to id {$maxId}, chunk {$chunk}");

        $seen = 0;
        $changed = 0;
        $totalPopularity = 0;

        for ($start = 0; $start <= $maxId; $start += $chunk) {
            $end = $start + $chunk;

            // Current values for the window, so we only write rows that actually differ.
            $current = DB::table('items')
                ->where('id', '>', $start)
                ->where('id', '<=', $end)
                ->pluck('popularity', 'id');

            if ($current->isEmpty()) {
                continue;
            }

            $counts = DB::table('messages_items')
                ->select('itemid', DB::raw('COUNT(*) AS n'))
                ->where('itemid', '>', $start)
                ->where('itemid', '<=', $end)
                ->groupBy('itemid')
                ->pluck('n', 'itemid');

            foreach ($current as $id => $popularity) {
                $seen++;
                $actual = (int) ($counts[$id] ?? 0);
                $totalPopularity += $actual;

                if ((int) $popularity === $actual) {
                    continue;
                }

                $changed++;
                if (!$dryRun) {
                    DB::table('items')->where('id', $id)->update(['popularity' => $actual]);
                }
            }

            if ($limit > 0 && $seen >= $limit) {
                $this->warn("Stopped at --limit={$limit}; {$maxId} is the full id range.");
                break;
            }
        }

        $verb = $dryRun ? 'would change' : 'changed';
        $this->info("Examined {$seen} items, {$verb} {$changed}, total popularity {$totalPopularity}");

        return Command::SUCCESS;
    }
}
