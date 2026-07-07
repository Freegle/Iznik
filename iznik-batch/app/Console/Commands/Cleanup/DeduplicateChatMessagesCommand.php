<?php

namespace App\Console\Commands\Cleanup;

use App\Services\PurgeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DeduplicateChatMessagesCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'cleanup:chat-duplicates
                            {--days=3 : Days back to check for duplicates}
                            {--dry-run : Show what would be deleted without deleting}';

    /**
     * The console command description.
     */
    protected $description = 'Remove duplicate consecutive chat messages';

    /**
     * Execute the console command.
     */
    public function handle(PurgeService $purgeService): int
    {
        $days = (int) $this->option('days');
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN — no data will be deleted.');
        }

        Log::info('Starting chat message deduplication', ['days' => $days, 'dry_run' => $dryRun]);
        $this->info("Deduplicating chat messages from the last {$days} days...");

        $deleted = $purgeService->deduplicateChatMessages($days, $dryRun);

        $this->info(($dryRun ? 'Would delete' : 'Deleted') . " {$deleted} duplicate chat messages.");
        Log::info('Chat message deduplication complete', ['deleted' => $deleted]);

        return Command::SUCCESS;
    }
}
