<?php

namespace App\Console\Commands\Purge;

use App\Services\PurgeService;
use App\Traits\GracefulShutdown;
use App\Traits\LogsBatchJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PurgeLogsCommand extends Command
{
    use GracefulShutdown, LogsBatchJob;

    protected $signature = 'purge:logs
                            {--dry-run : Show what would be purged without actually deleting}';

    protected $description = 'Purge old log entries from various tables';

    public function handle(PurgeService $purgeService): int
    {
        $this->registerShutdownHandlers();

        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no changes will be made.');
        }

        return $this->runWithLogging(function () use ($purgeService, $dryRun) {
            Log::info('Starting log purge', ['dry_run' => $dryRun]);
            $this->info('Purging log data...');

            $results = $purgeService->purgeAllLogs($dryRun);

            $this->newLine();
            $this->info('Log purge complete.');
            $this->table(
                ['Category', 'Purged'],
                collect($results)->map(fn ($count, $key) => [str_replace('_', ' ', ucfirst($key)), $count])->toArray()
            );

            Log::info('Log purge complete', $results);

            return Command::SUCCESS;
        });
    }
}
