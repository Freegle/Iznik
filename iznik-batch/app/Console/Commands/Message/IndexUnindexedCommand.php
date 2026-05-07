<?php

namespace App\Console\Commands\Message;

use App\Services\MessageIndexUnindexedService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class IndexUnindexedCommand extends Command
{
    protected $signature = 'messages:index-unindexed';

    protected $description = 'Add search index entries for recent approved messages that are not yet indexed';

    public function handle(MessageIndexUnindexedService $service): int
    {
        Log::info('Starting message index-unindexed');

        $result = $service->indexUnindexedMessages();

        $this->info("Indexed {$result['indexed']} messages.");
        Log::info('Message index-unindexed complete', $result);

        return Command::SUCCESS;
    }
}
