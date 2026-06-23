<?php

namespace App\Console\Commands\Ripple;

use App\Console\Concerns\PreventsOverlapping;
use App\Services\Ripple\RippleMembershipBackfillService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Backfill the Rippling Out auto-join mitigations onto members auto-joined before they existed:
 * mark rippled memberships, downgrade immediate -> daily, and send the one bundled intro email
 * (once per user). DRY-RUN BY DEFAULT - reports what it would do and changes nothing; pass --send
 * to actually apply changes and send the intro emails.
 */
class BackfillMembershipsCommand extends Command
{
    use PreventsOverlapping;

    protected $signature = 'ripple:backfill-memberships
                            {--user= : Restrict to a single user id}
                            {--limit=0 : Max users to process (0 = no limit)}
                            {--send : Apply changes and send intro emails (default: dry-run report only)}';

    protected $description = 'Backfill Rippling Out membership mitigations (flag rippled, downgrade immediate->daily, send one intro email) for already-rippled members';

    public function handle(RippleMembershipBackfillService $service): int
    {
        if (!$this->acquireLock()) {
            $this->info('Already running, exiting.');
            return Command::SUCCESS;
        }

        try {
            $send = (bool) $this->option('send');
            $userId = $this->option('user') !== null ? (int) $this->option('user') : null;
            $limit = (int) $this->option('limit');

            if (!$send) {
                $this->info('DRY RUN - no changes will be made and no emails sent. Pass --send to apply.');
            }

            $stats = $service->backfill($userId, $limit, $send);

            $prefix = $send ? 'Backfilled' : '[DRY RUN] Would backfill';
            $this->info("{$prefix}: {$stats['users']} user(s), {$stats['memberships']} rippled membership(s) "
                . "({$stats['flagged']} newly flagged, {$stats['downgraded']} downgraded immediate->daily), "
                . "{$stats['intros']} intro email(s).");
            Log::info('ripple:backfill-memberships complete', $stats + ['send' => $send]);

            return Command::SUCCESS;
        } finally {
            $this->releaseLock();
        }
    }
}
