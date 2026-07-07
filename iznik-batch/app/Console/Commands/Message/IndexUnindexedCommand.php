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
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — counting unindexed messages but not indexing.');
        } else {
            Log::info('Starting messages:update-index');
        }

        $count = $service->indexUnindexedMessages($dryRun);

        $this->info(($dryRun ? 'would index: ' : 'indexed: ') . $count);

        if (!$dryRun) {
            Log::info('messages:update-index complete', ['indexed' => $count]);
        }

        return Command::SUCCESS;
    }
}
