<?php

namespace App\Console\Commands\Eee;

use App\Services\EeeComponentService;
use App\Services\EeeSqliteService;
use App\Services\EeeVisionService;
use Illuminate\Console\Command;

/**
 * Compute objective quality scores for each model in eee_classifications.
 *
 * Scores are computed without assuming any model is ground truth:
 *
 *   - Self-consistency:  is_eee agrees with is_eee_from_components (when both non-null)
 *   - EEE reliability:   % of EEE items whose extracted components include a primary_eee component
 *   - Cross-model agree: % agreement on key fields vs ALL other models (majority-vote baseline)
 *   - Field completeness: % of expected fields non-null vs median across models
 *
 * Usage:
 *   php artisan eee:score-models
 *   php artisan eee:score-models --prompt-version=1.4.0
 */
class EeeScoreModelsCommand extends Command
{
    protected $signature = 'eee:score-models
                            {--prompt-version= : Only score classifications at this prompt version (default: latest)}
                            {--csv=            : Write per-image detail to CSV path}';

    protected $description = 'Score model quality using self-consistency, reliability, and cross-model agreement metrics';

    // Fields that come from the image API call (excluded if image call failed).
    private const IMAGE_COMPLETENESS_FIELDS = [
        'electrical_components_description',
        'condition',
        'brand',
        'model_number',
        'photo_quality',
        'value_band_gbp',
        'weight_kg_min',
        'material_primary',
        'item_complete',
        'size_cm',
    ];

    // Fields that come from the text API call (always scored).
    private const TEXT_COMPLETENESS_FIELDS = [
        'weee_category',
    ];

    // All completeness fields (for display only).
    private const COMPLETENESS_FIELDS = [
        'electrical_components_description',
        'weee_category',
        'condition',
        'brand',
        'model_number',
        'photo_quality',
        'value_band_gbp',
        'weight_kg_min',
        'material_primary',
        'item_complete',
        'size_cm',
    ];

    // Fields compared for cross-model agreement.
    private const AGREEMENT_FIELDS = [
        'is_eee'       => ['label' => 'EEE',         'type' => 'exact'],
        'weee_category'=> ['label' => 'WEEE cat',    'type' => 'exact'],
        'condition'    => ['label' => 'Condition',   'type' => 'exact'],
        'photo_quality'=> ['label' => 'Photo qual',  'type' => 'plusone'],
        'value_band_gbp'=> ['label' => 'Value band', 'type' => 'adjacent'],
        'item_complete'=> ['label' => 'Complete',    'type' => 'exact'],
        'brand'        => ['label' => 'Brand',       'type' => 'exact'],
    ];

    private const VALUE_BANDS = ['0-20', '20-100', '100-500', '500+'];

    public function __construct(
        protected EeeSqliteService $sqlite,
        protected EeeComponentService $componentService,
        protected EeeVisionService $vision,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $pdo = $this->sqlite->getPdo();

        // Determine prompt version to score.
        $promptVersion = $this->option('prompt-version');
        if (!$promptVersion) {
            $row = $pdo->query("SELECT MAX(prompt_version) as pv FROM eee_classifications")->fetch();
            $promptVersion = $row['pv'] ?? EeeVisionService::PROMPT_VERSION;
        }

        $this->info("Scoring models at prompt version {$promptVersion}");

        // Load all classifications at this prompt version.
        $stmt = $pdo->prepare("
            SELECT c.*, s.item_name
            FROM eee_classifications c
            LEFT JOIN eee_item_type_samples s
                ON s.messageid = c.messageid AND s.attid = c.attid
            WHERE c.prompt_version = ?
            ORDER BY c.messageid, c.attid, c.model
        ");
        $stmt->execute([$promptVersion]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($rows)) {
            $this->error("No classifications found for prompt version {$promptVersion}. Run eee:classify-item-types first.");
            return Command::FAILURE;
        }

        // Group by model.
        $byModel = [];
        foreach ($rows as $row) {
            $byModel[$row['model']][] = $row;
        }

        // Group by image (messageid+attid) for cross-model comparison.
        $byImage = [];
        foreach ($rows as $row) {
            $key = $row['messageid'] . '_' . $row['attid'];
            $byImage[$key][$row['model']] = $row;
        }

        $models = array_keys($byModel);
        $this->info(count($models) . ' models, ' . count($byImage) . ' images');
        $this->newLine();

        // Build component index if needed (for EEE reliability scoring).
        $componentIndexAvailable = !$this->componentService->needsBuilding();

        // ---- Score each model ----
        $scores = [];
        foreach ($byModel as $model => $classifications) {
            $scores[$model] = $this->scoreModel($model, $classifications, $byImage, $componentIndexAvailable);
        }

        // ---- Cross-model agreement (requires all models) ----
        if (count($models) > 1) {
            foreach ($models as $model) {
                $scores[$model]['cross_agree'] = $this->crossModelAgreement($model, $byImage);
            }
        } else {
            foreach ($models as $model) {
                $scores[$model]['cross_agree'] = null;
            }
        }

        // ---- Composite score ----
        foreach ($models as $model) {
            $s = $scores[$model];
            $components = array_filter([
                $s['self_consistency'],
                $s['eee_reliability'],
                $s['cross_agree'],
                $s['field_completeness'],
            ], fn($v) => $v !== null);
            $scores[$model]['composite'] = empty($components) ? null : array_sum($components) / count($components);
        }

        // ---- Display ----
        usort($models, fn($a, $b) => ($scores[$b]['composite'] ?? 0) <=> ($scores[$a]['composite'] ?? 0));

        $this->displayOverviewTable($models, $scores);
        $this->newLine();
        $this->displayAgreementDetails($models, $scores);
        $this->newLine();
        $this->displayFieldBreakdown($models, $scores);

        if ($csv = $this->option('csv')) {
            $this->writeDetailCsv($csv, $rows, $byImage);
        }

        return Command::SUCCESS;
    }

    private function imageCallSucceeded(array $row): bool
    {
        // A row has a successful image call when at least one image-derived field is non-null.
        // Rows with failed image calls (rate limits, errors) have null for all of these.
        return ($row['condition'] !== null && $row['condition'] !== '')
            || ($row['photo_quality'] !== null && $row['photo_quality'] !== '');
    }

    private function scoreModel(string $model, array $rows, array $byImage, bool $componentIndex): array
    {
        $total          = count($rows);
        $imageOkCount   = 0;
        $selfConsist    = ['match' => 0, 'seen' => 0];
        $eeeReliability = ['backed' => 0, 'eee_count' => 0];
        $fieldNull      = array_fill_keys(
            array_merge(self::IMAGE_COMPLETENESS_FIELDS, self::TEXT_COMPLETENESS_FIELDS),
            ['null' => 0, 'total' => 0]
        );

        foreach ($rows as $row) {
            $imageOk = $this->imageCallSucceeded($row);
            if ($imageOk) $imageOkCount++;

            // Self-consistency: is_eee agrees with is_eee_from_components
            $isEee   = $row['is_eee'];
            $ruleEee = $row['is_eee_from_components'];
            if ($isEee !== null && $ruleEee !== null) {
                $selfConsist['seen']++;
                if ((int)$isEee === (int)$ruleEee) {
                    $selfConsist['match']++;
                }
            }

            // EEE reliability: do EEE verdicts have supporting primary_eee components?
            if ((int)($row['is_eee'] ?? 0) === 1) {
                $eeeReliability['eee_count']++;
                $compDesc = $row['electrical_components_description'] ?? null;
                if ($compDesc && $componentIndex) {
                    $components = array_filter(array_map('trim', explode(';', $compDesc)));
                    $verdict = $this->componentService->classifyComponents($components);
                    if ($verdict['is_eee'] === true) {
                        $eeeReliability['backed']++;
                    }
                } elseif ($compDesc) {
                    $eeeReliability['backed']++;
                }
            }

            // Image-derived field completeness — only on rows with a successful image call.
            if ($imageOk) {
                foreach (self::IMAGE_COMPLETENESS_FIELDS as $field) {
                    $fieldNull[$field]['total']++;
                    if ($row[$field] === null || $row[$field] === '') {
                        $fieldNull[$field]['null']++;
                    }
                }
            }

            // Text-derived field completeness — all rows.
            foreach (self::TEXT_COMPLETENESS_FIELDS as $field) {
                $fieldNull[$field]['total']++;
                if ($row[$field] === null || $row[$field] === '') {
                    $fieldNull[$field]['null']++;
                }
            }
        }

        $selfConsistScore = $selfConsist['seen'] > 0
            ? 100.0 * $selfConsist['match'] / $selfConsist['seen']
            : null;

        $eeeReliabilityScore = $eeeReliability['eee_count'] > 0
            ? 100.0 * $eeeReliability['backed'] / $eeeReliability['eee_count']
            : null;

        $imageSuccessRate = $total > 0 ? 100.0 * $imageOkCount / $total : null;

        // Field completeness: average non-null rate across fields (using image-ok rows for image fields).
        $completenessRates = [];
        foreach ($fieldNull as $field => $counts) {
            if ($counts['total'] > 0) {
                $completenessRates[$field] = 100.0 * (1 - $counts['null'] / $counts['total']);
            }
        }
        $fieldCompletenessScore = !empty($completenessRates)
            ? array_sum($completenessRates) / count($completenessRates)
            : null;

        return [
            'total'              => $total,
            'image_ok'           => $imageOkCount,
            'image_success_rate' => $imageSuccessRate,
            'self_consistency'   => $selfConsistScore,
            'eee_reliability'    => $eeeReliabilityScore,
            'eee_count'          => $eeeReliability['eee_count'],
            'field_completeness' => $fieldCompletenessScore,
            'field_rates'        => $completenessRates,
            'cross_agree'        => null,
            'composite'          => null,
        ];
    }

    private function crossModelAgreement(string $targetModel, array $byImage): float
    {
        $imageFields = array_flip(self::IMAGE_COMPLETENESS_FIELDS);
        $agreements  = [];

        foreach (self::AGREEMENT_FIELDS as $field => ['type' => $type]) {
            $isImageField = isset($imageFields[$field]);
            $match = 0;
            $seen  = 0;

            foreach ($byImage as $imageKey => $modelMap) {
                if (!isset($modelMap[$targetModel])) continue;
                $targetRow = $modelMap[$targetModel];

                // Skip image-derived fields when the target model's image call failed.
                if ($isImageField && !$this->imageCallSucceeded($targetRow)) continue;

                $targetVal = $targetRow[$field] ?? null;

                // Gather votes from other models (skip their failed image calls for image fields).
                $otherVals = [];
                foreach ($modelMap as $model => $row) {
                    if ($model === $targetModel) continue;
                    if ($isImageField && !$this->imageCallSucceeded($row)) continue;
                    $otherVals[] = $row[$field] ?? null;
                }
                if (empty($otherVals)) continue;

                $majority = $this->majorityVote($otherVals);
                if ($majority === null && $targetVal === null) continue;
                if ($majority === null || $targetVal === null) {
                    $seen++;
                    continue;
                }

                $seen++;
                if ($this->fieldsAgree($field, $targetVal, $majority, $type)) {
                    $match++;
                }
            }

            if ($seen > 0) {
                $agreements[$field] = 100.0 * $match / $seen;
            }
        }

        return !empty($agreements) ? array_sum($agreements) / count($agreements) : 0.0;
    }

    private function majorityVote(array $values): mixed
    {
        $counts = [];
        foreach ($values as $v) {
            $key = ($v === null) ? '__null__' : (string)$v;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        arsort($counts);
        $topKey = array_key_first($counts);
        return $topKey === '__null__' ? null : $topKey;
    }

    private function fieldsAgree(string $field, mixed $a, mixed $b, string $type): bool
    {
        return match ($type) {
            'exact'    => (string)$a === (string)$b,
            'plusone'  => abs((float)$a - (float)$b) <= 1,
            'adjacent' => $this->valueBandsAdjacent((string)$a, (string)$b),
            default    => (string)$a === (string)$b,
        };
    }

    private function valueBandsAdjacent(string $a, string $b): bool
    {
        $bands  = self::VALUE_BANDS;
        $idxA   = array_search($a, $bands, true);
        $idxB   = array_search($b, $bands, true);
        if ($idxA === false || $idxB === false) return $a === $b;
        return abs($idxA - $idxB) <= 1;
    }

    private function displayOverviewTable(array $models, array $scores): void
    {
        $this->info('=== Model Scores ===');
        $rows = [];
        foreach ($models as $model) {
            $s = $scores[$model];
            $imgRate = $s['image_success_rate'] !== null ? number_format($s['image_success_rate'], 0) . '%' : 'n/a';
            $imgNote = ($s['image_success_rate'] !== null && $s['image_success_rate'] < 95)
                ? $imgRate . '*'
                : $imgRate;
            $rows[] = [
                $model,
                $s['total'],
                $imgNote,
                $s['self_consistency'] !== null ? number_format($s['self_consistency'], 1) . '%' : 'n/a',
                $s['eee_reliability']  !== null ? number_format($s['eee_reliability'], 1) . '%' : 'n/a',
                $s['cross_agree']      !== null ? number_format($s['cross_agree'], 1) . '%' : 'n/a',
                $s['field_completeness']!== null? number_format($s['field_completeness'], 1) . '%' : 'n/a',
                $s['composite']        !== null ? number_format($s['composite'], 1) . '%' : 'n/a',
            ];
        }
        $this->table(
            ['Model', 'N', 'Img calls OK', 'Self-consistency', 'EEE reliability', 'Cross-model agree', 'Field complete†', 'Composite'],
            $rows
        );

        $this->line('Img calls OK:      % of API image calls that returned data (rate limits → *partial data*)');
        $this->line('Self-consistency:  is_eee agrees with is_eee_from_components');
        $this->line('EEE reliability:   % of EEE items with supporting primary-EEE components');
        $this->line('Cross-model agree: % agreement with majority vote across all models on key fields');
        $this->line('Field complete†:   % of expected fields non-null (image fields only counted on successful image calls)');
    }

    private function displayAgreementDetails(array $models, array $scores): void
    {
        $this->info('=== Cross-model Agreement by Field ===');
        if (count($models) < 2) {
            $this->warn('Need at least 2 models for cross-model comparison.');
            return;
        }

        // Rebuild per-field agreement for display.
        $pdo  = $this->sqlite->getPdo();
        $stmt = $pdo->query("SELECT MAX(prompt_version) as pv FROM eee_classifications");
        $pv   = $stmt->fetch()['pv'];
        $fields = ['is_eee','weee_category','condition','photo_quality','value_band_gbp','item_complete','brand'];
        $stmt = $pdo->prepare("
            SELECT c.messageid, c.attid, c.model, " .
            implode(', ', array_map(fn($f) => "c.$f", $fields)) .
            " FROM eee_classifications c WHERE c.prompt_version = ?
        ");
        $stmt->execute([$pv]);
        $allRows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $byImage = [];
        foreach ($allRows as $row) {
            $key = $row['messageid'] . '_' . $row['attid'];
            $byImage[$key][$row['model']] = $row;
        }

        $headerRow = ['Field'];
        foreach ($models as $m) {
            $headerRow[] = substr($m, 0, 20);
        }
        $tableRows = [];

        $imageFields = array_flip(self::IMAGE_COMPLETENESS_FIELDS);
        foreach (self::AGREEMENT_FIELDS as $field => ['label' => $label, 'type' => $type]) {
            $isImageField = isset($imageFields[$field]);
            $row = [$label];
            foreach ($models as $model) {
                $match = 0; $seen = 0;
                foreach ($byImage as $imageKey => $modelMap) {
                    if (!isset($modelMap[$model])) continue;
                    $targetRow = $modelMap[$model];
                    if ($isImageField && !$this->imageCallSucceeded($targetRow)) continue;
                    $targetVal = $targetRow[$field] ?? null;
                    $otherVals = [];
                    foreach ($modelMap as $m => $r) {
                        if ($m === $model) continue;
                        if ($isImageField && !$this->imageCallSucceeded($r)) continue;
                        $otherVals[] = $r[$field] ?? null;
                    }
                    if (empty($otherVals)) continue;
                    $majority = $this->majorityVote($otherVals);
                    if ($majority === null && $targetVal === null) continue;
                    if ($majority === null || $targetVal === null) { $seen++; continue; }
                    $seen++;
                    if ($this->fieldsAgree($field, $targetVal, $majority, $type)) $match++;
                }
                $row[] = $seen > 0 ? number_format(100.0 * $match / $seen, 0) . '%' : 'n/a';
            }
            $tableRows[] = $row;
        }

        $this->table($headerRow, $tableRows);
    }

    private function displayFieldBreakdown(array $models, array $scores): void
    {
        $this->info('=== Field Completeness (% non-null) ===');
        $headerRow = ['Field'];
        foreach ($models as $m) {
            $headerRow[] = substr($m, 0, 20);
        }
        $tableRows = [];

        foreach (self::COMPLETENESS_FIELDS as $field) {
            $row = [$field];
            foreach ($models as $model) {
                $rate = $scores[$model]['field_rates'][$field] ?? null;
                $row[] = $rate !== null ? number_format($rate, 0) . '%' : 'n/a';
            }
            $tableRows[] = $row;
        }

        $this->table($headerRow, $tableRows);
    }

    private function writeDetailCsv(string $path, array $rows, array $byImage): void
    {
        $fp = fopen($path, 'w');
        if (!$fp) {
            $this->error("Cannot write to {$path}");
            return;
        }
        fputcsv($fp, ['messageid', 'attid', 'model', 'is_eee', 'is_eee_from_components',
            'electrical_components_description', 'weee_category', 'condition',
            'photo_quality', 'value_band_gbp', 'brand', 'model_number', 'material_primary']);
        foreach ($rows as $row) {
            fputcsv($fp, [
                $row['messageid'], $row['attid'], $row['model'],
                $row['is_eee'], $row['is_eee_from_components'],
                $row['electrical_components_description'],
                $row['weee_category'], $row['condition'],
                $row['photo_quality'], $row['value_band_gbp'],
                $row['brand'], $row['model_number'], $row['material_primary'],
            ]);
        }
        fclose($fp);
        $this->info("Detail CSV written to {$path}");
    }
}
