<?php

namespace App\Console\Commands\Volunteering;

use App\Services\VolunteeringMaintenanceService;
use App\Traits\LogsBatchJob;
use Illuminate\Console\Command;

class MaintainVolunteeringCommand extends Command
{
    use LogsBatchJob;

    protected $signature = 'volunteering:maintain
                            {--dry-run : Report what would change without emailing or expiring}';

    protected $description = 'Ask owners of dateless volunteering opportunities to renew, and expire stale ones';

    public function handle(VolunteeringMaintenanceService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no emails will be sent and nothing will be expired.');
        }

        return $this->runWithLogging(function () use ($service, $dryRun) {
            // V1 order (cron/volunteering.php): askRenew() then expire(). The ~7-day
            // gap between ASK_AGE (24) and EXPIRE_AGE (31) means owners are warned
            // about a week before an opportunity would expire.
            $asked = $service->askRenew(null, $dryRun);
            $expired = $service->expire(null, $dryRun);

            if ($dryRun) {
                $this->info("Would ask {$asked} owner(s) to renew; would expire {$expired} opportunities.");
            } else {
                $this->info("Asked {$asked} owner(s) to renew; expired {$expired} opportunities.");
            }

            return Command::SUCCESS;
        });
    }
}
