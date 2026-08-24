<?php

namespace App\Console\Commands\Donation;

use App\Services\DonationUserIdBackfillService;
use App\Traits\LogsBatchJob;
use Illuminate\Console\Command;

/**
 * Reconcile users_donations.userid against live accounts. Two populations:
 *
 *   1. donations never linked to any account (userid IS NULL) — the historical
 *      backlog from before the IPN handlers matched at receipt time; and
 *   2. donations stranded on a since-DELETED account (the donate/leave/rejoin
 *      gap) — re-pointed to the live account that now owns the payer email.
 *
 * The PayPal/Stripe IPN handlers match new donations at receipt, but neither
 * they nor the null-only backfill ever revisit population 2, and new strandings
 * appear whenever an account holding donations is later deleted without its
 * donations being reassigned — so this is now scheduled to run periodically.
 * Donations already on a LIVE account are deliberately left alone: that is the
 * duplicate-account case, resolved by a human account merge, not here.
 */
class CorrectDonationUserIdsCommand extends Command
{
    use LogsBatchJob;

    protected $signature = 'donations:correct-userids
                            {--dry-run : Report what would change without writing}
                            {--limit= : Only scan this many candidate donations}';

    protected $description = 'Reconcile donation userids: backfill unlinked donations and re-point donations stranded on deleted accounts to the live account owning the payer email.';

    public function handle(DonationUserIdBackfillService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit  = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        if ($dryRun) {
            $this->info('DRY RUN — no donations will be updated.');
        }

        return $this->runWithLogging(function () use ($service, $dryRun, $limit) {
            $r = $service->backfill($dryRun, $limit);

            $verb = $dryRun ? 'Would re-link' : 'Re-linked';
            $this->info(sprintf(
                '%s %d of %d candidate donation(s): %d unlinked backfilled, %d stranded on a deleted account re-pointed (%d by email/canon, %d by prior donation).',
                $verb,
                $r['updated'],
                $r['scanned'],
                $r['null_backfilled'],
                $r['deleted_repointed'],
                $r['matched_email'],
                $r['matched_prior']
            ));

            if ($r['scanned'] === 0) {
                $this->info('No candidate donations found.');
            }

            return Command::SUCCESS;
        });
    }
}
