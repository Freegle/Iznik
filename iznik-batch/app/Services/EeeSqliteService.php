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
                material_primary          TEXT,
                material_secondary        TEXT,
                material_confidence       REAL,
                primary_item              TEXT,
                short_description         TEXT,
                long_description          TEXT,
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
}
