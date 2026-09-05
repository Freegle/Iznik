<?php

namespace App\Console\Commands\Chat;

use App\Services\ChatExpectedService;
use App\Traits\GracefulShutdown;
use App\Traits\LogsBatchJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateChatExpectedCommand extends Command
{
    use GracefulShutdown, LogsBatchJob;

    protected $signature = 'chats:update-expected
                            {--dry-run : Show what would be done without actually changing}
                            {--full : Re-check every waiting message rather than only chats with recent activity}';

    protected $description = 'Update chat reply-expectation tracking and per-user reply-time metrics';

    public function handle(ChatExpectedService $service): int
    {
        $this->registerShutdownHandlers();

        $dryRun = (bool) $this->option('dry-run');
        $full = (bool) $this->option('full');

        if ($dryRun) {
            $this->info('DRY RUN — counting changes but not writing.');
        }

        return $this->runWithLogging(function () use ($service, $dryRun, $full) {
            if (!$dryRun) {
                Log::info('Starting chat expected update');
            }

            $stats = $service->updateChatExpected($dryRun, $full);

            $verb = $dryRun ? 'Would clear' : 'Cleared';
            $verb2 = $dryRun ? 'Would update' : 'Expected update:';
            $this->info("{$verb} replyexpected for {$stats['deleted_cleared']} deleted-user messages, {$stats['spam_cleared']} spam messages.");
            $scope = $stats['full'] ? 'all waiting messages' : 'chats with recent activity';
            $this->info("{$verb2} checked {$stats['checked']} messages across {$scope} - {$stats['waiting']} waiting, {$stats['received']} received.");

            if (!$dryRun) {
                Log::info('Chat expected update complete', $stats);
            }

            return Command::SUCCESS;
        });
    }
}
