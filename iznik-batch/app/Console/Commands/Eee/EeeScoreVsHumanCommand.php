<?php

namespace App\Console\Commands\Eee;

use App\Services\EeeSqliteService;
use App\Services\EeeVisionService;
use Illuminate\Console\Command;
use PDO;

/**
 * Score vision-model classifications against human reviewer labels.
 *
 * Human labels are the source of truth. The local sqlite file at
 * EEE_LABELS_PATH (default storage/eee/eee-labels.db) holds labels
 * collected via the eee-browser Fly.io deployment. To refresh:
 *
 *   fly ssh sftp get -a freegle-eee-browser /data/eee-labels.db \
 *     iznik-batch/storage/eee/eee-labels.db
 *
 * For each item with multiple human reviewers, the plurality vote wins;
 * ties and 'unsure' labels are excluded. This matches eee-browser's
 * /api/review/stats logic so the two views stay consistent.
 *
 * Usage:
 *   php artisan eee:score-vs-human
 *   php artisan eee:score-vs-human --field=EEE
 *   php artisan eee:score-vs-human --prompt-versions=1.4.1,1.4.2
 */
class EeeScoreVsHumanCommand extends Command
{
    protected $signature = 'eee:score-vs-human
                            {--field=         : Restrict to one field (EEE, Condition, ...). Default: all labelled fields}
                            {--prompt-versions=1.4.1,1.4.2 : Comma-separated classification prompt versions to score}
                            {--labels-path=   : Override path to eee-labels.db (default: from EEE_LABELS_PATH env)}';

    protected $description = 'Score vision models against human reviewer labels (human = ground truth)';

    /** Field definitions matching eee-browser/server/api/review/stats.ts */
    private const FIELDS = [
        ['field' => 'EEE',                   'col' => 'is_eee',                         'type' => 'binary'],
        ['field' => 'Electrical components', 'col' => 'electrical_components_description','type' => 'presence'],
        ['field' => 'Photo quality',         'col' => 'photo_quality',                  'type' => 'rating5'],
        ['field' => 'Condition',             'col' => 'condition',                      'type' => 'condition'],
        ['field' => 'Weight (kg)',           'col' => 'weight_kg_min',                  'type' => 'bucket'],
        ['field' => 'Size',                  'col' => 'size_cm',                        'type' => 'size_bucket'],
        ['field' => 'Value band',            'col' => 'value_band_gbp',                 'type' => 'value_band'],
    ];

    private const WEIGHT_BUCKETS = [
        'under_1kg'  => [null, 1],
        '1_5kg'      => [1, 5],
        '5_20kg'     => [5, 20],
        '20_100kg'   => [20, 100],
        'over_100kg' => [100, null],
    ];

    private const SIZE_BUCKETS = [
        'tiny'   => [null, 20],
        'small'  => [20, 50],
        'medium' => [50, 100],
        'large'  => [100, null],
    ];

    private const EXCLUDE_MODELS = ['claude-opus-4-7'];

    public function __construct(
        protected EeeSqliteService $sqlite,
        protected EeeVisionService $vision,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $labelsPath = $this->option('labels-path')
            ?: env('EEE_LABELS_PATH', storage_path('eee/eee-labels.db'));

        if (!file_exists($labelsPath)) {
            $this->error("Labels DB not found at {$labelsPath}.");
            $this->line("Download it with:");
            $this->line("  fly ssh sftp get -a freegle-eee-browser /data/eee-labels.db {$labelsPath}");
            return Command::FAILURE;
        }

        $labelsDb = new PDO('sqlite:' . $labelsPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        $promptVersions = array_map('trim', explode(',', $this->option('prompt-versions')));
        $fieldFilter    = $this->option('field');

        $fields = self::FIELDS;
        if ($fieldFilter) {
            $fields = array_values(array_filter($fields, fn($f) => $f['field'] === $fieldFilter));
            if (empty($fields)) {
                $this->error("Unknown field: {$fieldFilter}");
                return Command::FAILURE;
            }
        }

        $classDb = $this->sqlite->getPdo();

        foreach ($fields as $fieldDef) {
            $this->scoreField($labelsDb, $classDb, $fieldDef, $promptVersions);
        }

        return Command::SUCCESS;
    }

    protected function scoreField(PDO $labelsDb, PDO $classDb, array $fieldDef, array $promptVersions): void
    {
        $field   = $fieldDef['field'];
        $col     = $fieldDef['col'];
        $type    = $fieldDef['type'];

        $rows = $labelsDb->prepare('SELECT messageid, attid, labeller, label FROM eee_field_labels WHERE field = ?');
        $rows->execute([$field]);
        $labels = $rows->fetchAll(PDO::FETCH_ASSOC);

        if (empty($labels)) {
            $this->line("<comment>{$field}: no human labels yet — skipping.</comment>");
            return;
        }

        $quorum = $this->computeQuorum($labels);
        $valid  = array_filter($quorum, fn($l) => $l !== null && $l !== 'unsure');

        $this->info("=== {$field} ===");
        $this->line(sprintf("  Human labels: %d (across %d reviewers, quorum reached on %d items)",
            count($labels),
            count(array_unique(array_column($labels, 'labeller'))),
            count($valid),
        ));

        if (empty($valid)) {
            $this->warn("  No quorum labels — skipping.");
            return;
        }

        $modelStats = []; // model => ['total' => N, 'correct' => N]
        $placeholders = implode(',', array_fill(0, count($promptVersions), '?'));

        foreach ($valid as $itemKey => $humanLabel) {
            [$mid, $aid] = explode('-', $itemKey);

            $q = $classDb->prepare("SELECT DISTINCT model, {$col} AS val, is_eee, electrical_components_description,
                                           photo_quality, condition, weight_kg_min, size_cm, value_band_gbp
                                    FROM eee_classifications
                                    WHERE messageid = ? AND attid = ?
                                      AND prompt_version IN ({$placeholders})
                                      AND {$col} IS NOT NULL");
            $q->execute(array_merge([$mid, $aid], $promptVersions));
            $clfs = $q->fetchAll(PDO::FETCH_ASSOC);

            foreach ($clfs as $clf) {
                $model = $clf['model'];
                if (in_array($model, self::EXCLUDE_MODELS, true)) continue;

                if (!isset($modelStats[$model])) {
                    $modelStats[$model] = ['total' => 0, 'correct' => 0];
                }
                $modelStats[$model]['total']++;
                if ($this->isCorrect($type, $humanLabel, $clf)) {
                    $modelStats[$model]['correct']++;
                }
            }
        }

        if (empty($modelStats)) {
            $this->warn("  No model classifications match these labelled items.");
            return;
        }

        ksort($modelStats);
        $rows = [];
        foreach ($modelStats as $model => $s) {
            $acc = $s['total'] > 0 ? sprintf('%.1f%%', 100 * $s['correct'] / $s['total']) : 'n/a';
            $rows[] = [$model, $s['total'], $s['correct'], $acc];
        }
        $this->table(['Model', 'Scored', 'Correct', 'Accuracy vs human'], $rows);
        $this->newLine();
    }

    /**
     * Compute plurality vote per (messageid, attid).
     * Returns map "{messageid}-{attid}" => winning label, or null on tie.
     */
    protected function computeQuorum(array $labels): array
    {
        $byItem = [];
        foreach ($labels as $row) {
            $key = $row['messageid'] . '-' . $row['attid'];
            $byItem[$key][$row['label']] = ($byItem[$key][$row['label']] ?? 0) + 1;
        }

        $out = [];
        foreach ($byItem as $key => $votes) {
            $max = max($votes);
            $winners = array_keys(array_filter($votes, fn($v) => $v === $max));
            $out[$key] = count($winners) === 1 ? $winners[0] : null;
        }
        return $out;
    }

    /** Mirrors the isCorrect() logic in eee-browser/server/api/review/stats.ts */
    protected function isCorrect(string $type, string $humanLabel, array $clf): bool
    {
        switch ($type) {
            case 'binary':
                return ((int) $clf['is_eee'] === 1) === ($humanLabel === 'eee');

            case 'presence':
                $modelPresent = !empty($clf['electrical_components_description']);
                return ($humanLabel === 'present') === $modelPresent;

            case 'rating5':
                $h = (int) $humanLabel;
                $m = (int) $clf['photo_quality'];
                if ($h === 0 || $m === 0) return false;
                return abs($h - $m) <= 1;

            case 'condition':
                return strtolower($clf['condition'] ?? '') === strtolower($humanLabel);

            case 'bucket':
                $kg = is_numeric($clf['weight_kg_min']) ? (float) $clf['weight_kg_min'] : null;
                return $kg !== null && $this->inBucket($kg, self::WEIGHT_BUCKETS, $humanLabel);

            case 'size_bucket':
                $max = $this->maxSizeCm($clf['size_cm']);
                return $max !== null && $this->inBucket($max, self::SIZE_BUCKETS, $humanLabel);

            case 'value_band':
                return $clf['value_band_gbp'] === $humanLabel;
        }
        return false;
    }

    protected function inBucket(float $value, array $buckets, string $key): bool
    {
        if (!isset($buckets[$key])) return false;
        [$min, $max] = $buckets[$key];
        if ($min !== null && $value < $min) return false;
        if ($max !== null && $value >= $max) return false;
        return true;
    }

    protected function maxSizeCm(?string $sizeCm): ?float
    {
        if (!$sizeCm) return null;
        $obj = json_decode($sizeCm, true);
        if (!is_array($obj)) return null;
        $vals = array_filter([$obj['w'] ?? null, $obj['h'] ?? null, $obj['d'] ?? null],
                             fn($v) => is_numeric($v) && $v > 0);
        return empty($vals) ? null : (float) max($vals);
    }
}
