<?php

namespace App\Console\Commands\Cleanup;

use App\Services\PurgeService;
use App\Traits\GracefulShutdown;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupSessionsCommand extends Command
{
    use GracefulShutdown;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'cleanup:sessions
                            {--days=31 : Days to keep sessions}
                            {--dry-run : Show what would be purged without deleting}';

    /**
     * The console command description.
     */
    protected $description = 'Clean up old sessions and expired login links';

    /**
     * Execute the console command.
     */
    public function handle(PurgeService $purgeService): int
    {
        $this->registerShutdownHandlers();

        $results = [];

        $days = (int) $this->option('days');
        $dryRun = (bool) $this->option('dry-run');

        Log::info('Starting session cleanup', ['days' => $days, 'dry_run' => $dryRun]);
        $this->info('Cleaning up session data...');

        if ($dryRun) {
            $this->warn('DRY RUN — no data will be deleted.');
        }

        $this->line('Cleaning up old sessions...');
        $results['sessions'] = $purgeService->purgeSessions($days, $dryRun);
        $this->info("  " . ($dryRun ? 'Would remove' : 'Removed') . " {$results['sessions']} sessions");

        if ($this->shouldAbort()) {
            $this->warn('Aborting due to shutdown signal.');

            return Command::SUCCESS;
        }

        $this->line('Cleaning up old login links...');
        $results['login_links'] = $purgeService->purgeOldLoginLinks($days, $dryRun);
        $this->info("  " . ($dryRun ? 'Would remove' : 'Removed') . " {$results['login_links']} login links");

        $this->newLine();
        $this->info('Session cleanup ' . ($dryRun ? 'dry run' : '') . ' complete.');
        $this->table(
            ['Category', $dryRun ? 'Would Remove' : 'Removed'],
            collect($results)->map(fn ($count, $key) => [str_replace('_', ' ', ucfirst($key)), $count])->toArray()
        );

        Log::info('Session cleanup complete', array_merge($results, ['dry_run' => $dryRun]));

        return Command::SUCCESS;
    }
}
