<?php

namespace App\Console\Commands\Spam;

use App\Services\SpamCleanupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckSpammersCommand extends Command
{
    protected $signature = 'users:remove-spammers
                            {--dry-run : Show counts without making changes}';

    protected $description = 'Remove spam members from groups and clean up their content (V1: check_spammers.php)';

    public function handle(SpamCleanupService $service): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no changes will be made.');

            return Command::SUCCESS;
        }

        $removed = $service->removeSpamMembers();

        $this->info("users:remove-spammers complete — removed: {$removed}");
        Log::info('users:remove-spammers complete', ['removed' => $removed]);

        return Command::SUCCESS;
    }
}
