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
                            {--dry-run : Show what would be processed without making changes}';

    protected $description = 'Process pending GDPR data export requests and purge old completed export data';

    public function handle(UserDataExportService $service): int
    {
        if (!$this->acquireLock()) {
            $this->info('Already running, exiting.');
            return Command::SUCCESS;
        }

        try {
            if ($this->option('dry-run')) {
                $count = \Illuminate\Support\Facades\DB::table('users_exports')
                    ->whereNull('completed')
                    ->count();
                $this->info("Dry run — {$count} export(s) would be processed.");
                return Command::SUCCESS;
            }

            Log::info('Starting GDPR export processing');

            $count = $service->processAll();

            $this->info("{$count} export(s) processed");
            Log::info('GDPR export processing complete', ['count' => $count]);

            return Command::SUCCESS;
        } finally {
            $this->releaseLock();
        }
    }
}
