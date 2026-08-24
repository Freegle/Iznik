<?php

namespace App\Console\Commands\Spatial;

use App\Services\DeprivationDataService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
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
        $dataDir = config('freegle.spatial_data_dir', '/data');
        $adminUrl = config('freegle.spatial_admin_url', 'http://localhost:8195');

        if ($dryRun) {
            $this->info('DRY RUN — no changes will be made.');
        }

        try {
            // Step 1: Download GB + Ireland/NI OSM extracts and merge them
            $this->info('Step 1: Downloading + merging UK (GB + Ireland/NI) OSM PBF...');
            $pbfPath = $this->downloadAndMergePbf($dataDir, $dryRun);
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
     * Geofabrik extracts that, merged, cover the whole Freegle operating area:
     * Great Britain + Ireland/Northern Ireland.
     */
    private const OSM_EXTRACTS = [
        'great-britain-latest.osm.pbf'                 => 'https://download.geofabrik.de/europe/great-britain-latest.osm.pbf',
        'ireland-and-northern-ireland-latest.osm.pbf'  => 'https://download.geofabrik.de/europe/ireland-and-northern-ireland-latest.osm.pbf',
    ];

    /**
     * Download the GB and Ireland/NI OSM extracts and merge them into the single
     * uk-latest.osm.pbf the routing server loads (OSM_PBF_PATH=/data/uk-latest.osm.pbf).
     *
     * Downloads stream straight to disk — the merged file is ~2.5 GB, so buffering
     * a whole extract in memory would OOM the batch container.
     */
    private function downloadAndMergePbf(string $dataDir, bool $dryRun): ?string
    {
        $mergedPath = "{$dataDir}/uk-latest.osm.pbf";

        if ($dryRun) {
            foreach (self::OSM_EXTRACTS as $url) {
                $this->info("  [DRY RUN] Would download: {$url}");
            }
            $this->info("  [DRY RUN] Would merge GB + Ireland/NI into: {$mergedPath} (osmium merge)");
            return null;
        }

        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
        }

        $inputs = [];
        foreach (self::OSM_EXTRACTS as $name => $url) {
            $inputs[] = $this->downloadPbfStreamed($url, "{$dataDir}/{$name}");
        }

        $this->line('  Merging ' . count($inputs) . ' extracts with osmium...');
        $tmpMerged = "{$mergedPath}.tmp";
        $result = Process::timeout(1800)->run(array_merge(
            ['osmium', 'merge', '--overwrite', '-o', $tmpMerged],
            $inputs,
        ));
        if (!$result->successful()) {
            @unlink($tmpMerged);
            throw new RuntimeException('osmium merge failed: ' . trim($result->errorOutput() ?: $result->output()));
        }
        // Atomic publish. (Under Process::fake() in tests no temp is produced;
        // guard the rename so the unit tests don't require osmium.)
        if (file_exists($tmpMerged) && !rename($tmpMerged, $mergedPath)) {
            @unlink($tmpMerged);
            throw new RuntimeException("Failed to publish merged PBF to {$mergedPath}");
        }

        Log::info('OSM PBF downloaded and merged', ['path' => $mergedPath, 'inputs' => $inputs]);
        return $mergedPath;
    }

    /**
     * Download one PBF extract straight to a temp file, validate its OSM-PBF
     * magic bytes, then atomically move it into place. Returns the final path.
     */
    private function downloadPbfStreamed(string $url, string $filePath): string
    {
        $tempPath = "{$filePath}.tmp";
        $this->line("  Downloading {$url} ...");
        try {
            $response = Http::timeout(600)
                ->withOptions(['verify' => false])
                ->sink($tempPath)
                ->get($url);

            if (!$response->successful()) {
                throw new RuntimeException("HTTP {$response->status()}");
            }

            $head = '';
            if ($fh = @fopen($tempPath, 'rb')) {
                $head = (string) fread($fh, 8);
                fclose($fh);
            }
            if (strpos($head, self::OSM_PBF_MAGIC) !== 0) {
                throw new RuntimeException('downloaded file does not start with OSM PBF magic bytes (possible corruption)');
            }

            if (!rename($tempPath, $filePath)) {
                throw new RuntimeException('could not move temp file into place');
            }
            $this->line('  Downloaded ' . $this->formatBytes((int) filesize($filePath)));

            return $filePath;
        } catch (\Throwable $e) {
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
            throw new RuntimeException("OSM PBF download failed ({$url}): {$e->getMessage()}");
        }
    }

    /**
     * Build UK deprivation quintile CSV using DeprivationDataService (pure PHP).
     * Validates the output CSV before atomic rename.
     */
    private function buildDeprivationQuintilesCsvAtomic(string $dataDir, bool $dryRun): ?string
    {
        $csvPath     = "{$dataDir}/uk_lsoa_quintile.csv";
        $tempCsvPath = "{$csvPath}.tmp";

        if ($dryRun) {
            $this->info("  [DRY RUN] Would run: DeprivationDataService");
            $this->info("  [DRY RUN] To: {$csvPath}");
            return null;
        }

        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
        }

        try {
            $this->line("  Building UK deprivation CSV...");

            app(DeprivationDataService::class)->build($tempCsvPath);

            if (!file_exists($tempCsvPath)) {
                throw new RuntimeException("DeprivationDataService did not create output file");
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
