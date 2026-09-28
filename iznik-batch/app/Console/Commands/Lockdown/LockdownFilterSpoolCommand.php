<?php

namespace App\Console\Commands\Lockdown;

use App\Services\Lockdown\LockdownFilterSpoolService;
use Illuminate\Console\Command;

/**
 * Removes a waiting spooled mail once the member content it names is no longer fit to
 * send (plan 2026-09-27-lockdown-switch.md, section 11.8) - see LockdownFilterSpoolService
 * for the checks. Run on every lockdown:triage pass while email is held, and once more as
 * the first step of `lockdown:off --surface=email` (which refuses to lift email if this
 * reports an error) - also runnable on its own at any time, since a file with no `about`
 * field is always left untouched.
 */
class LockdownFilterSpoolCommand extends Command
{
    protected $signature = 'lockdown:filter-spool';

    protected $description = 'Remove waiting spooled mail whose member content is no longer fit to send';

    public function handle(LockdownFilterSpoolService $filter): int
    {
        $stats = $filter->filter();

        $this->info(sprintf(
            'Checked %d, removed %d, errors %d.',
            $stats['checked'],
            $stats['removed'],
            $stats['errors']
        ));

        return $stats['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
