<?php

namespace App\Console\Commands\Chat;

use App\Services\ChatMaintenanceService;
use App\Traits\GracefulShutdown;
use App\Traits\LogsBatchJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateMessageCountsCommand extends Command
{
    use GracefulShutdown, LogsBatchJob;

    protected $signature = 'chats:update-counts
                            {--dry-run : Show what would be updated without actually changing}';

    protected $description = 'Update chat room message counts and reopen closed User2Mod chats with unseen messages';

    public function handle(ChatMaintenanceService $service): int
    {
        $this->registerShutdownHandlers();

        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no changes will be made.');
        }

        return $this->runWithLogging(function () use ($service, $dryRun) {
            Log::info('Starting chat message count update', ['dry_run' => $dryRun]);
            $this->info('Updating chat message counts...');

            $stats = $service->updateMessageCounts($dryRun);

            $this->info("Updated {$stats['rooms_updated']} rooms, reopened {$stats['rooms_reopened']} closed chats.");
            Log::info('Chat message count update complete', $stats);

            return Command::SUCCESS;
        });
    }
}
