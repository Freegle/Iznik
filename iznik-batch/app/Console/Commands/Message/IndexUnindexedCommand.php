<?php

namespace App\Console\Commands\Message;

use App\Services\MessageSearchService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class IndexUnindexedCommand extends Command
{
    protected $signature = 'messages:update-index
                            {--dry-run : Show what would be indexed without making changes}';

    protected $description = 'Add search index entries for recent approved messages that are not yet indexed';

    public function handle(MessageSearchService $service): int
    {
        if ($this->option('dry-run')) {
            $this->info('DRY RUN — no changes will be made.');
            return Command::SUCCESS;
        }

        Log::info('Starting messages:update-index');

        $count = $service->indexUnindexedMessages();

        $this->info("indexed: {$count}");
        Log::info('messages:update-index complete', ['indexed' => $count]);

        return Command::SUCCESS;
    }
}
