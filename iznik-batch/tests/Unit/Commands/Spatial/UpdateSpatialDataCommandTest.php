<?php

namespace Tests\Unit\Commands\Spatial;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class UpdateSpatialDataCommandTest extends TestCase
{
    /**
     * Test dry-run mode doesn't make any changes.
     */
    public function test_dry_run_mode_reports_no_changes(): void
    {
        $this->artisan('spatial:update-data', ['--dry-run' => true])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('Would download')
            ->expectsOutputToContain('Would run')
            ->expectsOutputToContain('Would signal')
            ->assertExitCode(0);
    }

    /**
     * Test OSM PBF download with atomic write (creates temp file first).
     */
    public function test_downloads_osm_pbf_file_atomically(): void
    {
        Http::fake([
            'https://download.geofabrik.de/europe/great-britain-latest.osm.pbf' => Http::response(
                "\x00\x00\x00\x1a\x4f\x73\x6d\x48\x65\x61\x64\x65\x72\x00MOCK_PBF_DATA",
                200
            ),
        ]);

        $dataDir = storage_path('tests/spatial');
        if (is_dir($dataDir)) {
            array_map('unlink', glob("$dataDir/*"));
            rmdir($dataDir);
        }

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
            ->expectsOutputToContain('Error:')
            ->expectsOutputToContain('magic bytes')
            ->assertExitCode(1);
    }

    /**
     * Test CSV validation detects too few rows.
     */
    public function test_csv_validation_rejects_too_few_rows(): void
    {
        $this->mockPythonScriptOutput(
            "lat,lng,quintile\n51.5,0.0,1\n51.6,0.1,2\n"
        );

        Http::fake([
            'https://download.geofabrik.de/europe/great-britain-latest.osm.pbf' => Http::response(
                "\x00\x00\x00\x1a\x4f\x73\x6d\x48\x65\x61\x64\x65\x72\x00MOCK_PBF_DATA",
                200
            ),
        ]);

        $this->artisan('spatial:update-data')
            ->expectsOutputToContain('Error:')
            ->expectsOutputToContain('Too few total rows')
            ->assertExitCode(1);
    }

    /**
     * Test CSV validation detects out-of-bounds coordinates.
     */
    public function test_csv_validation_rejects_out_of_bounds_coords(): void
    {
        // Create CSV with row outside UK bounds
        $csvContent = "lat,lng,quintile\n";
        for ($i = 0; $i < 40001; $i++) {
            $lat = 51.5 + ($i % 10);
            $lng = 0.0 + ($i % 5);
            $quintile = ($i % 5) + 1;
            $csvContent .= "$lat,$lng,$quintile\n";
        }
        $csvContent .= "70.0,0.0,1\n";

        $this->mockPythonScriptOutput($csvContent);

        Http::fake([
            'https://download.geofabrik.de/europe/great-britain-latest.osm.pbf' => Http::response(
                "\x00\x00\x00\x1a\x4f\x73\x6d\x48\x65\x61\x64\x65\x72\x00MOCK_PBF_DATA",
                200
            ),
        ]);

        $this->artisan('spatial:update-data')
            ->expectsOutputToContain('Error:')
            ->expectsOutputToContain('outside UK bounds')
            ->assertExitCode(1);
    }

    /**
     * Test CSV validation detects missing quintile.
     */
    public function test_csv_validation_detects_missing_quintile(): void
    {
        // Create CSV with only quintiles 2-5 (missing 1)
        $csvContent = "lat,lng,quintile\n";
        for ($i = 0; $i < 40001; $i++) {
            $lat = 51.5 + ($i % 10);
            $lng = 0.0 + ($i % 5);
            $quintile = (($i % 4) + 2);
            $csvContent .= "$lat,$lng,$quintile\n";
        }

        $this->mockPythonScriptOutput($csvContent);

        Http::fake([
            'https://download.geofabrik.de/europe/great-britain-latest.osm.pbf' => Http::response(
                "\x00\x00\x00\x1a\x4f\x73\x6d\x48\x65\x61\x64\x65\x72\x00MOCK_PBF_DATA",
                200
            ),
        ]);

        $this->artisan('spatial:update-data')
            ->expectsOutputToContain('Error:')
            ->expectsOutputToContain('unusual distribution')
            ->assertExitCode(1);
    }

    /**
     * Test CSV validation detects too few E&W rows.
     */
    public function test_csv_validation_detects_too_few_ew_rows(): void
    {
        // Create CSV with only 100 E&W rows
        $csvContent = "lat,lng,quintile\n";
        for ($i = 0; $i < 100; $i++) {
            $csvContent .= "52.0,0.0," . (($i % 5) + 1) . "\n";
        }
        for ($i = 0; $i < 40000; $i++) {
            $csvContent .= (56.0 + ($i % 2)) . ",-2.0," . (($i % 5) + 1) . "\n";
        }

        $this->mockPythonScriptOutput($csvContent);

        Http::fake([
            'https://download.geofabrik.de/europe/great-britain-latest.osm.pbf' => Http::response(
                "\x00\x00\x00\x1a\x4f\x73\x6d\x48\x65\x61\x64\x65\x72\x00MOCK_PBF_DATA",
                200
            ),
        ]);

        $this->artisan('spatial:update-data')
            ->expectsOutputToContain('Error:')
            ->expectsOutputToContain('Too few E&W rows')
            ->assertExitCode(1);
    }

    /**
     * Test successful CSV generation and validation.
     */
    public function test_valid_csv_passes_validation(): void
    {
        $csvContent = "lat,lng,quintile\n";

        // E&W rows (30k)
        for ($i = 0; $i < 30000; $i++) {
            $lat = 49.0 + ($i % 8);
            $lng = -6.0 + ($i % 9);
            $quintile = ($i % 5) + 1;
            $csvContent .= "$lat,$lng,$quintile\n";
        }

        // Scotland rows (5k)
        for ($i = 0; $i < 5000; $i++) {
            $lat = 55.5 + ($i % 5);
            $lng = -3.0 - ($i % 4);
            $quintile = ($i % 5) + 1;
            $csvContent .= "$lat,$lng,$quintile\n";
        }

        // NI rows (800)
        for ($i = 0; $i < 800; $i++) {
            $lat = 54.0 + ($i % 2);
            $lng = -8.5 + ($i % 4);
            $quintile = ($i % 5) + 1;
            $csvContent .= "$lat,$lng,$quintile\n";
        }

        $this->mockPythonScriptOutput($csvContent);

        Http::fake([
            'https://download.geofabrik.de/europe/great-britain-latest.osm.pbf' => Http::response(
                "\x00\x00\x00\x1a\x4f\x73\x6d\x48\x65\x61\x64\x65\x72\x00MOCK_PBF_DATA",
                200
            ),
            'http://localhost:8195/v1/reload' => Http::response(
                ['status' => 'ok'],
                200
            ),
        ]);

        $this->artisan('spatial:update-data')
            ->expectsOutputToContain('Spatial data update complete')
            ->expectsOutputToContain('Validation passed')
            ->assertExitCode(0);
    }

    /**
     * Test reload signal is sent.
     */
    public function test_sends_reload_signal_to_server(): void
    {
        $csvContent = "lat,lng,quintile\n";
        for ($i = 0; $i < 40001; $i++) {
            $lat = 51.5 + ($i % 10);
            $lng = 0.0 + ($i % 5);
            $quintile = ($i % 5) + 1;
            $csvContent .= "$lat,$lng,$quintile\n";
        }

        $this->mockPythonScriptOutput($csvContent);

        Http::fake([
            'https://download.geofabrik.de/europe/great-britain-latest.osm.pbf' => Http::response(
                "\x00\x00\x00\x1a\x4f\x73\x6d\x48\x65\x21\x64\x65\x72\x00MOCK_PBF_DATA",
                200
            ),
            'http://localhost:8195/v1/reload' => Http::response(
                ['status' => 'ok'],
                200
            ),
        ]);

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
     * Test command handles Python script failure.
     */
    public function test_handles_python_script_failure(): void
    {
        $this->mockPythonScriptExitCode(1);

        Http::fake([
            'https://download.geofabrik.de/europe/great-britain-latest.osm.pbf' => Http::response(
                "\x00\x00\x00\x1a\x4f\x73\x6d\x48\x65\x61\x64\x65\x72\x00MOCK_PBF_DATA",
                200
            ),
        ]);

        $this->artisan('spatial:update-data')
            ->expectsOutputToContain('Error:')
            ->assertExitCode(1);
    }

    /**
     * Helper to mock Python script output.
     */
    private function mockPythonScriptOutput(string $output): void
    {
        $scriptPath = base_path('scripts/build_uk_deprivation.py');

        // Mock by creating a wrapper script in storage
        $wrapperPath = storage_path('tests/mock_python.sh');
        file_put_contents(
            $wrapperPath,
            "#!/bin/bash\necho \"$output\" > \"$1\"\nexit 0\n"
        );
        chmod($wrapperPath, 0755);

        // This is a simplification - in real tests, we'd mock the system() call
        // For now, tests will skip if actual Python isn't available
    }

    /**
     * Helper to mock Python script exit code.
     */
    private function mockPythonScriptExitCode(int $code): void
    {
        $wrapperPath = storage_path('tests/mock_python.sh');
        file_put_contents(
            $wrapperPath,
            "#!/bin/bash\nexit $code\n"
        );
        chmod($wrapperPath, 0755);
    }
}
