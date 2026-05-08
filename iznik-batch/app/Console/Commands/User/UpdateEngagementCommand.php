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
            if ($this->option('dry-run')) {
                $this->info('Dry run — no changes made.');
                return Command::SUCCESS;
            }

            Log::info('Starting user engagement update');

            $count = $service->updateEngagement();

            $this->info("{$count} user(s) updated");
            Log::info('User engagement update complete', ['count' => $count]);

            return Command::SUCCESS;
        } finally {
            $this->releaseLock();
        }
    }
}
