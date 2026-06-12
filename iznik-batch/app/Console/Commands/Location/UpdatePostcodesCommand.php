<?php

namespace App\Console\Commands\Location;

use App\Services\DoogalService;
use Illuminate\Console\Command;

/**
 * Add new UK postcodes and refresh changed lat/lng from the Doogal Code-Point
 * dataset. Faithful migration of V1 scripts/cli/doogal.php and the cron/doogal
 * wrapper (which downloaded + unzipped the CSV before running it).
 */
class UpdatePostcodesCommand extends Command
{
    protected $signature = 'locations:update-postcodes
                            {--file= : Path to an already-extracted Code-Point/Doogal CSV (skips download)}
                            {--url= : Override the postcodes.zip download URL}
                            {--keep : Keep the downloaded/extracted temp files}';

    protected $description = 'Add new UK postcodes and refresh changed lat/lng from the Doogal dataset (V1 cli/doogal.php)';

    public function handle(DoogalService $service): int
    {
        $file = $this->option('file');
        $downloaded = false;

        if (!$file) {
            $this->info('Downloading postcode dataset...');
            try {
                $file = $service->fetchCsv($this->option('url'));
                $downloaded = true;
            } catch (\Throwable $e) {
                $this->error('Download failed: '.$e->getMessage());

                return self::FAILURE;
            }
        }

        if (!is_file($file)) {
            $this->error("CSV file not found: {$file}");

            return self::FAILURE;
        }

        $this->info("Processing postcodes from: {$file}");

        try {
            $result = $service->process($file, function (int $processed, int $added, int $updated) {
                $this->line("  ...{$processed} processed ({$added} added, {$updated} updated)");
            });
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            if ($downloaded && !$this->option('keep')) {
                @unlink($file);
                @rmdir(dirname($file));
            }
        }

        $this->info(
            "Done. Processed {$result['processed']}, added {$result['added']}, "
            ."updated {$result['updated']}, failed {$result['failed']}."
        );

        return self::SUCCESS;
    }
}
