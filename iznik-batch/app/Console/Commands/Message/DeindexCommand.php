<?php

namespace App\Console\Commands\Message;

use App\Services\MessageSearchService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DeindexCommand extends Command
{
    protected $signature = 'messages:deindex
                            {--dry-run : Show what would be deindexed without making changes}';

    protected $description = 'Remove search index entries for messages older than 30 days';

    public function handle(MessageSearchService $service): int
    {
        if ($this->option('dry-run')) {
            $this->info('DRY RUN — no changes will be made.');
            return Command::SUCCESS;
        }

        Log::info('Starting message deindex');

        $count = $service->deindexOldMessages();

        $this->info("deleted: {$count}");
        Log::info('Message deindex complete', ['deleted' => $count]);

        return Command::SUCCESS;
    }
}
