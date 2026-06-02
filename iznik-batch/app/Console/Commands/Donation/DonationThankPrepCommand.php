<?php

namespace App\Console\Commands\Donation;

use App\Services\DonationThankPrepService;
use App\Traits\LogsBatchJob;
use Illuminate\Console\Command;

/**
 * Daily thank-prep digest: a card-per-donation email aimed at the person
 * composing thank-you replies. Distinct from {@code mail:donations:summary}
 * which sends the simple finance-team status table.
 */
class DonationThankPrepCommand extends Command
{
    use LogsBatchJob;

    protected $signature = 'mail:donations:thank-prep
                            {--dry-run : Build the digest but do not send it}';

    protected $description = 'Send daily thank-prep digest (rich donor cards) to the thanks address';

    public function handle(DonationThankPrepService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no email will be sent.');
        }

        return $this->runWithLogging(function () use ($service, $dryRun) {
            $result = $service->sendDailyThankPrep($dryRun);

            $verb  = $dryRun ? 'Would send' : 'Sent';
            $total = number_format($result['total'], 2);
            $this->info("{$verb} thank-prep digest for {$result['donations']} donor(s) needing thanks, totalling £{$total}.");

            if ($result['donations'] === 0) {
                $this->info('No donations need thanks today — no email sent.');
            }

            return Command::SUCCESS;
        });
    }
}
