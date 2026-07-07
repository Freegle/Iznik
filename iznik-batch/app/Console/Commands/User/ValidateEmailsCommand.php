<?php

namespace App\Console\Commands\User;

use App\Services\UserManagementService;
use App\Traits\GracefulShutdown;
use App\Traits\LogsBatchJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ValidateEmailsCommand extends Command
{
    use GracefulShutdown, LogsBatchJob;

    protected $signature = 'emails:validate
                            {--dry-run : Show what would be deleted without actually deleting}';

    protected $description = 'Validate all non-bouncing emails and delete invalid ones';

    public function handle(UserManagementService $service): int
    {
        $this->registerShutdownHandlers();

        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no changes will be made.');
        }

        return $this->runWithLogging(function () use ($service, $dryRun) {
            Log::info('Starting email validation', ['dry_run' => $dryRun]);

            $stats = $service->validateEmails($dryRun);

            $this->info("Validated {$stats['total']} emails, deleted {$stats['invalid']} invalid.");
            Log::info('Email validation complete', $stats);

            return Command::SUCCESS;
        });
    }
}
