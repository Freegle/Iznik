<?php

namespace App\Console\Commands\Chat;

use App\Console\Concerns\PreventsOverlapping;
use App\Services\ChatProcessService;
use App\Traits\GracefulShutdown;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessIncomingChatCommand extends Command
{
    use PreventsOverlapping;
    use GracefulShutdown;

    protected $signature = 'chats:process-incoming
                            {--dry-run : Show what would be processed without making changes}';

    protected $description = 'Process pending chat messages (processingrequired=1) — spam checks, roster update, reopen closed chats';

    public function handle(ChatProcessService $service): int
    {
        if (!$this->acquireLock()) {
            $this->info('Already running, exiting.');
            return Command::SUCCESS;
        }

        try {
            if ($this->option('dry-run')) {
                $count = \Illuminate\Support\Facades\DB::table('chat_messages')
                    ->where('processingrequired', 1)
                    ->count();
                $this->info("[DRY RUN] {$count} message(s) pending processing.");
                Log::info('Dry run — chat message processing', ['pending' => $count]);
                return Command::SUCCESS;
            }

            Log::info('Starting chat message processing');

            $count = $service->processIncoming();

            $this->info("{$count} message(s) processed");
            Log::info('Chat message processing complete', ['count' => $count]);

            return Command::SUCCESS;
        } finally {
            $this->releaseLock();
        }
    }
}
