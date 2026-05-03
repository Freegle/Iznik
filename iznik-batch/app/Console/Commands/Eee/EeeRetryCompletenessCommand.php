<?php

namespace App\Console\Commands\Eee;

use App\Services\EeeSqliteService;
use App\Services\EeeVisionService;
use Illuminate\Console\Command;

/**
 * Re-run API calls for canonical sample images where key fields are null.
 *
 * Some models return null for brand, model_number, or electrical_components_description
 * on the first pass — either because the photo is marginal, the model hedged, or the
 * field is genuinely absent. Re-running the same image can fill in blanks because models
 * have some randomness (temperature > 0). Only null fields are updated; existing values
 * are preserved.
 *
 *   php artisan eee:retry-completeness
 *   php artisan eee:retry-completeness --driver=gemini --limit=50
 *   php artisan eee:retry-completeness --fields=brand,model_number
 *   php artisan eee:retry-completeness --prompt-version=1.4.1 --dry-run
 */
class EeeRetryCompletenessCommand extends Command
{
    protected $signature = 'eee:retry-completeness
                            {--driver=           : Model driver to retry (claude, gemini, openai). Defaults to configured model.}
                            {--prompt-version=   : Prompt version to target (default: current)}
                            {--fields=           : Comma-separated fields to check for nulls (default: brand,model_number,electrical_components_description)}
                            {--limit=100         : Max number of images to retry}
                            {--dry-run           : Show what would be retried without making API calls}';

    protected $description = 'Fill null fields in eee_classifications by re-running API on canonical sample images';

    // Fields that are worth retrying — only image-derived fields, not text-derived
    private const RETRYABLE_FIELDS = [
        'brand',
        'model_number',
        'electrical_components_description',
        'condition',
        'weight_kg_min',
        'value_band_gbp',
    ];

    public function __construct(
        protected EeeVisionService $vision,
        protected EeeSqliteService $sqlite,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $driver = $this->option('driver');
        if ($driver) {
            $this->vision = $this->vision->withDriver($driver);
        }

        $promptVersion = $this->option('prompt-version') ?? $this->vision->getPromptVersion();
        $limit         = (int) $this->option('limit');
        $dryRun        = (bool) $this->option('dry-run');

        $fieldsOpt = $this->option('fields');
        $fields    = $fieldsOpt
            ? array_map('trim', explode(',', $fieldsOpt))
            : ['brand', 'model_number', 'electrical_components_description'];

        // Validate requested fields
        $invalid = array_diff($fields, self::RETRYABLE_FIELDS);
        if (!empty($invalid)) {
            $this->error('Unknown fields: ' . implode(', ', $invalid) . '. Valid: ' . implode(', ', self::RETRYABLE_FIELDS));
            return Command::FAILURE;
        }

        $model = $this->vision->getModelName();

        $this->info("EEE retry completeness | model: {$model} | prompt: {$promptVersion} | fields: " . implode(', ', $fields));

        // Build a WHERE clause that matches rows where ANY of the target fields is null
        $pdo  = $this->sqlite->getPdo();
        $nullChecks = implode(' OR ', array_map(fn($f) => "c.{$f} IS NULL", $fields));

        $stmt = $pdo->prepare("
            SELECT c.messageid, c.attid, c.model, c.prompt_version,
                   s.externaluid, s.item_name,
                   " . implode(', ', array_map(fn($f) => "c.{$f}", $fields)) . "
            FROM eee_classifications c
            JOIN eee_item_type_samples s ON s.messageid = c.messageid AND s.attid = c.attid
            WHERE c.model = ? AND c.prompt_version = ? AND ({$nullChecks})
            ORDER BY s.item_name, c.messageid
            LIMIT ?
        ");
        $stmt->execute([$model, $promptVersion, $limit]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($rows)) {
            $this->info('No rows found with null fields for this model/prompt version.');
            return Command::SUCCESS;
        }

        $this->info(count($rows) . ' rows to retry' . ($dryRun ? ' (dry run)' : '') . ':');

        if ($dryRun) {
            foreach (array_slice($rows, 0, 20) as $row) {
                $nullFields = array_filter($fields, fn($f) => $row[$f] === null);
                $this->line("  {$row['item_name']} (msg {$row['messageid']}) — null: " . implode(', ', $nullFields));
            }
            if (count($rows) > 20) {
                $this->line('  ... and ' . (count($rows) - 20) . ' more');
            }
            return Command::SUCCESS;
        }

        if (!$this->vision->isConfigured()) {
            $this->error('Vision service not configured.');
            return Command::FAILURE;
        }

        $updated  = 0;
        $filled   = array_fill_keys($fields, 0);
        $totalCost = 0.0;

        foreach ($rows as $row) {
            $imageUrl = EeeVisionService::buildImageUrl($row['externaluid']);
            // No text context — this is an image-only retry
            $result = $this->vision->analyse($imageUrl, ['subject' => '', 'description' => '']);

            if ($result === null) {
                $this->line("  <comment>skip</comment> {$row['item_name']} (API returned null)");
                continue;
            }

            $totalCost += $result['_meta']['cost_usd'] ?? 0.0;

            // Build UPDATE: only set fields that are currently null AND the new result is non-null
            $setClauses = [];
            $params     = [];
            foreach ($fields as $field) {
                if ($row[$field] !== null) continue;  // already has a value — skip

                // Map from DB column to result key
                $value = $this->extractFieldValue($field, $result);
                if ($value === null) continue;  // new call also returned null — no gain

                $setClauses[] = "{$field} = ?";
                $params[]     = $value;
                $filled[$field]++;
            }

            if (empty($setClauses)) {
                $this->line("  <fg=gray>no gain</fg=gray> {$row['item_name']}");
                continue;
            }

            $params[] = $row['messageid'];
            $params[] = $row['attid'];
            $params[] = $model;
            $params[] = $promptVersion;

            $pdo->prepare("
                UPDATE eee_classifications
                SET " . implode(', ', $setClauses) . "
                WHERE messageid = ? AND attid = ? AND model = ? AND prompt_version = ?
            ")->execute($params);

            $filled_str = implode(', ', array_keys(array_filter($setClauses, fn($c) => true)));
            $this->line("  <info>filled</info> {$row['item_name']} — " . implode(', ', array_filter($fields, fn($f) => isset($filled[$f]) && $row[$f] === null && $this->extractFieldValue($f, $result) !== null)));
            $updated++;
        }

        $this->newLine();
        $this->table(['Field', 'Newly filled'], array_map(fn($f) => [$f, $filled[$f]], $fields));
        $this->info("{$updated} rows updated. Cost: \$" . number_format($totalCost, 4));

        return Command::SUCCESS;
    }

    private function extractFieldValue(string $field, array $result): mixed
    {
        return match ($field) {
            'brand'                              => $result['brand'] ?? null,
            'model_number'                       => $result['model_number'] ?? null,
            'electrical_components_description'  => $result['electrical_components_description'] ?? null,
            'condition'                          => $result['condition'] ?? null,
            'weight_kg_min'                      => $result['weight_kg_min'] ?? null,
            'value_band_gbp'                     => $result['value_band_gbp'] ?? null,
            default                              => null,
        };
    }
}
