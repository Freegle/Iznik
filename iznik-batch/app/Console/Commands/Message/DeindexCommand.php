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
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — counting old messages but not deleting.');
        } else {
            Log::info('Starting message deindex');
        }

        $count = $service->deindexOldMessages($dryRun);

        $this->info(($dryRun ? 'would delete: ' : 'deleted: ') . $count);

        if (!$dryRun) {
            Log::info('Message deindex complete', ['deleted' => $count]);
        }

        return Command::SUCCESS;
    }
}
