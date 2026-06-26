<?php

namespace App\Console\Commands\Eee;

use App\Services\EeeClassificationService;
use App\Services\EeeSqliteService;
use App\Services\EeeVisionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill historical messages with EEE classifications.
 *
 * Processes messages in date order, using the item-type lookup cache where
 * available and falling back to per-image API calls for ambiguous types.
 * Run eee:classify-item-types first to fill the cache.
 *
 *   php artisan eee:backfill --from=2024-05-01 --to=2025-05-01
 *   php artisan eee:backfill --from=2024-05-01 --limit=5000 --dry-run
 */
class EeeBackfillCommand extends Command
{
    protected $signature = 'eee:backfill
                            {--from=       : Start date (YYYY-MM-DD, required)}
                            {--to=         : End date (YYYY-MM-DD, default: today)}
                            {--limit=0     : Max messages to process (0 = no limit)}
                            {--force       : Re-classify already-classified messages}
                            {--dry-run     : Show what would be processed without API calls}
                            {--batch=100   : Messages per DB query batch}';

    protected $description = 'Backfill historical messages with EEE classifications';

    public function __construct(
        protected EeeClassificationService $classifier,
        protected EeeVisionService $vision,
        protected EeeSqliteService $sqlite,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $from   = $this->option('from');
        $to     = $this->option('to') ?: now()->toDateString();
        $limit  = (int) $this->option('limit');
        $force  = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');
        $batch  = (int) $this->option('batch');

        if (!$from) {
            $this->error('--from date is required. Example: --from=2024-05-01');
            return Command::FAILURE;
        }

        if (!$dryRun && !$this->vision->isConfigured()) {
            $this->error('Vision service not configured. Check EEE_MODEL and API keys.');
            return Command::FAILURE;
        }

        $this->info("EEE backfill | from: {$from} | to: {$to} | model: {$this->vision->getModelName()}");

        if ($dryRun) {
            $this->warn('[DRY RUN]');
        }

        $query = DB::table('messages')
            ->select('messages.id')
            ->where('messages.type', 'Offer')
            ->whereBetween('messages.arrival', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->whereNotNull('messages.arrival')
            ->orderBy('messages.arrival');

        $total = $query->count();
        $this->info("Total messages in range: {$total}");

        if ($dryRun) {
            $sample = $query->limit(10)->pluck('id');
            $this->info("Sample message IDs: " . $sample->implode(', '));
            return Command::SUCCESS;
        }

        $runId = $this->sqlite->startRun(
            $this->vision->getModelName(),
            $this->vision->getPromptVersion(),
            "backfill_{$from}_{$to}",
        );

        $processed = 0;
        $eeeFound  = 0;
        $skipped   = 0;
        $cost      = 0.0;
        $offset    = 0;

        $bar = $this->output->createProgressBar($limit > 0 ? min($total, $limit) : $total);
        $bar->start();

        do {
            $batchQuery = clone $query;
            $ids = $batchQuery->offset($offset)->limit($batch)->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            foreach ($ids as $messageid) {
                if ($limit > 0 && $processed + $skipped >= $limit) {
                    break 2;
                }

                if (!$force && $this->sqlite->hasClassification($messageid, $this->vision->getModelName())) {
                    $skipped++;
                    $bar->advance();
                    continue;
                }

                $result = $this->classifier->classifyMessage($messageid);

                if ($result) {
                    $processed++;
                    if (!empty($result['is_eee'])) {
                        $eeeFound++;
                    }
                    $cost += $result['cost_usd'] ?? 0.0;
                } else {
                    $skipped++;
                }

                $bar->advance();
            }

            $offset += $batch;
        } while ($ids->count() === $batch);

        $bar->finish();
        $this->newLine();

        $this->sqlite->finishRun($runId, $processed, $eeeFound, 0, $cost);

        $this->table(['Metric', 'Value'], [
            ['Messages processed',   $processed],
            ['EEE found',            $eeeFound],
            ['Skipped (existing)',   $skipped],
            ['Estimated cost',       '$' . number_format($cost, 4)],
        ]);

        return Command::SUCCESS;
    }
}
