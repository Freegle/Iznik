<?php

namespace App\Console\Commands\User;

use App\Console\Concerns\PreventsOverlapping;
use App\Services\Mail\Incoming\BounceService;
use App\Services\UserManagementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessBouncedEmailsCommand extends Command
{
    use PreventsOverlapping;

    protected $signature = 'mail:bounced {--dry-run : Show what would be processed without making changes}';

    protected $description = 'Process bounced emails and mark them as invalid';

    public function handle(UserManagementService $userService, BounceService $bounceService): int
    {
        if (! $this->acquireLock()) {
            $this->info('Already running, exiting.');

            return Command::SUCCESS;
        }

        try {
            $dryRun = $this->option('dry-run');

            if ($dryRun) {
                $this->info('DRY RUN — no changes will be made.');

                return Command::SUCCESS;
            }

            Log::info('Starting bounced email processing');
            $this->info('Processing bounced emails...');

            $stats = $userService->processBouncedEmails(false);

            $this->info("Processed: {$stats['processed']}");
            $this->info("Marked invalid: {$stats['marked_invalid']}");

            Log::info('Bounced email processing complete', $stats);

            $this->info('Suspending users with excessive bounces...');
            $suspended = $bounceService->suspendBouncingUsers();
            $this->info("Suspended: {$suspended}");

            return Command::SUCCESS;
        } finally {
            $this->releaseLock();
        }
    }
}
