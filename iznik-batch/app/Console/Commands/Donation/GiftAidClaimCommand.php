<?php

namespace App\Console\Commands\Donation;

use App\Services\GiftAidClaimService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GiftAidClaimCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'donations:giftaid-claim
        {--dry-run : Preview the CSV output without marking donations as claimed or invalidating records}
        {--output= : Write CSV output to this file path instead of stdout}
        {--end-date= : Only include donations on or before this date (YYYY-MM-DD, inclusive)}';

    /**
     * The console command description.
     */
    protected $description = 'Generate HMRC Gift Aid claim CSV for reviewable donations';

    /**
     * Execute the console command.
     */
    public function handle(GiftAidClaimService $claimService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $outputPath = $this->option('output');
        $endDate = $this->option('end-date');

        if ($endDate !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
            $this->error("Invalid --end-date '{$endDate}'. Expected YYYY-MM-DD.");

            return Command::FAILURE;
        }

        if ($dryRun) {
            $this->warn('DRY RUN — no donations will be marked as claimed, no records invalidated');
        }

        if ($endDate !== null) {
            $this->info("Limiting donations to those on or before {$endDate} (inclusive)");
        }

        Log::info('Starting Gift Aid claim', ['dry_run' => $dryRun, 'output' => $outputPath, 'end_date' => $endDate]);

        $result = $claimService->generateClaim($dryRun, function (array $row) use ($outputPath) {
            // Rows are streamed one at a time via this callback
            if ($outputPath === null) {
                $this->outputCsvRow($row);
            }
        }, $outputPath, $endDate);

        $this->info("Total claimed: £{$result['total']}");
        $this->info("Rows output: {$result['rows']}");

        if ($result['invalid'] > 0) {
            $this->warn("Invalid records reset for review: {$result['invalid']}");
        }

        if ($dryRun) {
            $this->warn('DRY RUN complete — no changes written to database');
        }

        Log::info('Gift Aid claim complete', $result);

        return Command::SUCCESS;
    }

    /**
     * Write a single row as CSV to stdout.
     *
     * @param  string[]  $row
     */
    private function outputCsvRow(array $row): void
    {
        $handle = fopen('php://stdout', 'w');
        fputcsv($handle, $row);
        fclose($handle);
    }
}
