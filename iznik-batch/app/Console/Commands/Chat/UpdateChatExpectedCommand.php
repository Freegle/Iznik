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
                            {--dry-run : Show what would be done without actually changing}';

    protected $description = 'Update chat reply-expectation tracking and per-user reply-time metrics';

    public function handle(ChatExpectedService $service): int
    {
        $this->registerShutdownHandlers();

        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no changes will be made.');

            return Command::SUCCESS;
        }

        return $this->runWithLogging(function () use ($service) {
            Log::info('Starting chat expected update');

            $stats = $service->updateChatExpected();

            $this->info("Cleared replyexpected for {$stats['deleted_cleared']} deleted-user messages, {$stats['spam_cleared']} spam messages.");
            $this->info("Expected update: {$stats['waiting']} waiting, {$stats['received']} received.");

            Log::info('Chat expected update complete', $stats);

            return Command::SUCCESS;
        });
    }
}
