<?php

namespace App\Console\Commands\Eee;

use App\Services\EeeClassificationService;
use App\Services\EeeSqliteService;
use App\Services\EeeVisionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Build the item-type lookup cache by sampling images per type.
 *
 * Run this before backfilling historical messages. Classifying the top 200
 * item types covers ~80% of posts using only ~2,000 API calls.
 *
 *   php artisan eee:classify-item-types --limit=200
 *   php artisan eee:classify-item-types --limit=500 --force
 *   php artisan eee:classify-item-types --limit=5 --dry-run
 */
class EeeClassifyItemTypesCommand extends Command
{
    protected $signature = 'eee:classify-item-types
                            {--limit=200 : Number of top item types to process}
                            {--force     : Re-classify already-classified types}
                            {--dry-run   : Show pending types without making API calls}';

    protected $description = 'Build item-type EEE lookup cache by sampling images (run before backfill)';

    public function __construct(
        protected EeeClassificationService $classifier,
        protected EeeVisionService $vision,
        protected EeeSqliteService $sqlite,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit  = (int)  $this->option('limit');
        $force  = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');

        if (!$dryRun && !$this->vision->isConfigured()) {
            $this->error('Vision service not configured. Check EEE_MODEL and API keys.');
            return Command::FAILURE;
        }

        $this->info("EEE item-type classification | model: {$this->vision->getModelName()} | limit: {$limit}");

        if ($dryRun) {
            $this->warn('[DRY RUN]');
            $items = DB::table('items')->orderByDesc('popularity')->limit($limit)->pluck('popularity', 'name')->toArray();
            $pending = $force ? array_keys($items) : $this->sqlite->getUnclassifiedItemTypeNames(array_keys($items));
            $this->info(count($pending) . ' item types would be classified:');
            foreach (array_slice($pending, 0, 20) as $name) {
                $this->line("  {$name} (popularity: {$items[$name]})");
            }
            if (count($pending) > 20) $this->line('  ... and ' . (count($pending) - 20) . ' more');
            return Command::SUCCESS;
        }

        $runId = $this->sqlite->startRun(
            $this->vision->getModelName(),
            $this->vision->getPromptVersion(),
            "item_types_limit_{$limit}",
        );

        $stats = $this->classifier->classifyItemTypes($limit, $force, function (string $itemName, ?array $result) {
            if ($result === null) {
                $this->line("  <comment>skip</comment>  {$itemName} (no images)");
            } else {
                $eeeLabel = $result['is_eee'] ? '<info>EEE</info>    ' : '<fg=gray>non-EEE</fg=gray>';
                $cost     = '$' . number_format($result['cost'], 5);
                $this->line("  {$eeeLabel}  {$itemName}  {$cost}");
            }
        });

        $this->sqlite->finishRun($runId, $stats['processed'], $stats['eee'], $stats['skipped'], $stats['cost']);

        $this->newLine();
        $this->table(['Metric', 'Value'], [
            ['Item types processed', $stats['processed']],
            ['EEE item types found', $stats['eee']],
            ['Skipped (no images)',  $stats['skipped']],
            ['Estimated cost',       '$' . number_format($stats['cost'], 4)],
        ]);

        $this->info("Done. Run 'eee:compare-models' or 'eee:backfill' next.");
        return Command::SUCCESS;
    }
}
