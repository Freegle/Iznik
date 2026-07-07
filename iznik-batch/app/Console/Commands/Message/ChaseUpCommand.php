<?php

namespace App\Console\Commands\Message;

use App\Services\ChaseUpService;
use App\Traits\GracefulShutdown;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ChaseUpCommand extends Command
{
    use GracefulShutdown;

    protected $signature = 'messages:chase-up
                            {--dry-run : Show what would be chased up without actually updating}';

    protected $description = 'Send chase-up emails for messages with replies but no outcome after max reposts reached';

    public function handle(ChaseUpService $service): int
    {
        $this->registerShutdownHandlers();

        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no changes will be made.');
        }

        Log::info('Starting chase-up processing', ['dry_run' => $dryRun]);

        // V1: chaseup.php calls these three operations before the main chaseUp().
        $this->info('Tidying dull outcome comments...');
        $tidied = $service->tidyOutcomes($dryRun);
        $this->info("  Tidied {$tidied} outcomes");

        $this->info('Processing intended outcomes...');
        $intended = $service->processIntendedOutcomes($dryRun);
        $this->info("  Processed {$intended} intended outcomes");

        $this->info('Notifying about languishing posts...');
        $languishing = $service->notifyLanguishing($dryRun);
        $this->info("  Found {$languishing} languishing posts");

        $this->info('Processing messages for chase-up...');

        $stats = $service->process($dryRun);

        $prefix = $dryRun ? '[DRY RUN] ' : '';
        $this->info("{$prefix}Chased: {$stats['chased']}, Skipped: {$stats['skipped']}, Errors: {$stats['errors']}");

        if ($stats['errors'] > 0) {
            $this->warn("Errors: {$stats['errors']}");
        }

        Log::info('Chase-up processing complete', $stats);

        return $stats['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
