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
                            {--dry-run : Build the digest but do not send it}
                            {--today : Select today\'s donations and leave the high-water mark untouched (for ad-hoc resends)}
                            {--recipient= : Send to this address instead of the configured thanks address}';

    protected $description = 'Send per-donation thank-prep emails (rich donor cards) to the thanks address';

    public function handle(DonationThankPrepService $service): int
    {
        $dryRun    = (bool) $this->option('dry-run');
        $todayOnly = (bool) $this->option('today');
        $recipient = $this->option('recipient') ?: null;

        if ($dryRun) {
            $this->info('DRY RUN — no email will be sent.');
        }
        if ($todayOnly) {
            $this->info("TODAY mode — selecting today's donations; the high-water mark will not change.");
        }
        if ($recipient) {
            $this->info("Recipient override: {$recipient}");
        }

        return $this->runWithLogging(function () use ($service, $dryRun, $recipient, $todayOnly) {
            $result = $service->sendDailyThankPrep($dryRun, $recipient, $todayOnly);

            $verb  = $dryRun ? 'Would send' : 'Sent';
            $total = number_format($result['total'], 2);
            $this->info("{$verb} {$result['donations']} thank-prep email(s) for {$result['donations']} donor(s) needing thanks (of {$result['examined']} new donation(s) examined), totalling £{$total}.");

            if ($result['donations'] === 0) {
                $this->info('No donations need thanks today — no email sent.');
            }

            return Command::SUCCESS;
        });
    }
}
