<?php

namespace App\Console\Commands\AI;

use App\Services\AiImageMatcherService;
use App\Services\ItemCleanerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Bring the pictures we already have onto sharing by item. Each item's best-known picture (the
 * one on most posts) becomes the item's picture; any other picture of the same item is retired:
 * its posts get the item's picture, its wording becomes an alias, and its row goes.
 *
 * Works through the used pictures, most-used first, a few hundred names a run, and remembers how
 * far it got. Once through, a run costs one count. docs/developers/reference/ai-item-images.md.
 */
#[AsCommand(name: 'ai:images:backfill-items')]
class BackfillAIImageItemsCommand extends Command
{
    protected $signature = 'ai:images:backfill-items
                            {--limit=500 : Names to clean this run}
                            {--dry-run : Count what would be retired, change nothing}';

    protected $description = 'Share existing AI item pictures by item, retiring duplicates';

    private const OFFSET_KEY = 'ai.images_item_backfill_offset';

    public function handle(AiImageMatcherService $matcher, ItemCleanerService $cleaner): int
    {
        if (! $cleaner->isConfigured()) {
            $this->warn('No Gemini key: nothing to do.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $offset = $dryRun ? 0 : (int) (DB::table('config')->where('key', self::OFFSET_KEY)->value('value') ?? 0);
        $limit = max(1, (int) $this->option('limit'));
        $seen = 0;
        $retired = 0;

        while ($seen < $limit) {
            $chunk = min(ItemCleanerService::BATCH, $limit - $seen);
            $done = $matcher->backfill($offset, $chunk, $dryRun);
            if ($done['seen'] === 0) {
                // Finished, or the cleaner failed: either way stop, and keep the offset so a
                // failed chunk is retried next run rather than skipped.
                break;
            }
            $seen += $done['seen'];
            $retired += $done['retired'];
            $offset += $done['kept'];
            if (! $dryRun) {
                DB::table('config')->upsert(['key' => self::OFFSET_KEY, 'value' => (string) $offset], ['key'], ['value']);
            }
        }

        $this->info(($dryRun ? 'Dry run: ' : '')."Looked at {$seen} pictures, retired {$retired}, {$offset} kept so far.");

        return self::SUCCESS;
    }
}
