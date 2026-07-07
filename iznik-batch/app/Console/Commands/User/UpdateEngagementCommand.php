<?php

namespace App\Console\Commands\User;

use App\Console\Concerns\PreventsOverlapping;
use App\Services\EngageUpdateService;
use App\Traits\GracefulShutdown;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateEngagementCommand extends Command
{
    use PreventsOverlapping;
    use GracefulShutdown;

    protected $signature = 'users:update-engagement
                            {--dry-run : Show what would be updated without making changes}';

    protected $description = 'Update user engagement classifications based on recent activity';

    public function handle(EngageUpdateService $service): int
    {
        if (!$this->acquireLock()) {
            $this->info('Already running, exiting.');
            return Command::SUCCESS;
        }

        try {
            $dryRun = (bool) $this->option('dry-run');

            if ($dryRun) {
                $this->info('Dry run — counting transitions but not writing.');
            } else {
                Log::info('Starting user engagement update');
            }

            $stats = $service->updateEngagement($dryRun);

            $verb = $dryRun ? 'Would update' : 'Updated';
            $this->info("{$verb} {$stats['total']} user(s):");
            $this->line(sprintf('  NULL → New:                       %d', $stats['null_to_new']));
            $this->line(sprintf('  NULL → Inactive:                  %d', $stats['null_to_inactive']));
            $this->line(sprintf('  New/Occasional → Inactive:        %d', $stats['new_or_occasional_to_inactive']));
            $this->line(sprintf('  * → Dormant (>182d):              %d', $stats['to_dormant']));
            $this->line(sprintf('  New/Inactive/Dormant → Occasional: %d', $stats['to_occasional']));
            $this->line(sprintf('  Occasional → Frequent:            %d', $stats['occasional_to_frequent']));
            $this->line(sprintf('  Frequent → Obsessed:              %d', $stats['frequent_to_obsessed']));
            $this->line(sprintf('  Obsessed → Frequent:              %d', $stats['obsessed_to_frequent']));

            if (!$dryRun) {
                Log::info('User engagement update complete', $stats);
            }

            return Command::SUCCESS;
        } finally {
            $this->releaseLock();
        }
    }
}
