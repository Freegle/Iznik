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

        // Attributes to compare: [column, label, is_numeric_diff (vs exact match)]
        $compareAttrs = [
            ['is_eee',              'EEE (binary)',        false],
            ['weee_category',       'WEEE category',      false],
            ['condition',           'Condition',          false],
            ['value_band_gbp',      'Value band',         false],
            ['item_complete',       'Item complete',      false],
            ['brand',               'Brand',              false],
            ['model_number',        'Model number',       false],
            ['photo_quality',       'Photo quality ±1',   true],
            ['weight_kg_min',       'Weight min ±20%',    true],
        ];

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
                $cmpRow  = $all[$modelName];
                $rowData = ['messageid' => $messageid, 'model' => $model];

                foreach ($compareAttrs as [$col, , $numeric]) {
                    $ref = $refRow[$col] ?? null;
                    $cmp = $cmpRow[$col] ?? null;
                    if ($numeric) {
                        if ($ref === null || $cmp === null) {
                            $agree = null;
                        } elseif ($col === 'photo_quality') {
                            $agree = (int) (abs((float)$ref - (float)$cmp) <= 1);
                        } else {
                            // weight: agree within 20%
                            $avg = ((float)$ref + (float)$cmp) / 2;
                            $agree = $avg > 0 ? (int) (abs((float)$ref - (float)$cmp) / $avg <= 0.20) : (int) ($ref == $cmp);
                        }
                    } else {
                        $agree = ($ref === null && $cmp === null) ? null : (int) ($ref === $cmp);
                    }
                    $rowData["agree_{$col}"] = $agree;
                    $rowData["ref_{$col}"]   = $ref;
                    $rowData["cmp_{$col}"]   = $cmp;
                }

                $rows[] = $rowData;
            }
        }

        if (empty($rows)) {
            $this->warn('No comparison data found. Run the comparison first.');
            return;
        }

        // Aggregate per model per attribute.
        $stats = [];
        foreach ($rows as $row) {
            $m = $row['model'];
            if (!isset($stats[$m])) {
                $stats[$m] = ['total' => 0];
                foreach ($compareAttrs as [$col]) {
                    $stats[$m]["agree_{$col}"] = 0;
                    $stats[$m]["seen_{$col}"]  = 0;
                }
            }
            $stats[$m]['total']++;
            foreach ($compareAttrs as [$col]) {
                $v = $row["agree_{$col}"] ?? null;
                if ($v !== null) {
                    $stats[$m]["agree_{$col}"] += $v;
                    $stats[$m]["seen_{$col}"]++;
                }
            }
        }

        // Print per-attribute agreement table.
        foreach ($compareAttrs as [$col, $label]) {
            $attrRows = [];
            foreach ($stats as $model => $s) {
                $seen = $s["seen_{$col}"];
                $attrRows[] = [
                    $model,
                    $seen,
                    $seen > 0 ? number_format(100 * $s["agree_{$col}"] / $seen, 1) . '%' : 'n/a',
                ];
            }
            $this->line("<comment>Attribute: {$label}</comment>");
            $this->table(['Model', 'Comparable', 'Agreement w/ Claude'], $attrRows);
        }

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
