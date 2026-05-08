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

        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — counting changes but not writing.');
        }

        return $this->runWithLogging(function () use ($service, $dryRun) {
            if (!$dryRun) {
                Log::info('Starting chat expected update');
            }

            $stats = $service->updateChatExpected($dryRun);

            $verb = $dryRun ? 'Would clear' : 'Cleared';
            $verb2 = $dryRun ? 'Would update' : 'Expected update:';
            $this->info("{$verb} replyexpected for {$stats['deleted_cleared']} deleted-user messages, {$stats['spam_cleared']} spam messages.");
            $this->info("{$verb2} {$stats['waiting']} waiting, {$stats['received']} received.");

            if (!$dryRun) {
                Log::info('Chat expected update complete', $stats);
            }

            return Command::SUCCESS;
        });
    }
}
