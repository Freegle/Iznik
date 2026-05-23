<?php

namespace App\Console\Commands\Paf;

use App\Services\PafService;
use Illuminate\Console\Command;

class UpdatePafCommand extends Command
{
    protected $signature = 'paf:update
                            {--input= : Path to the PAF CSV input file (required)}';

    protected $description = 'Update PAF (Postal Address File) address records from a new data file';

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

        $this->info("Updating PAF data from: {$inputPath}");

        try {
            [$processed, $updated] = $service->update(
                $inputPath,
                function (int $count, int $upd) {
                    $this->line("  Processed {$count}, updated {$upd}...");
                }
            );
        } catch (\Exception $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->info("Done. Processed {$processed} records, updated {$updated} address(es).");

        return self::SUCCESS;
    }
}
