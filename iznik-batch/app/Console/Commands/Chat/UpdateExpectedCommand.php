<?php

namespace App\Console\Commands\Chat;

use App\Services\ChatExpectedService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateExpectedCommand extends Command
{
    protected $signature = 'chats:update-expected';

    protected $description = 'Update expected reply tracking for recent chats';

    public function handle(ChatExpectedService $service): int
    {
        Log::info('Starting chat expected update');

        $result = $service->updateChatExpected();

        $this->info("Chat expected: {$result['received']} received, {$result['waiting']} waiting, {$result['tidied']} tidied.");
        Log::info('Chat expected update complete', $result);

        return Command::SUCCESS;
    }
}
