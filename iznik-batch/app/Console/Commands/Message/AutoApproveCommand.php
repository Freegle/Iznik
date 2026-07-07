<?php

namespace App\Console\Commands\Message;

use App\Services\AutoApproveService;
use App\Traits\GracefulShutdown;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AutoApproveCommand extends Command
{
    use GracefulShutdown;

    protected $signature = 'messages:auto-approve
                            {--dry-run : Show what would be approved without actually approving}';

    protected $description = 'Auto-approve messages that have been pending for more than 48 hours';

    public function handle(AutoApproveService $service): int
    {
        $this->registerShutdownHandlers();

        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no changes will be made.');
        }

        Log::info('Starting auto-approve processing', ['dry_run' => $dryRun]);
        $this->info('Processing pending messages for auto-approval...');

        $stats = $service->process($dryRun);

        $prefix = $dryRun ? '[DRY RUN] ' : '';
        $this->info("{$prefix}Approved: {$stats['approved']}, Skipped: {$stats['skipped']}, Errors: {$stats['errors']}");

        if ($stats['errors'] > 0) {
            $this->warn("Errors: {$stats['errors']}");
        }

        Log::info('Auto-approve processing complete', $stats);

        return $stats['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
