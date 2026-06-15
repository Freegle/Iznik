<?php

namespace Tests\Unit\Commands\Spatial;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class UpdateSpatialDataCommandTest extends TestCase
{
    private string $dataDir;

    protected function setUp(): void
    {
        parent::setUp();
        // Don't actually shell out to osmium for the merge in unit tests.
        Process::fake();
        $this->dataDir = storage_path('tests/spatial');
        if (!is_dir($this->dataDir)) {
            mkdir($this->dataDir, 0755, true);
        }
        config(['freegle.spatial_data_dir' => $this->dataDir]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dataDir)) {
            foreach (glob("{$this->dataDir}/*") ?: [] as $f) {
                @unlink($f);
            }
        }
        parent::tearDown();
    }

    /**
     * Test dry-run mode doesn't make any changes.
     */
    public function test_dry_run_mode_reports_no_changes(): void
    {
        Http::fake();

        $this->artisan('spatial:update-data', ['--dry-run' => true])
            ->expectsOutputToContain('no changes will be made')
            ->expectsOutputToContain('Would download: https://')
            ->expectsOutputToContain('Would run: DeprivationDataService')
            ->expectsOutputToContain('Would signal: http://')
            ->assertExitCode(0);
    }

    /**
     * Test OSM PBF download with atomic write succeeds end-to-end.
     */
    public function test_downloads_osm_pbf_file_atomically(): void
    {
        Http::fake($this->buildFakeHttpResponses($this->generateValidCsv()));

        $this->artisan('spatial:update-data')
            ->assertExitCode(0);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'geofabrik.de');
        });
    }

    /**
     * Test OSM PBF download rejects files without magic bytes.
     */
    public function test_rejects_osm_pbf_without_magic_bytes(): void
    {
        Http::fake([
            'https://download.geofabrik.de/europe/great-britain-latest.osm.pbf' => Http::response(
                'INVALID_BINARY_DATA',
                200
            ),
        ]);

        $this->artisan('spatial:update-data')
            ->expectsOutputToContain('magic bytes')
            ->assertExitCode(1);
    }

    /**
     * Test CSV validation detects too few rows.
     */
    public function test_csv_validation_rejects_too_few_rows(): void
    {
        $tinyContent = "lat,lng,quintile\n51.5,-2.0,1\n51.6,-2.0,2\n";

        Http::fake($this->buildFakeHttpResponses($tinyContent));

        $this->artisan('spatial:update-data')
            ->expectsOutputToContain('Too few total rows')
            ->assertExitCode(1);
    }

    /**
     * Test CSV validation detects out-of-bounds coordinates.
     * Uses a full valid CSV plus one row with lat=70 (outside UK bounds).
     */
    public function test_csv_validation_rejects_out_of_bounds_coords(): void
    {
        $csvContent = $this->generateValidCsv();
        $csvContent .= "70.0,-2.0,1\n";

        Http::fake($this->buildFakeHttpResponses($csvContent));

        $this->artisan('spatial:update-data')
            ->expectsOutputToContain('outside UK bounds')
            ->assertExitCode(1);
    }

    /**
     * Test CSV validation detects skewed quintile distribution.
     * Uses valid row counts/coords but quintile cycles only 2-5 (missing 1).
     */
    public function test_csv_validation_detects_missing_quintile(): void
    {
        Http::fake($this->buildFakeHttpResponses($this->generateCsvWithSkewedQuintiles()));

        $this->artisan('spatial:update-data')
            ->expectsOutputToContain('unusual distribution')
            ->assertExitCode(1);
    }

    /**
     * Test CSV validation detects too few E&W rows.
     */
    public function test_csv_validation_detects_too_few_ew_rows(): void
    {
        // Only 100 E&W rows; rest are pure Scotland (lat>56, not in E&W range)
        $csvContent = "lat,lng,quintile\n";
        for ($i = 0; $i < 100; $i++) {
            $csvContent .= "52.0,-2.0," . (($i % 5) + 1) . "\n";
        }
        for ($i = 0; $i < 39900; $i++) {
            $csvContent .= "57.0,-3.0," . (($i % 5) + 1) . "\n";
        }

        Http::fake($this->buildFakeHttpResponses($csvContent));

        $this->artisan('spatial:update-data')
            ->expectsOutputToContain('Too few E&W rows')
            ->assertExitCode(1);
    }

    /**
     * Test successful CSV generation and validation.
     */
    public function test_valid_csv_passes_validation(): void
    {
        Http::fake($this->buildFakeHttpResponses($this->generateValidCsv()));

        $this->artisan('spatial:update-data')
            ->expectsOutputToContain('Spatial data update complete')
            ->expectsOutputToContain('Validation passed')
            ->assertExitCode(0);
    }

    /**
     * Test reload signal is sent after successful update.
     */
    public function test_sends_reload_signal_to_server(): void
    {
        Http::fake($this->buildFakeHttpResponses($this->generateValidCsv()));

        $this->artisan('spatial:update-data')
            ->assertExitCode(0);

        Http::assertSent(function ($request) {
            return $request->url() === 'http://localhost:8195/v1/reload' &&
                $request->method() === 'POST';
        });
    }

    /**
     * Test command handles HTTP errors gracefully.
     */
    public function test_handles_download_failure(): void
    {
        Http::fake([
            'https://download.geofabrik.de/europe/great-britain-latest.osm.pbf' => Http::response(
                'Not Found',
                404
            ),
        ]);

        $this->artisan('spatial:update-data')
            ->expectsOutputToContain('Error:')
            ->assertExitCode(1);
    }

    /**
     * Test command handles DeprivationDataService failure (IMD download fails).
     */
    public function test_handles_deprivation_service_failure(): void
    {
        Http::fake([
            'https://download.geofabrik.de/europe/great-britain-latest.osm.pbf' => Http::response(
                "\x00\x00\x00\x1a\x4f\x73\x6d\x48\x65\x61\x64\x65\x72\x00MOCK_PBF_DATA",
                200
            ),
            'https://raw.githubusercontent.com/*' => Http::response('Server Error', 500),
            '*' => Http::response('Not Found', 404),
        ]);

        $this->artisan('spatial:update-data')
            ->expectsOutputToContain('Error:')
            ->assertExitCode(1);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build Http::fake() responses that stub the OSM download, all DeprivationDataService
     * external calls, and the reload signal — returning the given CSV content from
     * a combined IMD+geometry response that produces exactly those rows.
     *
     * Rather than constructing realistic multi-page ArcGIS payloads, we stub the
     * IMD lookup to return a small set of codes and stub the geometry endpoints to
     * return matching features, then rely on the real service code to produce the
     * correct output.  For integration-style CSV validation tests we bypass the
     * service entirely and write the CSV directly (see writeCsvForTest).
     *
     * For simplicity, we write the final CSV ourselves into the temp path before
     * the command runs, using a mock DeprivationDataService bound in the container.
     */
    private function buildFakeHttpResponses(string $csvContent): array
    {
        // Write the CSV directly to the expected temp path so the command can
        // find it after "building".  We mock DeprivationDataService via the
        // service container so no real HTTP is made for deprivation data.
        $csvPath     = "{$this->dataDir}/uk_lsoa_quintile.csv";
        $tempCsvPath = "{$csvPath}.tmp";

        $mockService = new class($tempCsvPath, $csvContent) {
            public function __construct(private string $path, private string $csv) {}
            public function build(string $outputPath): void
            {
                file_put_contents($outputPath, $this->csv);
            }
        };

        app()->bind(\App\Services\DeprivationDataService::class, fn() => $mockService);

        $pbf = "\x00\x00\x00\x1a\x4f\x73\x6d\x48\x65\x61\x64\x65\x72\x00MOCK_PBF_DATA";

        return [
            'https://download.geofabrik.de/europe/great-britain-latest.osm.pbf' => Http::response($pbf, 200),
            'https://download.geofabrik.de/europe/ireland-and-northern-ireland-latest.osm.pbf' => Http::response($pbf, 200),
            'http://localhost:8195/v1/reload' => Http::response(['status' => 'ok'], 200),
        ];
    }

    /**
     * Generate a valid UK deprivation CSV (40000 rows) meeting all validation criteria:
     * - Total rows >= 40000
     * - E&W rows (lat 49-56, lng -6 to 2) >= 30000
     * - Scotland rows (lat > 55.3, lng < 0) >= 5000
     * - NI rows (lat 54-55.3, lng -8.5 to -5.3) >= 800
     * - Each quintile ~20% (within 15-30% range)
     * - All coordinates within UK bounds (lat 49-61.5, lng -9.5-2.2)
     */
    private function generateValidCsv(): string
    {
        $lines = ['lat,lng,quintile'];
        for ($i = 0; $i < 30100; $i++) {
            $lines[] = '51.5,-2.0,' . (($i % 5) + 1);
        }
        for ($i = 0; $i < 5100; $i++) {
            $lines[] = '57.0,-3.0,' . (($i % 5) + 1);
        }
        for ($i = 0; $i < 900; $i++) {
            $lines[] = '54.5,-7.0,' . (($i % 5) + 1);
        }
        for ($i = 0; $i < 3900; $i++) {
            $lines[] = '50.0,-1.0,' . (($i % 5) + 1);
        }
        return implode("\n", $lines) . "\n";
    }

    /**
     * Generate a CSV with valid row counts/coords but quintile 1 missing (0%).
     * This triggers the "unusual distribution" validation error.
     */
    private function generateCsvWithSkewedQuintiles(): string
    {
        $lines = ['lat,lng,quintile'];
        for ($i = 0; $i < 30100; $i++) {
            $lines[] = '51.5,-2.0,' . (($i % 4) + 2);
        }
        for ($i = 0; $i < 5100; $i++) {
            $lines[] = '57.0,-3.0,' . (($i % 4) + 2);
        }
        for ($i = 0; $i < 900; $i++) {
            $lines[] = '54.5,-7.0,' . (($i % 4) + 2);
        }
        for ($i = 0; $i < 3900; $i++) {
            $lines[] = '50.0,-1.0,' . (($i % 4) + 2);
        }
        return implode("\n", $lines) . "\n";
    }
}
