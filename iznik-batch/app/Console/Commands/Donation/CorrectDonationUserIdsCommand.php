<?php

namespace App\Console\Commands\Donation;

use App\Services\DonationUserIdBackfillService;
use App\Traits\LogsBatchJob;
use Illuminate\Console\Command;

/**
 * One-shot backfill of users_donations.userid for the historical backlog of
 * unmatched donations. Deliberately NOT scheduled — the PayPal/Stripe IPN
 * handlers now match at receipt time (email/canon/prior donation); this just
 * cleans up donations that landed before that logic existed.
 */
class CorrectDonationUserIdsCommand extends Command
{
    use LogsBatchJob;

    protected $signature = 'donations:correct-userids
                            {--dry-run : Report what would change without writing}
                            {--limit= : Only scan this many unmatched donations}';

    protected $description = 'Backfill unmatched donations to accounts by email/canon or a prior donation from the same Payer (one-shot; not scheduled).';

    public function handle(DonationUserIdBackfillService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit  = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        if ($dryRun) {
            $this->info('DRY RUN — no donations will be updated.');
        }

        return $this->runWithLogging(function () use ($service, $dryRun, $limit) {
            $r = $service->backfill($dryRun, $limit);

            $verb = $dryRun ? 'Would link' : 'Linked';
            $this->info(sprintf(
                '%s %d of %d unmatched donation(s): %d by email/canon, %d by prior donation.',
                $verb,
                $r['updated'],
                $r['scanned'],
                $r['matched_email'],
                $r['matched_prior']
            ));

            if ($r['scanned'] === 0) {
                $this->info('No unmatched donations found.');
            }

            return Command::SUCCESS;
        });
    }
}
