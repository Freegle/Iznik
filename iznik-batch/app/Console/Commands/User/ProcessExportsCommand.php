<?php

namespace App\Console\Commands\User;

use App\Console\Concerns\PreventsOverlapping;
use App\Services\UserDataExportService;
use App\Traits\GracefulShutdown;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessExportsCommand extends Command
{
    use PreventsOverlapping;
    use GracefulShutdown;

    protected $signature = 'users:process-exports
                            {--dry-run : Log what would be processed without making changes}';

    protected $description = 'Process pending GDPR data export requests and purge old completed export data';

    public function handle(UserDataExportService $service): int
    {
        if (!$this->acquireLock()) {
            $this->info('Already running, exiting.');
            return Command::SUCCESS;
        }

        try {
            $dryRun = (bool) $this->option('dry-run');

            if ($dryRun) {
                $this->info('DRY RUN — no exports will be assembled or data written.');
            }

            Log::info('Starting GDPR export processing', ['dry_run' => $dryRun]);

            $count = $service->processAll($dryRun);

            $prefix = $dryRun ? '[DRY RUN] Would process' : 'Processed';
            $this->info("{$prefix} {$count} export(s).");
            Log::info('GDPR export processing complete', ['count' => $count, 'dry_run' => $dryRun]);

            return Command::SUCCESS;
        } finally {
            $this->releaseLock();
        }
    }
}
