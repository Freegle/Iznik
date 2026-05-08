<?php

namespace App\Console\Commands\Message;

use App\Services\MessageIndexUnindexedService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class IndexUnindexedCommand extends Command
{
    protected $signature = 'messages:index-unindexed
                            {--dry-run : Show what would be indexed without making changes}';

    protected $description = 'Add search index entries for recent approved messages that are not yet indexed';

    public function handle(MessageIndexUnindexedService $service): int
    {
        if ($this->option('dry-run')) {
            $this->info('Dry run — no changes made.');
            return Command::SUCCESS;
        }

        Log::info('Starting message index-unindexed');

        $result = $service->indexUnindexedMessages();

        $this->info("Indexed {$result['indexed']} messages.");
        Log::info('Message index-unindexed complete', $result);

        return Command::SUCCESS;
    }
}
