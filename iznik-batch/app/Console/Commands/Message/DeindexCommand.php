<?php

namespace App\Console\Commands\Message;

use App\Services\MessageDeindexService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DeindexCommand extends Command
{
    protected $signature = 'messages:deindex
                            {--dry-run : Show what would be deindexed without making changes}';

    protected $description = 'Remove search index entries for messages older than 30 days';

    public function handle(MessageDeindexService $service): int
    {
        if ($this->option('dry-run')) {
            $this->info('Dry run — no changes made.');
            return Command::SUCCESS;
        }

        Log::info('Starting message deindex');

        $result = $service->deindexOldMessages();

        $this->info("Deindexed {$result['deindexed']} messages.");
        Log::info('Message deindex complete', $result);

        return Command::SUCCESS;
    }
}
