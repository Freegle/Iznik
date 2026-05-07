<?php

namespace App\Console\Commands\Message;

use App\Services\MessageDeindexService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DeindexCommand extends Command
{
    protected $signature = 'messages:deindex';

    protected $description = 'Remove search index entries for messages older than 30 days';

    public function handle(MessageDeindexService $service): int
    {
        Log::info('Starting message deindex');

        $result = $service->deindexOldMessages();

        $this->info("Deindexed {$result['deindexed']} messages.");
        Log::info('Message deindex complete', $result);

        return Command::SUCCESS;
    }
}
