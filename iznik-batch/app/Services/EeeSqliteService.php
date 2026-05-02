<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use PDO;

/**
 * SQLite storage for EEE classification results.
 *
 * Separate from MySQL to avoid schema churn during the experimental phase
 * and to keep the dataset portable for Python/pandas analysis.
 */
class EeeSqliteService
{
    protected ?PDO $pdo = null;

    protected string $dbPath;

    public function __construct()
    {
        $this->dbPath = config('freegle.eee.sqlite_path');
    }

    public function getPdo(): PDO
    {
        if ($this->pdo === null) {
            $dir = dirname($this->dbPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $this->pdo = new PDO('sqlite:' . $this->dbPath);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->exec('PRAGMA journal_mode=WAL');
            $this->pdo->exec('PRAGMA foreign_keys=ON');

            $this->migrate();
        }

        return $this->pdo;
    }

    protected function migrate(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS eee_item_types (
                item_name                TEXT PRIMARY KEY,
                item_id                  INTEGER,
                popularity               INTEGER,
                sample_size              INTEGER DEFAULT 0,
                images_analysed          INTEGER DEFAULT 0,
                eee_sample_count         INTEGER DEFAULT 0,
                is_eee                   INTEGER,
                is_eee_confidence        REAL,
                is_eee_agree_rate        REAL,
                weee_category            INTEGER,
                weee_category_name       TEXT,
                weee_category_confidence REAL,
                needs_image_analysis     INTEGER DEFAULT 0,
                model                    TEXT,
                prompt_version           TEXT,
                classified_at            DATETIME
            )
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS eee_classifications (
                id                        INTEGER PRIMARY KEY AUTOINCREMENT,
                messageid                 INTEGER NOT NULL,
                attid                     INTEGER,
                model                     TEXT NOT NULL,
                prompt_version            TEXT NOT NULL,
                run_at                    DATETIME NOT NULL,
                data_sources              TEXT NOT NULL,
                is_eee                    INTEGER,
                is_eee_confidence         REAL,
                is_eee_reasoning          TEXT,
                is_unusual_eee            INTEGER,
                unusual_eee_reason        TEXT,
                weee_category             INTEGER,
                weee_category_name        TEXT,
                weee_category_confidence  REAL,
                weight_kg_min             REAL,
                weight_kg_max             REAL,
                weight_kg_confidence      REAL,
                size_cm                   TEXT,
                size_confidence           REAL,
                condition                 TEXT,
                condition_confidence      REAL,
                brand                     TEXT,
                brand_confidence          REAL,
                model_number              TEXT,
                model_number_confidence   REAL,
                material_primary          TEXT,
                material_secondary        TEXT,
                material_confidence       REAL,
                primary_item              TEXT,
                short_description         TEXT,
                long_description          TEXT,
                photo_quality             INTEGER,
                photo_quality_notes       TEXT,
                item_complete             INTEGER,
                item_complete_confidence  REAL,
                item_complete_notes       TEXT,
                accessories_visible       TEXT,
                value_band_gbp            TEXT,
                value_band_confidence     REAL,
                text_eee_signals          TEXT,
                chat_eee_signals          TEXT,
                conflict_flag             INTEGER DEFAULT 0,
                raw_response              TEXT,
                input_tokens              INTEGER,
                output_tokens             INTEGER,
                cost_usd                  REAL
            )
        ");

        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_cls_messageid ON eee_classifications(messageid)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_cls_is_eee ON eee_classifications(is_eee)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_cls_model ON eee_classifications(model, prompt_version)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_cls_run_at ON eee_classifications(run_at)");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS eee_runs (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                started_at     DATETIME,
                completed_at   DATETIME,
                model          TEXT,
                prompt_version TEXT,
                scope          TEXT,
                processed      INTEGER DEFAULT 0,
                eee_found      INTEGER DEFAULT 0,
                errors         INTEGER DEFAULT 0,
                cost_usd_total REAL DEFAULT 0,
                notes          TEXT
            )
        ");

        // Research journal: structured observations with confidence progression.
        // Confidence levels: preliminary → emerging → consistent → verified
        // Observations are auto-generated at end of each run and can be promoted
        // manually as the same finding recurs across runs and models.
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS eee_observations (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                observed_at    DATETIME NOT NULL,
                run_id         INTEGER,
                phase          TEXT NOT NULL,
                scope          TEXT NOT NULL,
                finding        TEXT NOT NULL,
                confidence     TEXT NOT NULL DEFAULT 'preliminary',
                evidence       TEXT,
                supersedes_id  INTEGER,
                prompt_version TEXT,
                FOREIGN KEY (run_id) REFERENCES eee_runs(id)
            )
        ");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_obs_scope ON eee_observations(scope)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_obs_confidence ON eee_observations(confidence)");
    }

    public function upsertItemType(array $data): void
    {
        $pdo  = $this->getPdo();
        $cols = implode(', ', array_keys($data));
        $vals = ':' . implode(', :', array_keys($data));

        $updates = implode(', ', array_map(
            fn($k) => "$k = excluded.$k",
            array_filter(array_keys($data), fn($k) => $k !== 'item_name')
        ));

        $pdo->prepare("
            INSERT INTO eee_item_types ($cols) VALUES ($vals)
            ON CONFLICT(item_name) DO UPDATE SET $updates
        ")->execute($data);
    }

    public function getItemType(string $itemName): ?array
    {
        $pdo  = $this->getPdo();
        $stmt = $pdo->prepare("SELECT * FROM eee_item_types WHERE item_name = ?");
        $stmt->execute([$itemName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Returns names from $itemNames that are NOT yet in eee_item_types. */
    public function getUnclassifiedItemTypeNames(array $itemNames): array
    {
        if (empty($itemNames)) {
            return [];
        }
        $pdo          = $this->getPdo();
        $placeholders = implode(',', array_fill(0, count($itemNames), '?'));
        $stmt         = $pdo->prepare("SELECT item_name FROM eee_item_types WHERE item_name IN ($placeholders)");
        $stmt->execute($itemNames);
        $classified = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return array_values(array_diff($itemNames, $classified));
    }

    public function insertClassification(array $data): int
    {
        $pdo          = $this->getPdo();
        $cols         = implode(', ', array_keys($data));
        $placeholders = ':' . implode(', :', array_keys($data));
        $pdo->prepare("INSERT INTO eee_classifications ($cols) VALUES ($placeholders)")->execute($data);
        return (int) $pdo->lastInsertId();
    }

    public function hasClassification(int $messageid, string $model): bool
    {
        $pdo  = $this->getPdo();
        $stmt = $pdo->prepare("SELECT 1 FROM eee_classifications WHERE messageid = ? AND model = ? LIMIT 1");
        $stmt->execute([$messageid, $model]);
        return (bool) $stmt->fetchColumn();
    }

    /** Returns all classifications for a message keyed by model. */
    public function getClassificationsForMessage(int $messageid): array
    {
        $pdo  = $this->getPdo();
        $stmt = $pdo->prepare("SELECT * FROM eee_classifications WHERE messageid = ?");
        $stmt->execute([$messageid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_column($rows, null, 'model');
    }

    public function startRun(string $model, string $promptVersion, string $scope, ?string $notes = null): int
    {
        $pdo = $this->getPdo();
        $pdo->prepare("
            INSERT INTO eee_runs (started_at, model, prompt_version, scope, notes)
            VALUES (datetime('now'), ?, ?, ?, ?)
        ")->execute([$model, $promptVersion, $scope, $notes]);
        return (int) $pdo->lastInsertId();
    }

    public function finishRun(int $runId, int $processed, int $eeeFound, int $errors, float $cost): void
    {
        $this->getPdo()->prepare("
            UPDATE eee_runs
            SET completed_at = datetime('now'), processed = ?, eee_found = ?, errors = ?, cost_usd_total = ?
            WHERE id = ?
        ")->execute([$processed, $eeeFound, $errors, $cost, $runId]);
    }

    public function getStats(): array
    {
        $pdo = $this->getPdo();

        $total      = (int) $pdo->query("SELECT COUNT(*) FROM eee_classifications")->fetchColumn();
        $eeeCount   = (int) $pdo->query("SELECT COUNT(*) FROM eee_classifications WHERE is_eee = 1")->fetchColumn();
        $unusual    = (int) $pdo->query("SELECT COUNT(*) FROM eee_classifications WHERE is_unusual_eee = 1")->fetchColumn();
        $typesCount = (int) $pdo->query("SELECT COUNT(*) FROM eee_item_types")->fetchColumn();

        $byCategory = $pdo->query("
            SELECT weee_category, weee_category_name, COUNT(*) as cnt
            FROM eee_classifications
            WHERE is_eee = 1 AND weee_category IS NOT NULL
            GROUP BY weee_category, weee_category_name ORDER BY cnt DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $byCondition = $pdo->query("
            SELECT condition, COUNT(*) as cnt
            FROM eee_classifications
            WHERE is_eee = 1
            GROUP BY condition ORDER BY cnt DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $topBrands = $pdo->query("
            SELECT brand, COUNT(*) as cnt
            FROM eee_classifications
            WHERE is_eee = 1 AND brand IS NOT NULL AND brand != ''
            GROUP BY brand ORDER BY cnt DESC LIMIT 20
        ")->fetchAll(PDO::FETCH_ASSOC);

        $monthlyTrend = $pdo->query("
            SELECT strftime('%Y-%m', run_at) as month,
                   COUNT(*) as total,
                   SUM(CASE WHEN is_eee = 1 THEN 1 ELSE 0 END) as eee_count
            FROM eee_classifications
            GROUP BY month ORDER BY month DESC LIMIT 13
        ")->fetchAll(PDO::FETCH_ASSOC);

        $weightTotal = $pdo->query("
            SELECT SUM((weight_kg_min + weight_kg_max) / 2.0)
            FROM eee_classifications
            WHERE is_eee = 1 AND weight_kg_min IS NOT NULL AND weight_kg_max IS NOT NULL
        ")->fetchColumn();

        return compact('total', 'eeeCount', 'unusual', 'typesCount',
            'byCategory', 'byCondition', 'topBrands', 'monthlyTrend', 'weightTotal');
    }

    /**
     * Returns messageids that have been classified by at least one model but not all of $models.
     * Used by eee:compare-models to find gaps.
     */
    public function getMessageidsForModel(string $model, int $limit): array
    {
        $pdo  = $this->getPdo();
        $stmt = $pdo->prepare("
            SELECT DISTINCT messageid FROM eee_classifications
            WHERE model = ?
            ORDER BY id DESC LIMIT ?
        ");
        $stmt->execute([$model, $limit]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // -------------------------------------------------------------------------
    // Research journal
    // -------------------------------------------------------------------------

    public function recordObservation(
        string $phase,
        string $scope,
        string $finding,
        string $confidence = 'preliminary',
        ?array $evidence   = null,
        ?int   $runId      = null,
        ?int   $supersedes = null,
    ): int {
        $pdo = $this->getPdo();
        $pdo->prepare("
            INSERT INTO eee_observations
                (observed_at, run_id, phase, scope, finding, confidence, evidence, supersedes_id, prompt_version)
            VALUES (datetime('now'), ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $runId,
            $phase,
            $scope,
            $finding,
            $confidence,
            $evidence ? json_encode($evidence) : null,
            $supersedes,
            config('freegle.eee.prompt_version', '1.1.0'),
        ]);
        return (int) $pdo->lastInsertId();
    }

    public function getObservations(string $minConfidence = 'preliminary'): array
    {
        $order = ['preliminary' => 0, 'emerging' => 1, 'consistent' => 2, 'verified' => 3];
        $level = $order[$minConfidence] ?? 0;

        $pdo  = $this->getPdo();
        $rows = $pdo->query("
            SELECT o.*, r.scope as run_scope
            FROM eee_observations o
            LEFT JOIN eee_runs r ON r.id = o.run_id
            WHERE o.supersedes_id IS NULL
            ORDER BY
                CASE o.confidence
                    WHEN 'verified'    THEN 0
                    WHEN 'consistent'  THEN 1
                    WHEN 'emerging'    THEN 2
                    WHEN 'preliminary' THEN 3
                END,
                o.observed_at DESC
        ")->fetchAll(\PDO::FETCH_ASSOC);

        return array_values(array_filter($rows, function ($r) use ($order, $level) {
            return ($order[$r['confidence']] ?? 0) >= $level;
        }));
    }

    /**
     * Auto-generate journal observations from a completed classify-item-types run.
     * Called at the end of EeeClassifyItemTypesCommand.
     */
    public function journalItemTypeRun(int $runId, string $promptVersion): void
    {
        $pdo = $this->getPdo();

        $total    = (int) $pdo->query("SELECT COUNT(*) FROM eee_item_types")->fetchColumn();
        $eeeCount = (int) $pdo->query("SELECT COUNT(*) FROM eee_item_types WHERE is_eee = 1")->fetchColumn();
        $eeeRate  = $total > 0 ? round(100 * $eeeCount / $total, 1) : 0;

        $this->recordObservation('classify_item_types', 'overall',
            "{$eeeCount} of {$total} item types classified as EEE ({$eeeRate}%).",
            'preliminary',
            ['eee_count' => $eeeCount, 'total' => $total],
            $runId,
        );

        // Mixed types: item types where some sampled images were EEE even though majority was not.
        $mixed = $pdo->query("
            SELECT item_name, eee_sample_count, images_analysed, is_eee_agree_rate
            FROM eee_item_types
            WHERE is_eee = 0 AND eee_sample_count > 0
            ORDER BY eee_sample_count DESC LIMIT 20
        ")->fetchAll(\PDO::FETCH_ASSOC);

        if (!empty($mixed)) {
            $names = implode(', ', array_column($mixed, 'item_name'));
            $this->recordObservation('classify_item_types', 'mixed_types',
                count($mixed) . " nominally non-EEE types had EEE variants in the sample (will always run per-image): {$names}.",
                'preliminary',
                ['types' => $mixed],
                $runId,
            );
        }

        // Low-agreement types that need per-image regardless.
        $ambiguous = $pdo->query("
            SELECT item_name, is_eee_agree_rate, is_eee_confidence
            FROM eee_item_types
            WHERE needs_image_analysis = 1
            ORDER BY is_eee_agree_rate ASC LIMIT 20
        ")->fetchAll(\PDO::FETCH_ASSOC);

        if (!empty($ambiguous)) {
            $names = implode(', ', array_column($ambiguous, 'item_name'));
            $this->recordObservation('classify_item_types', 'ambiguous_types',
                count($ambiguous) . " item types flagged as ambiguous (low agreement/confidence, needs per-image): {$names}.",
                'preliminary',
                ['types' => $ambiguous],
                $runId,
            );
        }

        // Photo quality observations.
        $lowQuality = $pdo->query("
            SELECT AVG(photo_quality) as avg_quality, COUNT(*) as cnt
            FROM eee_classifications
            WHERE photo_quality IS NOT NULL
        ")->fetch(\PDO::FETCH_ASSOC);

        if ($lowQuality && $lowQuality['cnt'] > 0) {
            $avgQ = round((float) $lowQuality['avg_quality'], 2);
            $this->recordObservation('classify_item_types', 'photo_quality',
                "Average photo quality across {$lowQuality['cnt']} images: {$avgQ}/5.",
                'preliminary',
                ['avg_quality' => $avgQ, 'sample_count' => $lowQuality['cnt']],
                $runId,
            );
        }
    }

    /**
     * Auto-generate observations from a completed compare-models run.
     */
    public function journalCompareRun(int $runId, array $agreementStats): void
    {
        foreach ($agreementStats as $model => $attrs) {
            foreach ($attrs as $attr => $stat) {
                if (!isset($stat['seen'], $stat['agree']) || $stat['seen'] < 10) {
                    continue;
                }
                $rate = round(100 * $stat['agree'] / $stat['seen'], 1);
                $confidence = match (true) {
                    $stat['seen'] >= 100 && $rate >= 85 => 'consistent',
                    $stat['seen'] >= 50  => 'emerging',
                    default              => 'preliminary',
                };
                $this->recordObservation('compare_models', "model:{$model}:attr:{$attr}",
                    "{$model} agrees with reference on '{$attr}' at {$rate}% ({$stat['seen']} samples).",
                    $confidence,
                    $stat,
                    $runId,
                );
            }
        }
    }
}
