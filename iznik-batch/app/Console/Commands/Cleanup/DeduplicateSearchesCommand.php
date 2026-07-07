<?php

namespace App\Console\Commands\Cleanup;

use App\Services\PurgeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DeduplicateSearchesCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'cleanup:search-duplicates
                            {--days=2 : Days back to check for duplicates}
                            {--dry-run : Show what would be deleted without deleting}';

    /**
     * The console command description.
     */
    protected $description = 'Remove duplicate consecutive search history entries';

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

        Log::info('Starting search deduplication', ['days' => $days, 'dry_run' => $dryRun]);
        $this->info("Deduplicating searches from the last {$days} days...");

        $deleted = $purgeService->deduplicateSearchHistory($days, $dryRun);

        $this->info(($dryRun ? 'Would delete' : 'Deleted') . " {$deleted} duplicate search entries.");
        Log::info('Search deduplication complete', ['deleted' => $deleted]);

        return Command::SUCCESS;
    }
}
