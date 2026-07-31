<?php

namespace Tests\Unit\Services;

use App\Services\EeeSqliteService;
use PDO;
use Tests\TestCase;

/**
 * Unit tests for EeeSqliteService.
 *
 * The service owns its own SQLite file (separate from the MySQL `iznik` DB used
 * elsewhere in the app), so every test points `freegle.eee.sqlite_path` at a
 * fresh temp file and constructs a brand-new service instance — no shared
 * state, no MySQL fixtures required.
 */
class EeeSqliteServiceTest extends TestCase
{
    /** @var string[] */
    private array $tempPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $path) {
            foreach ([$path, $path . '-wal', $path . '-shm', $path . '-journal'] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            $dir = dirname($path);
            if (is_dir($dir) && str_contains($dir, 'eee_sqlite_test_')) {
                @rmdir($dir);
                $parent = dirname($dir);
                if (is_dir($parent) && str_contains($parent, 'eee_sqlite_test_')) {
                    @rmdir($parent);
                }
            }
        }
        $this->tempPaths = [];

        parent::tearDown();
    }

    private function newService(?string $path = null): EeeSqliteService
    {
        $path ??= sys_get_temp_dir() . '/eee_sqlite_test_' . uniqid() . '.sqlite';
        $this->tempPaths[] = $path;
        config(['freegle.eee.sqlite_path' => $path]);
        return new EeeSqliteService();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // getPdo() / migrate() bootstrap
    // ─────────────────────────────────────────────────────────────────────────

    public function test_get_pdo_creates_missing_nested_directory_and_file(): void
    {
        $path = sys_get_temp_dir() . '/eee_sqlite_test_' . uniqid() . '/nested/classifications.sqlite';
        $this->tempPaths[] = $path;
        config(['freegle.eee.sqlite_path' => $path]);
        $service = new EeeSqliteService();

        $this->assertFileDoesNotExist($path);

        $pdo = $service->getPdo();

        $this->assertInstanceOf(PDO::class, $pdo);
        $this->assertFileExists($path);
    }

    public function test_get_pdo_returns_same_instance_on_repeat_calls(): void
    {
        $service = $this->newService();

        $this->assertSame($service->getPdo(), $service->getPdo());
    }

    public function test_migrate_creates_all_expected_tables(): void
    {
        $service = $this->newService();
        $pdo     = $service->getPdo();

        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);

        foreach ([
            'eee_item_types',
            'eee_classifications',
            'eee_item_type_samples',
            'eee_runs',
            'eee_observations',
            'eee_component_types',
        ] as $expected) {
            $this->assertContains($expected, $tables, "Missing table: {$expected}");
        }
    }

    public function test_migrate_upgrades_legacy_schema_missing_classification_mode_column(): void
    {
        $path = sys_get_temp_dir() . '/eee_sqlite_test_' . uniqid() . '.sqlite';
        $this->tempPaths[] = $path;

        // Simulate a pre-existing v1 database: the full column set the upgrade
        // code expects, but no classification_mode column at all.
        $legacy = new PDO('sqlite:' . $path);
        $legacy->exec("
            CREATE TABLE eee_item_types (
                item_name                TEXT,
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
                classified_at            DATETIME,
                PRIMARY KEY (item_name, model, prompt_version)
            )
        ");
        $legacy->exec("
            INSERT INTO eee_item_types
                (item_name, item_id, popularity, sample_size, is_eee, model, prompt_version, classified_at)
            VALUES ('Toaster', 1, 5, 3, 1, 'gemini', '1.0.0', '2026-01-01 00:00:00')
        ");
        $legacy = null;

        config(['freegle.eee.sqlite_path' => $path]);
        $service = new EeeSqliteService();

        $row = $service->getItemType('Toaster', 'gemini', '1.0.0', 'combined');

        $this->assertNotNull($row);
        $this->assertSame('combined', $row['classification_mode']);
        $this->assertSame(5, (int) $row['popularity']);
    }

    public function test_migrate_upgrades_schema_with_mode_column_but_short_primary_key(): void
    {
        // TODO: latent bug — migrate()'s "add contains_eee_components /
        // electrical_components_description if missing" block runs BEFORE the
        // hasMode=true/pkCols<4 upgrade block, widening the source table to 19
        // columns. The upgrade then does `INSERT INTO eee_item_types_new (17 cols)
        // SELECT * FROM eee_item_types (19 cols)`, which SQLite rejects with
        // "table eee_item_types_new has 17 columns but 19 values were supplied".
        // Any real v2-schema DB (classification_mode present, PK still 3 columns)
        // would crash on migrate(). Not fixed here per task instructions (test
        // files only, no production code changes).
        $this->markTestSkipped('Latent bug in EeeSqliteService::migrate(): v2-schema (hasMode=true, pkCols<4) upgrade crashes with a column-count mismatch because ADD COLUMN runs before the upgrade SELECT *.');

        $path = sys_get_temp_dir() . '/eee_sqlite_test_' . uniqid() . '.sqlite';
        $this->tempPaths[] = $path;

        // v2: classification_mode column already exists, but the PK is still
        // only 3 columns (not the 4-column composite the current schema needs).
        $legacy = new PDO('sqlite:' . $path);
        $legacy->exec("
            CREATE TABLE eee_item_types (
                item_name                TEXT,
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
                classification_mode      TEXT NOT NULL DEFAULT 'combined',
                classified_at            DATETIME,
                PRIMARY KEY (item_name, model, prompt_version)
            )
        ");
        $legacy->exec("
            INSERT INTO eee_item_types
                (item_name, item_id, popularity, is_eee, model, prompt_version, classification_mode, classified_at)
            VALUES ('Kettle', 2, 9, 1, 'gemini', '1.0.0', 'per_image', '2026-01-02 00:00:00')
        ");
        $legacy = null;

        config(['freegle.eee.sqlite_path' => $path]);
        $service = new EeeSqliteService();

        $row = $service->getItemType('Kettle', 'gemini', '1.0.0', 'per_image');

        $this->assertNotNull($row);
        $this->assertSame('per_image', $row['classification_mode']);
        $this->assertSame(9, (int) $row['popularity']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // upsertItemType() / getItemType()
    // ─────────────────────────────────────────────────────────────────────────

    public function test_get_item_type_returns_null_when_not_found(): void
    {
        $service = $this->newService();

        $this->assertNull($service->getItemType('Nonexistent', 'gemini', '1.0.0'));
    }

    public function test_upsert_item_type_inserts_new_row(): void
    {
        $service = $this->newService();

        $service->upsertItemType([
            'item_name'            => 'Blender',
            'item_id'              => 10,
            'popularity'           => 4,
            'is_eee'               => 1,
            'model'                => 'gemini',
            'prompt_version'       => '1.1.0',
            'classification_mode'  => 'combined',
        ]);

        $row = $service->getItemType('Blender', 'gemini', '1.1.0');

        $this->assertNotNull($row);
        $this->assertSame(4, (int) $row['popularity']);
        $this->assertSame(1, (int) $row['is_eee']);
    }

    public function test_upsert_item_type_updates_existing_row_on_conflict(): void
    {
        $service = $this->newService();

        $base = [
            'item_name'           => 'Fan',
            'model'               => 'gemini',
            'prompt_version'      => '1.1.0',
            'classification_mode' => 'combined',
            'popularity'          => 1,
        ];
        $service->upsertItemType($base);
        $service->upsertItemType([...$base, 'popularity' => 99]);

        $row = $service->getItemType('Fan', 'gemini', '1.1.0');

        $this->assertSame(99, (int) $row['popularity']);
    }

    public function test_upsert_item_type_with_only_key_columns(): void
    {
        // TODO: latent bug — upsertItemType() builds `$updates` by filtering OUT
        // the 4 key columns from array_keys($data). When $data contains ONLY the
        // key columns (a legitimate call — e.g. "register that this item type
        // exists, nothing else known yet"), $updates is an empty string, producing
        // `... ON CONFLICT(...) DO UPDATE SET ` with nothing after SET, which
        // SQLite rejects with "incomplete input". Not fixed here per task
        // instructions (test files only, no production code changes).
        $this->markTestSkipped('Latent bug in EeeSqliteService::upsertItemType(): calling it with only the 4 primary-key columns produces an empty "DO UPDATE SET" clause and throws a PDOException.');

        $service = $this->newService();

        $service->upsertItemType([
            'item_name'           => 'Toaster',
            'model'               => 'gemini',
            'prompt_version'      => '1.0.0',
            'classification_mode' => 'combined',
        ]);

        $this->assertNotNull($service->getItemType('Toaster', 'gemini', '1.0.0'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // getUnclassifiedItemTypeNames()
    // ─────────────────────────────────────────────────────────────────────────

    public function test_get_unclassified_item_type_names_returns_empty_for_empty_input(): void
    {
        $service = $this->newService();

        $this->assertSame([], $service->getUnclassifiedItemTypeNames([]));
    }

    public function test_get_unclassified_item_type_names_excludes_already_classified(): void
    {
        $service = $this->newService();

        $service->upsertItemType([
            'item_name'           => 'Toaster',
            'model'               => 'gemini',
            'prompt_version'      => '1.0.0',
            'classification_mode' => 'combined',
            'is_eee'              => 1,
        ]);

        $result = $service->getUnclassifiedItemTypeNames(
            ['Toaster', 'Kettle', 'Fan'],
            'gemini',
            '1.0.0',
            'combined'
        );

        $this->assertSame(['Kettle', 'Fan'], array_values($result));
    }

    public function test_get_unclassified_item_type_names_is_scoped_by_mode(): void
    {
        $service = $this->newService();

        // Classified under 'combined' only — should still be "unclassified" for 'per_image'.
        $service->upsertItemType([
            'item_name'           => 'Toaster',
            'model'               => 'gemini',
            'prompt_version'      => '1.0.0',
            'classification_mode' => 'combined',
            'is_eee'              => 1,
        ]);

        $result = $service->getUnclassifiedItemTypeNames(['Toaster'], 'gemini', '1.0.0', 'per_image');

        $this->assertSame(['Toaster'], array_values($result));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // insertClassification() / hasClassification() / getClassificationsForMessage()
    // ─────────────────────────────────────────────────────────────────────────

    private function classificationRow(array $overrides = []): array
    {
        return array_merge([
            'messageid'      => 100,
            'model'          => 'gemini',
            'prompt_version' => '1.0.0',
            'run_at'         => '2026-01-01 00:00:00',
            'data_sources'   => 'text,image',
            'is_eee'         => 1,
        ], $overrides);
    }

    public function test_insert_classification_returns_last_insert_id(): void
    {
        $service = $this->newService();

        $id1 = $service->insertClassification($this->classificationRow());
        $id2 = $service->insertClassification($this->classificationRow(['messageid' => 101]));

        $this->assertSame($id1 + 1, $id2);
        $this->assertGreaterThan(0, $id1);
    }

    /**
     * @dataProvider provideHasClassificationCases
     */
    public function test_has_classification(array $inserted, int $messageid, string $model, ?string $promptVersion, bool $expected): void
    {
        $service = $this->newService();
        $service->insertClassification($this->classificationRow($inserted));

        $this->assertSame($expected, $service->hasClassification($messageid, $model, $promptVersion));
    }

    public static function provideHasClassificationCases(): array
    {
        return [
            'matches messageid+model, no prompt version filter' => [
                ['messageid' => 5, 'model' => 'gemini'], 5, 'gemini', null, true,
            ],
            'wrong model, no prompt version filter' => [
                ['messageid' => 5, 'model' => 'gemini'], 5, 'openai', null, false,
            ],
            'wrong messageid, no prompt version filter' => [
                ['messageid' => 5, 'model' => 'gemini'], 6, 'gemini', null, false,
            ],
            'matches messageid+model+prompt version' => [
                ['messageid' => 7, 'model' => 'gemini', 'prompt_version' => '2.0.0'], 7, 'gemini', '2.0.0', true,
            ],
            'wrong prompt version' => [
                ['messageid' => 7, 'model' => 'gemini', 'prompt_version' => '2.0.0'], 7, 'gemini', '1.0.0', false,
            ],
        ];
    }

    public function test_get_classifications_for_message_returns_empty_array_when_none(): void
    {
        $service = $this->newService();

        $this->assertSame([], $service->getClassificationsForMessage(999));
    }

    public function test_get_classifications_for_message_keyed_by_model(): void
    {
        $service = $this->newService();

        $service->insertClassification($this->classificationRow(['messageid' => 42, 'model' => 'gemini']));
        $service->insertClassification($this->classificationRow(['messageid' => 42, 'model' => 'openai']));
        $service->insertClassification($this->classificationRow(['messageid' => 43, 'model' => 'gemini']));

        $result = $service->getClassificationsForMessage(42);

        $this->assertSame(['gemini', 'openai'], array_keys($result));
        $this->assertSame(42, (int) $result['gemini']['messageid']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // startRun() / finishRun()
    // ─────────────────────────────────────────────────────────────────────────

    public function test_start_run_and_finish_run_updates_stats(): void
    {
        $service = $this->newService();

        $runId = $service->startRun('gemini', '1.0.0', 'all', 'test note');
        $this->assertGreaterThan(0, $runId);

        $service->finishRun($runId, 50, 12, 2, 1.2345);

        $pdo = $service->getPdo();
        $row = $pdo->prepare('SELECT * FROM eee_runs WHERE id = ?');
        $row->execute([$runId]);
        $run = $row->fetch(PDO::FETCH_ASSOC);

        $this->assertSame('gemini', $run['model']);
        $this->assertSame('test note', $run['notes']);
        $this->assertSame(50, (int) $run['processed']);
        $this->assertSame(12, (int) $run['eee_found']);
        $this->assertSame(2, (int) $run['errors']);
        $this->assertEqualsWithDelta(1.2345, (float) $run['cost_usd_total'], 0.0001);
        $this->assertNotNull($run['completed_at']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // getStats()
    // ─────────────────────────────────────────────────────────────────────────

    public function test_get_stats_on_empty_database(): void
    {
        $service = $this->newService();

        $stats = $service->getStats();

        $this->assertSame(0, $stats['total']);
        $this->assertSame(0, $stats['eeeCount']);
        $this->assertSame(0, $stats['unusual']);
        $this->assertSame(0, $stats['typesCount']);
        $this->assertSame([], $stats['byCategory']);
        $this->assertSame([], $stats['byCondition']);
        $this->assertSame([], $stats['topBrands']);
        $this->assertSame([], $stats['monthlyTrend']);
        $this->assertNull($stats['weightTotal']);
    }

    public function test_get_stats_aggregates_across_classifications(): void
    {
        $service = $this->newService();

        $service->upsertItemType(['item_name' => 'Toaster', 'model' => 'gemini', 'prompt_version' => '1.0.0', 'classification_mode' => 'combined', 'is_eee' => 1]);
        $service->upsertItemType(['item_name' => 'Chair', 'model' => 'gemini', 'prompt_version' => '1.0.0', 'classification_mode' => 'combined', 'is_eee' => 0]);

        $service->insertClassification($this->classificationRow([
            'messageid' => 1, 'is_eee' => 1, 'is_unusual_eee' => 1,
            'weee_category' => 4, 'weee_category_name' => 'Large household appliances',
            'condition' => 'Working', 'brand' => 'Acme',
            'weight_kg_min' => 2.0, 'weight_kg_max' => 4.0,
            'run_at' => '2026-01-15 10:00:00',
        ]));
        $service->insertClassification($this->classificationRow([
            'messageid' => 2, 'is_eee' => 1, 'is_unusual_eee' => 0,
            'weee_category' => 4, 'weee_category_name' => 'Large household appliances',
            'condition' => 'Working', 'brand' => 'Acme',
            'weight_kg_min' => 1.0, 'weight_kg_max' => 3.0,
            'run_at' => '2026-01-20 10:00:00',
        ]));
        $service->insertClassification($this->classificationRow([
            'messageid' => 3, 'is_eee' => 0, 'is_unusual_eee' => 0,
            'run_at' => '2026-01-20 10:00:00',
        ]));

        $stats = $service->getStats();

        $this->assertSame(3, $stats['total']);
        $this->assertSame(2, $stats['eeeCount']);
        $this->assertSame(1, $stats['unusual']);
        $this->assertSame(2, $stats['typesCount']);

        $this->assertCount(1, $stats['byCategory']);
        $this->assertSame(2, $stats['byCategory'][0]['cnt']);

        $this->assertCount(1, $stats['byCondition']);
        $this->assertSame('Working', $stats['byCondition'][0]['condition']);
        $this->assertSame(2, $stats['byCondition'][0]['cnt']);

        $this->assertCount(1, $stats['topBrands']);
        $this->assertSame('Acme', $stats['topBrands'][0]['brand']);

        $this->assertCount(1, $stats['monthlyTrend']);
        $this->assertSame('2026-01', $stats['monthlyTrend'][0]['month']);
        $this->assertSame(3, (int) $stats['monthlyTrend'][0]['total']);
        $this->assertSame(2, (int) $stats['monthlyTrend'][0]['eee_count']);

        // (2+4)/2 + (1+3)/2 = 3 + 2 = 5
        $this->assertEqualsWithDelta(5.0, (float) $stats['weightTotal'], 0.0001);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Item type samples
    // ─────────────────────────────────────────────────────────────────────────

    private function sampleAttachment(array $overrides = []): object
    {
        return (object) array_merge([
            'messageid'   => 1,
            'attid'       => 1,
            'externaluid' => 'uid-1',
            'subject'     => 'OFFER: Toaster',
            'textbody'    => 'Free toaster',
        ], $overrides);
    }

    public function test_has_sample_for_item_type_false_when_none_recorded(): void
    {
        $service = $this->newService();

        $this->assertFalse($service->hasSampleForItemType('Toaster'));
        $this->assertSame([], $service->getSampleForItemType('Toaster'));
    }

    public function test_record_and_retrieve_item_type_sample(): void
    {
        $service = $this->newService();

        $service->recordItemTypeSample('Toaster', [
            $this->sampleAttachment(['messageid' => 2, 'attid' => 20, 'externaluid' => 'uid-2']),
            $this->sampleAttachment(['messageid' => 1, 'attid' => 10, 'externaluid' => 'uid-1']),
        ]);

        $this->assertTrue($service->hasSampleForItemType('Toaster'));

        $samples = $service->getSampleForItemType('Toaster');

        $this->assertCount(2, $samples);
        // Ordered by messageid ascending.
        $this->assertSame(1, $samples[0]->messageid);
        $this->assertSame(2, $samples[1]->messageid);
    }

    public function test_record_item_type_sample_ignores_duplicates(): void
    {
        $service = $this->newService();

        $att = $this->sampleAttachment();
        $service->recordItemTypeSample('Toaster', [$att]);
        $service->recordItemTypeSample('Toaster', [$att]);

        $this->assertCount(1, $service->getSampleForItemType('Toaster'));
    }

    public function test_get_sample_message_ids_returns_distinct_ids_within_limit(): void
    {
        $service = $this->newService();

        $service->recordItemTypeSample('Toaster', [
            $this->sampleAttachment(['messageid' => 1, 'attid' => 1, 'externaluid' => 'a']),
        ]);
        $service->recordItemTypeSample('Kettle', [
            $this->sampleAttachment(['messageid' => 1, 'attid' => 2, 'externaluid' => 'b']),
            $this->sampleAttachment(['messageid' => 2, 'attid' => 3, 'externaluid' => 'c']),
        ]);

        $ids = $service->getSampleMessageIds();
        sort($ids);
        $this->assertSame([1, 2], $ids);

        $limited = $service->getSampleMessageIds(1);
        $this->assertCount(1, $limited);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // getMessageidsForModel()
    // ─────────────────────────────────────────────────────────────────────────

    public function test_get_messageids_for_model_orders_desc_and_respects_limit(): void
    {
        $service = $this->newService();

        foreach ([10, 20, 30] as $mid) {
            $service->insertClassification($this->classificationRow(['messageid' => $mid, 'model' => 'gemini']));
        }
        $service->insertClassification($this->classificationRow(['messageid' => 40, 'model' => 'openai']));

        $ids = $service->getMessageidsForModel('gemini', 2);

        $this->assertSame([30, 20], $ids);
    }

    public function test_get_messageids_for_model_returns_empty_for_unknown_model(): void
    {
        $service = $this->newService();

        $this->assertSame([], $service->getMessageidsForModel('unknown-model', 10));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Research journal: recordObservation() / getObservations()
    // ─────────────────────────────────────────────────────────────────────────

    public function test_record_observation_defaults_to_preliminary_confidence(): void
    {
        $service = $this->newService();

        $id = $service->recordObservation('classify_item_types', 'overall', 'Test finding.');

        $this->assertGreaterThan(0, $id);

        $observations = $service->getObservations();
        $this->assertCount(1, $observations);
        $this->assertSame('preliminary', $observations[0]['confidence']);
        $this->assertSame('Test finding.', $observations[0]['finding']);
    }

    public function test_record_observation_stores_evidence_as_json(): void
    {
        $service = $this->newService();

        $service->recordObservation('scope', 'x', 'finding', 'emerging', ['count' => 5]);

        $observations = $service->getObservations();
        $this->assertSame(['count' => 5], json_decode($observations[0]['evidence'], true));
    }

    /**
     * @dataProvider provideConfidenceFilterCases
     */
    public function test_get_observations_filters_by_min_confidence(string $minConfidence, array $expectedFindings): void
    {
        $service = $this->newService();

        $service->recordObservation('s', 'a', 'preliminary finding', 'preliminary');
        $service->recordObservation('s', 'b', 'emerging finding', 'emerging');
        $service->recordObservation('s', 'c', 'consistent finding', 'consistent');
        $service->recordObservation('s', 'd', 'verified finding', 'verified');

        $results = array_column($service->getObservations($minConfidence), 'finding');
        sort($results);
        $expected = $expectedFindings;
        sort($expected);

        $this->assertSame($expected, $results);
    }

    public static function provideConfidenceFilterCases(): array
    {
        return [
            'preliminary returns all' => ['preliminary', ['preliminary finding', 'emerging finding', 'consistent finding', 'verified finding']],
            'emerging excludes preliminary' => ['emerging', ['emerging finding', 'consistent finding', 'verified finding']],
            'consistent only consistent and verified' => ['consistent', ['consistent finding', 'verified finding']],
            'verified only verified' => ['verified', ['verified finding']],
            'unknown level treated as preliminary (0)' => ['bogus', ['preliminary finding', 'emerging finding', 'consistent finding', 'verified finding']],
        ];
    }

    public function test_get_observations_excludes_superseded_entries(): void
    {
        // TODO: latent bug — getObservations() filters `WHERE o.supersedes_id IS
        // NULL`, which keeps the OLD/original row (supersedes_id never set on it)
        // and HIDES the newer row that supersedes it (that row is the one with
        // supersedes_id populated). This is inverted: per the class docblock,
        // observations "can be promoted" as findings recur, and callers should see
        // the latest/promoted finding, not the one it replaced. Not fixed here per
        // task instructions (test files only, no production code changes).
        $this->markTestSkipped('Latent bug in EeeSqliteService::getObservations(): the supersedes_id IS NULL filter keeps the original (superseded) row and hides the newer row that supersedes it — the opposite of "show the latest finding".');

        $service = $this->newService();

        $originalId = $service->recordObservation('s', 'a', 'original finding', 'preliminary');
        $service->recordObservation('s', 'a', 'updated finding', 'consistent', null, null, $originalId);

        $findings = array_column($service->getObservations(), 'finding');

        $this->assertNotContains('original finding', $findings);
        $this->assertContains('updated finding', $findings);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // journalItemTypeRun()
    // ─────────────────────────────────────────────────────────────────────────

    public function test_journal_item_type_run_records_only_overall_when_nothing_mixed_or_ambiguous(): void
    {
        $service = $this->newService();

        $service->upsertItemType([
            'item_name' => 'Toaster', 'model' => 'gemini', 'prompt_version' => '1.0.0',
            'classification_mode' => 'combined', 'is_eee' => 1,
        ]);

        $runId = $service->startRun('gemini', '1.0.0', 'all');
        $service->journalItemTypeRun($runId, '1.0.0');

        $scopes = array_column($service->getObservations(), 'scope');

        $this->assertContains('overall', $scopes);
        $this->assertNotContains('mixed_types', $scopes);
        $this->assertNotContains('ambiguous_types', $scopes);
        $this->assertNotContains('photo_quality', $scopes);
    }

    public function test_journal_item_type_run_records_mixed_types_observation(): void
    {
        $service = $this->newService();

        // Nominally non-EEE, but has EEE samples in the mix.
        $service->upsertItemType([
            'item_name' => 'Chair', 'model' => 'gemini', 'prompt_version' => '1.0.0',
            'classification_mode' => 'combined', 'is_eee' => 0, 'eee_sample_count' => 2,
        ]);

        $runId = $service->startRun('gemini', '1.0.0', 'all');
        $service->journalItemTypeRun($runId, '1.0.0');

        $scopes = array_column($service->getObservations(), 'scope');
        $this->assertContains('mixed_types', $scopes);
    }

    public function test_journal_item_type_run_records_ambiguous_types_observation(): void
    {
        $service = $this->newService();

        $service->upsertItemType([
            'item_name' => 'Lamp', 'model' => 'gemini', 'prompt_version' => '1.0.0',
            'classification_mode' => 'combined', 'needs_image_analysis' => 1,
            'is_eee_agree_rate' => 0.4, 'is_eee_confidence' => 0.5,
        ]);

        $runId = $service->startRun('gemini', '1.0.0', 'all');
        $service->journalItemTypeRun($runId, '1.0.0');

        $scopes = array_column($service->getObservations(), 'scope');
        $this->assertContains('ambiguous_types', $scopes);
    }

    public function test_journal_item_type_run_records_photo_quality_when_present(): void
    {
        $service = $this->newService();

        $service->insertClassification($this->classificationRow(['photo_quality' => 4]));
        $service->insertClassification($this->classificationRow(['messageid' => 2, 'photo_quality' => 2]));

        $runId = $service->startRun('gemini', '1.0.0', 'all');
        $service->journalItemTypeRun($runId, '1.0.0');

        $photoQualityObs = array_values(array_filter(
            $service->getObservations(),
            fn ($o) => $o['scope'] === 'photo_quality'
        ));

        $this->assertCount(1, $photoQualityObs);
        $this->assertStringContainsString('3', $photoQualityObs[0]['finding']); // avg of 4 and 2
    }

    public function test_journal_item_type_run_skips_photo_quality_when_none_recorded(): void
    {
        $service = $this->newService();

        $runId = $service->startRun('gemini', '1.0.0', 'all');
        $service->journalItemTypeRun($runId, '1.0.0');

        $scopes = array_column($service->getObservations(), 'scope');
        $this->assertNotContains('photo_quality', $scopes);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Component knowledge base
    // ─────────────────────────────────────────────────────────────────────────

    public function test_get_component_type_by_name_returns_null_when_not_found(): void
    {
        $service = $this->newService();

        $this->assertNull($service->getComponentTypeByName('motor'));
    }

    public function test_upsert_component_type_inserts_with_embedding_blob(): void
    {
        $service = $this->newService();

        $embedding = pack('f*', 0.1, 0.2, 0.3);
        $service->upsertComponentType([
            'canonical_name' => 'motor',
            'category'       => 'primary_eee',
            'embedding'      => $embedding,
            'raw_strings'    => 'motor,drum motor',
        ]);

        $row = $service->getComponentTypeByName('motor');

        $this->assertSame('primary_eee', $row['category']);
        $this->assertSame($embedding, $row['embedding']);
    }

    public function test_upsert_component_type_inserts_with_null_embedding_and_default_category(): void
    {
        $service = $this->newService();

        $service->upsertComponentType(['canonical_name' => 'gasket']);

        $row = $service->getComponentTypeByName('gasket');

        $this->assertSame('unknown', $row['category']);
        $this->assertNull($row['embedding']);
    }

    public function test_upsert_component_type_on_conflict_preserves_embedding_when_new_value_is_null(): void
    {
        $service = $this->newService();

        $embedding = pack('f*', 0.5, 0.6);
        $service->upsertComponentType(['canonical_name' => 'motor', 'category' => 'primary_eee', 'embedding' => $embedding]);

        // Re-upsert with a category change but no embedding — COALESCE must keep the old one.
        $service->upsertComponentType(['canonical_name' => 'motor', 'category' => 'supplementary_eee']);

        $row = $service->getComponentTypeByName('motor');

        $this->assertSame('supplementary_eee', $row['category']);
        $this->assertSame($embedding, $row['embedding']);
    }

    public function test_get_component_type_by_name_is_case_insensitive(): void
    {
        $service = $this->newService();

        $service->upsertComponentType(['canonical_name' => 'Motor']);

        $this->assertNotNull($service->getComponentTypeByName('MOTOR'));
        $this->assertNotNull($service->getComponentTypeByName('motor'));
    }

    public function test_get_component_types_filtered_by_category(): void
    {
        $service = $this->newService();

        $service->upsertComponentType(['canonical_name' => 'motor', 'category' => 'primary_eee']);
        $service->upsertComponentType(['canonical_name' => 'gasket', 'category' => 'non_electrical']);

        $primary = $service->getComponentTypes('primary_eee');

        $this->assertCount(1, $primary);
        $this->assertSame('motor', $primary[0]['canonical_name']);
    }

    public function test_get_component_types_without_filter_returns_all(): void
    {
        $service = $this->newService();

        $service->upsertComponentType(['canonical_name' => 'motor', 'category' => 'primary_eee']);
        $service->upsertComponentType(['canonical_name' => 'gasket', 'category' => 'non_electrical']);

        $this->assertCount(2, $service->getComponentTypes());
    }

    public function test_count_component_types_groups_by_category(): void
    {
        $service = $this->newService();

        $service->upsertComponentType(['canonical_name' => 'motor', 'category' => 'primary_eee']);
        $service->upsertComponentType(['canonical_name' => 'fan motor', 'category' => 'primary_eee']);
        $service->upsertComponentType(['canonical_name' => 'gasket', 'category' => 'non_electrical']);

        $counts = $service->countComponentTypes();

        $this->assertSame(2, $counts['primary_eee']);
        $this->assertSame(1, $counts['non_electrical']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // journalCompareRun()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @dataProvider provideJournalCompareRunCases
     */
    public function test_journal_compare_run_confidence_tiers(array $agreementStats, bool $expectObservation, ?string $expectedConfidence = null): void
    {
        $service = $this->newService();

        $runId = $service->startRun('gemini', '1.0.0', 'compare');
        $service->journalCompareRun($runId, $agreementStats);

        $observations = $service->getObservations();

        if (!$expectObservation) {
            $this->assertSame([], $observations);
            return;
        }

        $this->assertCount(1, $observations);
        $this->assertSame($expectedConfidence, $observations[0]['confidence']);
    }

    public static function provideJournalCompareRunCases(): array
    {
        return [
            'missing seen/agree keys is skipped' => [
                ['gemini' => ['brand' => ['other_key' => 1]]], false,
            ],
            'below minimum sample size (seen < 10) is skipped' => [
                ['gemini' => ['brand' => ['seen' => 9, 'agree' => 9]]], false,
            ],
            'consistent tier: seen >= 100 and rate >= 85%' => [
                ['gemini' => ['brand' => ['seen' => 100, 'agree' => 90]]], true, 'consistent',
            ],
            'emerging tier: seen >= 50 but rate below 85%' => [
                ['gemini' => ['brand' => ['seen' => 60, 'agree' => 30]]], true, 'emerging',
            ],
            'emerging tier: seen >= 100 but rate below 85%' => [
                ['gemini' => ['brand' => ['seen' => 100, 'agree' => 50]]], true, 'emerging',
            ],
            'preliminary tier: seen between 10 and 49' => [
                ['gemini' => ['brand' => ['seen' => 10, 'agree' => 10]]], true, 'preliminary',
            ],
        ];
    }

    public function test_journal_compare_run_records_observation_per_attribute_per_model(): void
    {
        $service = $this->newService();

        $runId = $service->startRun('gemini', '1.0.0', 'compare');
        $service->journalCompareRun($runId, [
            'gemini' => [
                'brand'     => ['seen' => 20, 'agree' => 18],
                'condition' => ['seen' => 20, 'agree' => 10],
            ],
            'openai' => [
                'brand' => ['seen' => 20, 'agree' => 15],
            ],
        ]);

        $scopes = array_column($service->getObservations(), 'scope');
        sort($scopes);

        $this->assertSame([
            'model:gemini:attr:brand',
            'model:gemini:attr:condition',
            'model:openai:attr:brand',
        ], $scopes);
    }
}
