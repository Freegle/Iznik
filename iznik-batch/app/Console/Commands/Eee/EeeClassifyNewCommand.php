<?php

namespace App\Console\Commands\Eee;

use App\Services\EeeClassificationService;
use App\Services\EeeProductionStore;
use App\Services\EeeSqliteService;
use App\Services\EeeVisionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Incremental EEE classification — processes messages approved since the last run.
 *
 * Designed to run from the scheduler (e.g. hourly). Tracks the high-water mark
 * via the most recent run_at in eee_classifications.
 *
 *   php artisan eee:classify-new
 *   php artisan eee:classify-new --limit=500
 */
class EeeClassifyNewCommand extends Command
{
    protected $signature = 'eee:classify-new
                            {--limit=1000  : Max new messages to process per run}
                            {--since=      : Override the since datetime (YYYY-MM-DD HH:MM:SS)}
                            {--dry-run     : Show pending count without classifying}';

    protected $description = 'Classify new Freegle messages since the last run (for scheduler)';

    public function __construct(
        protected EeeClassificationService $classifier,
        protected EeeVisionService $vision,
        protected EeeSqliteService $sqlite,
        protected EeeProductionStore $productionStore,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit  = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');

        if (!$dryRun && !$this->vision->isConfigured()) {
            $this->error('Vision service not configured.');
            return Command::FAILURE;
        }

        $since = $this->option('since') ?: $this->getHighWaterMark();
        $this->info("EEE classify-new | since: {$since} | limit: {$limit}");

        if ($dryRun) {
            $this->warn('[DRY RUN]');
        }

        $ids = DB::table('messages')
            ->select('messages.id')
            ->where('messages.type', 'Offer')
            ->where('messages.arrival', '>', $since)
            ->orderBy('messages.arrival')
            ->limit($limit)
            ->pluck('id');

        $this->info(count($ids) . ' new messages to classify.');

        if ($dryRun || $ids->isEmpty()) {
            return Command::SUCCESS;
        }

        $runId = $this->sqlite->startRun(
            $this->vision->getModelName(),
            $this->vision->getPromptVersion(),
            'classify_new',
        );

        $processed = 0;
        $eeeFound  = 0;
        $cost      = 0.0;

        foreach ($ids as $messageid) {
            $result = $this->classifier->classifyMessage($messageid);

            if ($result) {
                $processed++;
                if (!empty($result['is_eee'])) {
                    $eeeFound++;
                }
                $cost += $result['cost_usd'] ?? 0.0;
            }
        }

        $this->sqlite->finishRun($runId, $processed, $eeeFound, 0, $cost);
        $this->info("Done: {$processed} classified, {$eeeFound} EEE, \$" . number_format($cost, 4));

        return Command::SUCCESS;
    }

    /**
     * Where to resume from.
     *
     * Reads production MySQL, not the dev SQLite file: this command runs from the
     * scheduler on the production batch host, which has no such file, and pointing a
     * production job at a dev artefact would silently reclassify everything every run.
     *
     * The mark is the newest messages.arrival already classified under this model and
     * prompt, not the newest run time. A run takes minutes, and anything arriving during
     * it would be skipped for ever if the mark were a wall-clock stamp.
     */
    protected function getHighWaterMark(): string
    {
        $mark = $this->productionStore->highWaterMark(
            $this->vision->getModelName(),
            $this->vision->getPromptVersion(),
        );

        // Default to 24 hours ago if nothing has been classified yet.
        return $mark ?: now()->subDay()->toDateTimeString();
    }
}
