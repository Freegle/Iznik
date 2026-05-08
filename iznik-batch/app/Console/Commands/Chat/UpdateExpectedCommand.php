<?php

namespace App\Console\Commands\Chat;

use App\Console\Concerns\PreventsOverlapping;
use App\Services\ChatExpectedService;
use App\Traits\GracefulShutdown;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateExpectedCommand extends Command
{
    use PreventsOverlapping;
    use GracefulShutdown;

    protected $signature = 'chats:update-expected
                            {--dry-run : Show what would be updated without making changes}';

    protected $description = 'Update expected reply tracking for recent chats';

    public function handle(ChatExpectedService $service): int
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

            Log::info('Starting chat expected update');

            $result = $service->updateChatExpected();

            $this->info("Chat expected: {$result['received']} received, {$result['waiting']} waiting, {$result['tidied']} tidied.");
            Log::info('Chat expected update complete', $result);

            return Command::SUCCESS;
        } finally {
            $this->releaseLock();
        }
    }
}
