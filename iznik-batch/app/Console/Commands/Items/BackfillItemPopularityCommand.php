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
 * Walks the rows in keyset chunks so nothing takes a long lock, and so the cost tracks the
 * number of items rather than the size of the id space - stepping through the space by a
 * fixed increment spends a round trip on every empty window, which on a catalogue with
 * 3.6M rows and large gaps is nearly all of them. messages_items is large, so the count
 * comes from one grouped query per chunk rather than a correlated subquery per row.
 *
 *   php artisan items:backfill-popularity --dry-run
 *   php artisan items:backfill-popularity --chunk=5000
 */
class BackfillItemPopularityCommand extends Command
{
    protected $signature = 'items:backfill-popularity
                            {--chunk=5000 : Items to process per pass}
                            {--limit=0    : Stop after this many items (0 = all)}
                            {--dry-run    : Report what would change without writing}';

    protected $description = 'Recompute items.popularity from messages_items';

    public function handle(): int
    {
        $chunk  = max(1, (int) $this->option('chunk'));
        $limit  = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');

        if (!DB::table('items')->exists()) {
            $this->info('No items.');
            return Command::SUCCESS;
        }

        if ($dryRun) {
            $this->warn('[DRY RUN] nothing will be written');
        }

        $this->info("Backfilling items.popularity, chunk {$chunk}");

        $seen = 0;
        $changed = 0;
        $totalPopularity = 0;
        $lastId = 0;

        // Keyset pagination over the rows that exist, not over the id space. Walking the
        // space by a fixed step costs one round trip per step whether or not any item
        // falls in it, so a small chunk on a catalogue with 3.6M rows and large id gaps
        // spends nearly all its time on empty windows.
        while (true) {
            $current = DB::table('items')
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit($chunk)
                ->pluck('popularity', 'id');

            if ($current->isEmpty()) {
                break;
            }

            $ids     = $current->keys()->all();
            $lastId  = (int) end($ids);

            $counts = DB::table('messages_items')
                // keep-raw: aliased COUNT aggregate; the builder has no first-class
                // grouped-count-into-map form.
                ->select('itemid', DB::raw('COUNT(*) AS n'))
                ->whereIn('itemid', $ids)
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
                $this->warn("Stopped at --limit={$limit}; more items remain.");
                break;
            }
        }

        $verb = $dryRun ? 'would change' : 'changed';
        $this->info("Examined {$seen} items, {$verb} {$changed}, total popularity {$totalPopularity}");

        return Command::SUCCESS;
    }
}
