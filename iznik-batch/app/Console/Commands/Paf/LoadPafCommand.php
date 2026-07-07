<?php

namespace App\Console\Commands\Paf;

use App\Services\PafService;
use Illuminate\Console\Command;

class LoadPafCommand extends Command
{
    protected $signature = 'paf:load
                            {--input= : Path to the PAF CSV input file (required)}';

    protected $description = 'Initial load of PAF (Postal Address File) address data into the database';

    public function handle(PafService $service): int
    {
        $inputPath = $this->option('input');

        if (!$inputPath) {
            $this->error('--input is required');
            return self::FAILURE;
        }

        if (!file_exists($inputPath)) {
            $this->error("Input file not found: {$inputPath}");
            return self::FAILURE;
        }

        $this->info("Loading PAF data from: {$inputPath}");

        try {
            [$processed, $inserted, $unknown] = $service->load(
                $inputPath,
                function (int $count, int $ins) {
                    $this->line("  Processed {$count}, inserted {$ins}...");
                }
            );
        } catch (\Exception $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->info("Done. Processed {$processed} records, inserted {$inserted} new addresses.");

        if ($unknown) {
            $this->warn(count($unknown) . " unknown postcode(s) skipped (e.g. " . implode(', ', array_slice($unknown, 0, 5)) . ")");
        }

        return self::SUCCESS;
    }
}
