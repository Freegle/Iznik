<?php

namespace App\Console\Commands\Message;

use App\Services\ContentCheckService;
use App\Traits\GracefulShutdown;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ContentCheckCommand extends Command
{
    use GracefulShutdown;

    protected $signature = 'messages:contentcheck
                            {--dry-run : Show decisions without making changes}';

    protected $description = 'Process unprocessed pending messages through content checks';

    public function handle(ContentCheckService $service): int
    {
        $this->registerShutdownHandlers();
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no changes will be made.');
        }

        Log::info('ContentCheck: starting run', ['dry_run' => $dryRun]);
        $this->info('Processing unprocessed pending messages...');

        $stats = $service->processUnprocessed($dryRun);

        $prefix = $dryRun ? '[DRY RUN] ' : '';
        $this->info("{$prefix}Approved: {$stats['approved']}, Kept pending: {$stats['kept_pending']}, Errors: {$stats['errors']}");

        if ($stats['errors'] > 0) {
            $this->warn("Errors: {$stats['errors']}");
        }

        Log::info('ContentCheck: run complete', $stats);

        return $stats['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
