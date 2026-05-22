<?php

namespace App\Console\Commands\Paf;

use App\Services\PafService;
use Illuminate\Console\Command;

class PreprocessPafCsvCommand extends Command
{
    protected $signature = 'paf:preprocess-csv
                            {--input= : Path to the raw PAF CSV file (required)}
                            {--output= : Path for the preprocessed output file (required)}';

    protected $description = 'Preprocess a raw PAF CSV file to replace empty fields with MySQL NULL sentinels';

    public function handle(PafService $service): int
    {
        $inputPath = $this->option('input');
        $outputPath = $this->option('output');

        if (!$inputPath) {
            $this->error('--input is required');
            return self::FAILURE;
        }

        if (!$outputPath) {
            $this->error('--output is required');
            return self::FAILURE;
        }

        if (!file_exists($inputPath)) {
            $this->error("Input file not found: {$inputPath}");
            return self::FAILURE;
        }

        try {
            $lines = $service->preprocessCsv($inputPath, $outputPath);
        } catch (\Exception $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->info("Preprocessed {$lines} line(s) from {$inputPath} → {$outputPath}");

        return self::SUCCESS;
    }
}
