<?php

namespace App\Console\Commands\Donation;

use App\Services\DonationSummaryService;
use App\Traits\LogsBatchJob;
use Illuminate\Console\Command;

class DonationSummaryCommand extends Command
{
    use LogsBatchJob;

    protected $signature = 'mail:donations:summary
                            {--dry-run : Build the email but do not send it}';

    protected $description = 'Send daily donation summary email to the fundraising address';

    public function handle(DonationSummaryService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no email will be sent.');
        }

        return $this->runWithLogging(function () use ($service, $dryRun) {
            $result = $service->sendDailySummary($dryRun);

            $verb = $dryRun ? 'Would send' : 'Sent';
            $total = number_format($result['total'], 2);
            $this->info("{$verb} summary for {$result['donations']} donation(s) totalling £{$total}.");

            if ($result['donations'] === 0) {
                $this->info('No donations today — no email sent.');
            }

            return Command::SUCCESS;
        });
    }
}
