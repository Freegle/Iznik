<?php

namespace App\Console\Commands\Message;

use App\Services\AutoApproveCleanService;
use App\Traits\GracefulShutdown;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AutoApproveCleanCommand extends Command
{
    use GracefulShutdown;

    protected $signature = 'messages:auto-approve-clean
                            {--dry-run : Show what would be approved without actually approving}';

    protected $description = 'Auto-approve content-check-clean posts from NULL-status members after the configured delay';

    public function handle(AutoApproveCleanService $service): int
    {
        $this->registerShutdownHandlers();

        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no changes will be made.');
        }

        Log::info('Starting auto-approve-clean processing', ['dry_run' => $dryRun]);
        $this->info('Processing content-check-clean pending messages for auto-approval...');

        $stats = $service->process($dryRun);

        $prefix = $dryRun ? '[DRY RUN] ' : '';
        $this->info(
            "{$prefix}Approved: {$stats['approved']}, Held (quality): {$stats['held_quality']}, " .
            "Vetoed: {$stats['vetoed']}, Skipped: {$stats['skipped']}, Errors: {$stats['errors']}"
        );

        if ($stats['errors'] > 0) {
            $this->warn("Errors: {$stats['errors']}");
        }

        Log::info('Auto-approve-clean processing complete', $stats);

        return $stats['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
