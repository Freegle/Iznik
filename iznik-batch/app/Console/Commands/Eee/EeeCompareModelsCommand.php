<?php

namespace App\Console\Commands\Eee;

use App\Services\EeeClassificationService;
use App\Services\EeeSqliteService;
use App\Services\EeeVisionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Run the same sample through multiple models and report inter-model agreement.
 *
 * Workflow:
 *   1. php artisan eee:classify-item-types --limit=200   (reference run with Claude)
 *   2. php artisan eee:compare-models --sample=200       (compare all configured models)
 *   3. Review the agreement report, pick the production model.
 *
 * Only processes images that already have a Claude reference classification so
 * comparisons are apples-to-apples.
 */
class EeeCompareModelsCommand extends Command
{
    protected $signature = 'eee:compare-models
                            {--sample=200  : Number of messages to compare across models}
                            {--models=     : Comma-separated list of models to compare (default: all configured)}
                            {--force       : Re-classify messages already classified by a model}
                            {--report=     : Path to write agreement report CSV (default: stdout table)}';

    protected $description = 'Run a sample of messages through multiple models and report inter-model agreement';

    public function __construct(
        protected EeeClassificationService $classifier,
        protected EeeVisionService $vision,
        protected EeeSqliteService $sqlite,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $sample = (int) $this->option('sample');
        $force  = (bool) $this->option('force');

        $referenceModel = 'claude';

        // Determine which models to run.
        $modelsOpt = $this->option('models');
        if ($modelsOpt) {
            $models = array_map('trim', explode(',', $modelsOpt));
        } else {
            $models = $this->getConfiguredModels();
        }

        // Remove reference model from comparison set — it's the baseline.
        $compareModels = array_values(array_filter($models, fn($m) => $m !== $referenceModel));

        $this->info("EEE model comparison | reference: {$referenceModel} | comparing: " . implode(', ', $compareModels));
        $this->info("Sample size: {$sample}");

        // Pull a sample of message IDs that the reference model has classified.
        $referenceIds = $this->sqlite->getMessageidsForModel($referenceModel, $sample);

        if (empty($referenceIds)) {
            $this->error("No reference classifications found. Run 'eee:classify-item-types' first.");
            return Command::FAILURE;
        }

        $this->info(count($referenceIds) . ' reference messages found.');

        // Run each comparison model over the same messages.
        foreach ($compareModels as $model) {
            if (!$this->vision->withDriver($model)->isConfigured()) {
                $this->warn("Model '{$model}' is not configured — skipping.");
                continue;
            }

            $this->info("Running {$model}…");
            $runId = $this->sqlite->startRun(
                $this->vision->withDriver($model)->getModelName(),
                $this->vision->getPromptVersion(),
                "compare_{$model}_sample_{$sample}",
            );

            $processed = 0;
            $cost      = 0.0;

            foreach ($referenceIds as $messageid) {
                if (!$force && $this->sqlite->hasClassification($messageid, $this->vision->withDriver($model)->getModelName())) {
                    continue;
                }

                $result = $this->classifier->classifyMessageWithDriver($messageid, $model);
                if ($result) {
                    $processed++;
                    $cost += $result['cost_usd'] ?? 0.0;
                }
            }

            $this->sqlite->finishRun($runId, $processed, 0, 0, $cost);
            $this->line("  {$model}: {$processed} classified, \$" . number_format($cost, 4));
        }

        // Build and display the agreement report.
        $this->newLine();
        $this->info('=== Inter-model Agreement Report ===');
        $this->outputAgreementReport($referenceModel, $compareModels, $referenceIds);

        return Command::SUCCESS;
    }

    protected function getConfiguredModels(): array
    {
        $all = ['claude', 'gemini', 'openai', 'together', 'ollama'];
        return array_values(array_filter($all, fn($m) => $this->vision->withDriver($m)->isConfigured()));
    }

    protected function outputAgreementReport(string $referenceModel, array $compareModels, array $messageIds): void
    {
        $refModelName = $this->vision->withDriver($referenceModel)->getModelName();

        $rows = [];
        foreach ($messageIds as $messageid) {
            $all = $this->sqlite->getClassificationsForMessage($messageid);
            if (!isset($all[$refModelName])) {
                continue;
            }
            $refRow = $all[$refModelName];

            foreach ($compareModels as $model) {
                $modelName = $this->vision->withDriver($model)->getModelName();
                if (!isset($all[$modelName])) {
                    continue;
                }
                $cmpRow = $all[$modelName];
                $rows[] = [
                    'messageid'         => $messageid,
                    'model'             => $model,
                    'ref_is_eee'        => $refRow['is_eee'],
                    'cmp_is_eee'        => $cmpRow['is_eee'],
                    'is_eee_agree'      => (int) ($refRow['is_eee'] === $cmpRow['is_eee']),
                    'ref_weee'          => $refRow['weee_category'],
                    'cmp_weee'          => $cmpRow['weee_category'],
                    'weee_agree'        => (int) ($refRow['weee_category'] === $cmpRow['weee_category']),
                    'ref_confidence'    => $refRow['is_eee_confidence'],
                    'cmp_confidence'    => $cmpRow['is_eee_confidence'],
                ];
            }
        }

        if (empty($rows)) {
            $this->warn('No comparison data found. Run the comparison first.');
            return;
        }

        // Aggregate per model.
        $stats = [];
        foreach ($rows as $row) {
            $m = $row['model'];
            if (!isset($stats[$m])) {
                $stats[$m] = ['total' => 0, 'eee_agree' => 0, 'weee_agree' => 0];
            }
            $stats[$m]['total']++;
            $stats[$m]['eee_agree']  += $row['is_eee_agree'];
            $stats[$m]['weee_agree'] += $row['weee_agree'];
        }

        $tableRows = [];
        foreach ($stats as $model => $s) {
            $tableRows[] = [
                $model,
                $s['total'],
                number_format(100 * $s['eee_agree'] / $s['total'], 1) . '%',
                number_format(100 * $s['weee_agree'] / $s['total'], 1) . '%',
            ];
        }

        $this->table(
            ['Model', 'Messages', 'EEE agree w/ Claude', 'WEEE category agree'],
            $tableRows,
        );

        // Optional CSV dump.
        $reportPath = $this->option('report');
        if ($reportPath) {
            $fp = fopen($reportPath, 'w');
            fputcsv($fp, array_keys($rows[0]));
            foreach ($rows as $row) {
                fputcsv($fp, $row);
            }
            fclose($fp);
            $this->info("Full report written to {$reportPath}");
        }
    }
}
