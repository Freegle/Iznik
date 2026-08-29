<?php

namespace App\Console\Commands\Eee;

use App\Services\EeeClassificationService;
use App\Services\EeeComponentService;
use App\Services\EeeProductionStore;
use App\Services\EeeSqliteService;
use App\Services\EeeVisionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
        protected EeeComponentService $components,
    ) {
        parent::__construct();
    }

    /**
     * The clock a post joins the pipeline on: when a moderator approved it, or when it
     * arrived for posts that were approved automatically (those have no approvedat).
     * Arrival alone is wrong twice over — it admits posts no moderator has passed yet,
     * and it loses posts that were held and approved after the mark moved past them.
     */
    protected const APPROVAL_CLOCK = 'COALESCE(messages_groups.approvedat, messages_groups.arrival)';

    public function handle(): int
    {
        $limit  = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');

        if (!$dryRun && !$this->vision->isConfigured()) {
            $this->error('Vision service not configured.');
            return Command::FAILURE;
        }

        // Without the component index every verdict would be stored as NULL (components
        // observed, nothing to decide them against) and excluded from every statistic.
        // Refusing to run leaves the high-water mark where it is, so the same posts are
        // picked up once the index exists — instead of being spent on and lost for ever.
        if (!$dryRun && $this->components->needsBuilding()) {
            $this->error('Component index is empty — run eee:build-component-index first.');
            return Command::FAILURE;
        }

        $since = $this->option('since') ?: $this->getHighWaterMark();
        $this->info("EEE classify-new | since: {$since} | limit: {$limit}");

        if ($dryRun) {
            $this->warn('[DRY RUN]');
        }

        // Approved posts only. This service sends the photo and text to an external API,
        // so nothing a moderator has not passed may enter it: selecting on messages.arrival
        // alone classified held, spam and rejected posts within the hour of posting.
        //
        // >= rather than >, because many posts can share one clock value (a bulk approval)
        // and a capped run can stop mid-tie; the NOT EXISTS makes re-scanning the boundary
        // value free of re-work, so the tied remainder is picked up next run.
        $clock = self::APPROVAL_CLOCK;

        $ids = DB::table('messages')
            ->select('messages.id')
            ->join('messages_groups', 'messages_groups.msgid', '=', 'messages.id')
            ->where('messages.type', 'Offer')
            ->whereNull('messages.deleted')
            ->where('messages_groups.collection', 'Approved')
            ->where('messages_groups.deleted', 0)
            // keep-raw: COALESCE over two columns; the builder has no expression form.
            ->whereRaw("$clock >= ?", [$since])
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('messages_eee')
                  ->whereColumn('messages_eee.msgid', 'messages.id')
                  ->where('messages_eee.model', $this->vision->getModelName())
                  ->where('messages_eee.prompt_version', $this->vision->getPromptVersion());
            })
            ->groupBy('messages.id')
            // keep-raw: aggregate over the COALESCE clock; not expressible in the builder.
            ->orderByRaw("MIN($clock)")
            ->limit($limit)
            ->pluck('messages.id');

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
        $failures  = 0;
        $cost      = 0.0;

        foreach ($ids as $messageid) {
            try {
                $result = $this->classifier->classifyMessage($messageid);
            } catch (\Throwable $e) {
                // One failing message must not stall everything behind it. It stores no
                // row, so the NOT EXISTS above retries it on the next run.
                Log::error('eee:classify-new failed for message', ['msgid' => $messageid, 'error' => $e->getMessage()]);
                $failures++;
                continue;
            }

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

        if ($failures > 0) {
            $this->warn("{$failures} messages failed and will be retried next run — see the log.");
        }

        return ($processed === 0 && $failures > 0) ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Where to resume from.
     *
     * Reads production MySQL, not the dev SQLite file: this command runs from the
     * scheduler on the production batch host, which has no such file, and pointing a
     * production job at a dev artefact would silently reclassify everything every run.
     *
     * The mark is the newest approval clock already classified under this model and
     * prompt, not the newest run time. A run takes minutes, and anything approved during
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
