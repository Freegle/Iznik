<?php

namespace App\Console\Commands\Spatial;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class UpdateSpatialDataCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'spatial:update-data {--dry-run : Show what would be done without making changes}';

    /**
     * The console command description.
     */
    protected $description = 'Download UK OSM data and rebuild deprivation quintile CSV for spatial server';

    private const OSM_PBF_MAGIC = "\x00\x00\x00";
    private const OSM_CHUNK_SIZE = 8192;
    private const CSV_MIN_ROWS = 40000;
    private const CSV_MIN_EW_ROWS = 30000;
    private const CSV_MIN_SCOT_ROWS = 5000;
    private const CSV_MIN_NI_ROWS = 800;
    private const CSV_LAT_MIN = 49.0;
    private const CSV_LAT_MAX = 61.5;
    private const CSV_LNG_MIN = -9.5;
    private const CSV_LNG_MAX = 2.2;
    private const QUINTILE_MIN_PCT = 15;
    private const QUINTILE_MAX_PCT = 30;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $dataDir = env('SPATIAL_DATA_DIR', '/data');
        $adminUrl = env('SPATIAL_ADMIN_URL', 'http://localhost:8195');

        if ($dryRun) {
            $this->info('DRY RUN — no changes will be made.');
        }

        try {
            // Step 1: Download UK OSM PBF file (with atomic write)
            $this->info('Step 1: Downloading UK OSM PBF file...');
            $pbfPath = $this->downloadOsmPbfAtomic($dataDir, $dryRun);
            if ($pbfPath) {
                $this->info("  Downloaded to: {$pbfPath}");
            }

            // Step 2: Rebuild UK deprivation quintile CSV (with validation)
            $this->info('Step 2: Rebuilding UK deprivation quintile CSV...');
            $csvPath = $this->buildDeprivationQuintilesCsvAtomic($dataDir, $dryRun);
            if ($csvPath) {
                $this->info("  Written to: {$csvPath}");
            }

            // Step 3: Signal Go spatial server to reload
            $this->info('Step 3: Signaling spatial server to reload...');
            if (!$dryRun) {
                $this->signalServerReload($adminUrl);
                $this->info('  Reload signal sent');
            } else {
                $this->info("  [DRY RUN] Would signal: {$adminUrl}/v1/reload");
            }

            $this->newLine();
            $this->info('Spatial data update complete.');
            Log::info('Spatial data update complete');

            return Command::SUCCESS;
        } catch (RuntimeException $e) {
            $this->error("Error: {$e->getMessage()}");
            Log::error("Spatial data update failed: {$e->getMessage()}");
            $this->reportSentryError("Spatial data update failed", $e);
            return Command::FAILURE;
        }
    }

    /**
     * Download UK OSM PBF file with streaming to temp file and atomic rename.
     * Validates magic bytes to detect corruption/interruption.
     */
    private function downloadOsmPbfAtomic(string $dataDir, bool $dryRun): ?string
    {
        $url = 'https://download.geofabrik.de/europe/great-britain-latest.osm.pbf';
        $filePath = "{$dataDir}/great-britain-latest.osm.pbf";
        $tempPath = "{$filePath}.tmp";

        if ($dryRun) {
            $this->info("  [DRY RUN] Would download: {$url}");
            $this->info("  [DRY RUN] To: {$filePath}");
            return null;
        }

        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
        }

        try {
            $this->line("  Downloading from {$url}...");

            $tempHandle = fopen($tempPath, 'w');
            if (!$tempHandle) {
                throw new RuntimeException("Could not open temp file for writing: {$tempPath}");
            }

            try {
                $response = Http::timeout(600)
                    ->withOptions(['verify' => false])
                    ->get($url);

                if (!$response->successful()) {
                    throw new RuntimeException("Failed to download PBF file: HTTP {$response->status()}");
                }

                $body = $response->body();
                $size = 0;

                // Stream write in chunks
                for ($i = 0; $i < strlen($body); $i += self::OSM_CHUNK_SIZE) {
                    $chunk = substr($body, $i, self::OSM_CHUNK_SIZE);
                    if (fwrite($tempHandle, $chunk) === false) {
                        throw new RuntimeException("Failed to write chunk to temp file");
                    }
                    $size += strlen($chunk);
                }

                fflush($tempHandle);

                // Validate magic bytes
                if (strpos($body, self::OSM_PBF_MAGIC) !== 0) {
                    throw new RuntimeException("Downloaded file does not start with OSM PBF magic bytes (possible corruption)");
                }

                $this->line("  Downloaded " . $this->formatBytes($size));
            } finally {
                fclose($tempHandle);
            }

            // Atomic rename
            if (!rename($tempPath, $filePath)) {
                @unlink($tempPath);
                throw new RuntimeException("Failed to rename temp PBF file to production");
            }

            Log::info('OSM PBF file downloaded', [
                'url' => $url,
                'path' => $filePath,
                'size' => filesize($filePath),
            ]);

            return $filePath;
        } catch (\Exception $e) {
            // Clean up temp file on any failure
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
            throw new RuntimeException("OSM PBF download failed: {$e->getMessage()}");
        }
    }

    /**
     * Build UK deprivation quintile CSV by calling Python script (which handles coordinate transformation).
     * Validates the output CSV before atomic rename.
     */
    private function buildDeprivationQuintilesCsvAtomic(string $dataDir, bool $dryRun): ?string
    {
        $scriptPath = base_path('scripts/build_uk_deprivation.py');
        $csvPath = "{$dataDir}/uk_lsoa_quintile.csv";
        $tempCsvPath = "{$csvPath}.tmp";

        if ($dryRun) {
            $this->info("  [DRY RUN] Would run: {$scriptPath}");
            $this->info("  [DRY RUN] To: {$csvPath}");
            return null;
        }

        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
        }

        try {
            // Check Python script exists
            if (!file_exists($scriptPath)) {
                throw new RuntimeException("Python script not found: {$scriptPath}");
            }

            $this->line("  Running Python deprivation builder...");

            // Run Python script, passing temp CSV path as argument
            $cmd = sprintf(
                "python3 %s %s 2>&1; echo \"EXIT_CODE:\$?\"",
                escapeshellarg($scriptPath),
                escapeshellarg($tempCsvPath)
            );

            $output = shell_exec($cmd);

            // Extract exit code from output
            $returnCode = 0;
            if (preg_match('/EXIT_CODE:(\d+)$/', $output, $matches)) {
                $returnCode = (int) $matches[1];
                $output = preg_replace('/EXIT_CODE:\d+$/', '', $output);
            }

            // Log Python output for debugging
            if (trim($output)) {
                Log::info('Python deprivation script output', ['output' => trim($output)]);
            }

            if ($returnCode !== 0) {
                @unlink($tempCsvPath);
                throw new RuntimeException("Python script failed (exit code {$returnCode})");
            }

            if (!file_exists($tempCsvPath)) {
                throw new RuntimeException("Python script did not create output file");
            }

            // Validate CSV
            $this->line("  Validating CSV...");
            $stats = $this->validateDeprivationCsv($tempCsvPath);

            $this->line("  Validation passed:");
            $this->line("    Total rows: {$stats['total_rows']}");
            $this->line("    E&W rows: {$stats['ew_rows']}");
            $this->line("    Scotland rows: {$stats['scot_rows']}");
            $this->line("    NI rows: {$stats['ni_rows']}");
            $this->line("    Quintile distribution: " . json_encode($stats['quintile_dist']));

            // Atomic rename
            if (!rename($tempCsvPath, $csvPath)) {
                @unlink($tempCsvPath);
                throw new RuntimeException("Failed to rename temp CSV file to production");
            }

            $fileSize = filesize($csvPath);
            $this->line("  Wrote " . $this->formatBytes($fileSize));

            Log::info('UK deprivation quintile CSV rebuilt', [
                'path' => $csvPath,
                'rows' => $stats['total_rows'],
                'size' => $fileSize,
                'ew_rows' => $stats['ew_rows'],
                'scot_rows' => $stats['scot_rows'],
                'ni_rows' => $stats['ni_rows'],
                'quintile_dist' => $stats['quintile_dist'],
            ]);

            return $csvPath;
        } catch (\Exception $e) {
            @unlink($tempCsvPath);
            throw new RuntimeException("Deprivation CSV build failed: {$e->getMessage()}");
        }
    }

    /**
     * Validate deprivation quintile CSV for data quality issues.
     * Returns stats array on success, throws RuntimeException on validation failure.
     */
    private function validateDeprivationCsv(string $csvPath): array
    {
        $handle = fopen($csvPath, 'r');
        if (!$handle) {
            throw new RuntimeException("Could not open CSV for validation");
        }

        try {
            // Read header
            $header = fgetcsv($handle);
            if (!$header || count($header) < 3) {
                throw new RuntimeException("CSV header invalid or missing");
            }

            if ($header[0] !== 'lat' || $header[1] !== 'lng' || $header[2] !== 'quintile') {
                throw new RuntimeException("CSV header columns incorrect: " . implode(',', $header));
            }

            // Count rows and validate data
            $rowCount = 0;
            $quintileCounts = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
            $ewRows = 0;
            $scotRows = 0;
            $niRows = 0;
            $outOfBounds = 0;

            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) < 3) {
                    continue;
                }

                $lat = (float) $row[0];
                $lng = (float) $row[1];
                $quintile = (int) $row[2];

                // Bounds check
                if ($lat < self::CSV_LAT_MIN || $lat > self::CSV_LAT_MAX ||
                    $lng < self::CSV_LNG_MIN || $lng > self::CSV_LNG_MAX) {
                    $outOfBounds++;
                    continue;
                }

                // Regional classification
                if ($lat >= 49 && $lat <= 56 && $lng >= -6 && $lng <= 2) {
                    $ewRows++;
                } elseif ($lat > 55.3 && $lng < 0) {
                    $scotRows++;
                } elseif ($lat >= 54 && $lat <= 55.3 && $lng >= -8.5 && $lng <= -5.3) {
                    $niRows++;
                }

                // Quintile validation
                if ($quintile < 1 || $quintile > 5) {
                    throw new RuntimeException("Invalid quintile value: {$quintile}");
                }
                $quintileCounts[$quintile]++;

                $rowCount++;
            }

            // Validate counts
            if ($rowCount < self::CSV_MIN_ROWS) {
                throw new RuntimeException("Too few total rows: {$rowCount} (expected >= " . self::CSV_MIN_ROWS . ")");
            }

            if ($outOfBounds > 0) {
                throw new RuntimeException("Found {$outOfBounds} rows outside UK bounds");
            }

            if ($ewRows < self::CSV_MIN_EW_ROWS) {
                throw new RuntimeException("Too few E&W rows: {$ewRows} (expected >= " . self::CSV_MIN_EW_ROWS . ")");
            }

            if ($scotRows < self::CSV_MIN_SCOT_ROWS) {
                throw new RuntimeException("Too few Scotland rows: {$scotRows} (expected >= " . self::CSV_MIN_SCOT_ROWS . ")");
            }

            if ($niRows < self::CSV_MIN_NI_ROWS) {
                throw new RuntimeException("Too few NI rows: {$niRows} (expected >= " . self::CSV_MIN_NI_ROWS . ")");
            }

            // Quintile distribution check
            $quintileDist = [];
            foreach ($quintileCounts as $q => $count) {
                $pct = ($rowCount > 0) ? ($count / $rowCount) * 100 : 0;
                $quintileDist[$q] = round($pct, 1);

                if ($pct < self::QUINTILE_MIN_PCT || $pct > self::QUINTILE_MAX_PCT) {
                    throw new RuntimeException(
                        "Quintile {$q} has unusual distribution: {$pct}% (expected 15-30%)"
                    );
                }
            }

            return [
                'total_rows' => $rowCount,
                'ew_rows' => $ewRows,
                'scot_rows' => $scotRows,
                'ni_rows' => $niRows,
                'quintile_dist' => $quintileDist,
            ];
        } finally {
            fclose($handle);
        }
    }

    /**
     * Signal the Go spatial server to reload data via HTTP POST.
     */
    private function signalServerReload(string $adminUrl): void
    {
        try {
            $url = rtrim($adminUrl, '/') . '/v1/reload';

            $response = Http::timeout(10)
                ->withOptions(['verify' => false])
                ->post($url);

            if (!$response->successful()) {
                throw new RuntimeException("Reload signal failed: HTTP {$response->status()}");
            }

            Log::info('Spatial server reload signal sent', ['url' => $url]);
        } catch (\Exception $e) {
            throw new RuntimeException("Failed to signal server reload: {$e->getMessage()}");
        }
    }

    /**
     * Report error to Sentry if available.
     */
    private function reportSentryError(string $message, \Exception $e): void
    {
        if (app()->bound('sentry')) {
            app('sentry')->captureException($e);
        }
    }

    /**
     * Helper to format bytes as human-readable string.
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
