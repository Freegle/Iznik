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
                            {--dry-run : Show what would be chased up without actually updating}
                            {--skip-languishing : Leave the languishing-posts scan to its own daily run}
                            {--languishing-only : Run only the languishing-posts scan}';

    protected $description = 'Send chase-up emails for messages with replies but no outcome after max reposts reached';

    public function handle(ChaseUpService $service): int
    {
        $this->registerShutdownHandlers();

        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no changes will be made.');
        }

        $languishingOnly = (bool) $this->option('languishing-only');
        $skipLanguishing = (bool) $this->option('skip-languishing');

        Log::info('Starting chase-up processing', [
            'dry_run' => $dryRun,
            'languishing_only' => $languishingOnly,
            'skip_languishing' => $skipLanguishing,
        ]);

        // The languishing scan finds the same posts every hour and can only raise one
        // notification per person per day anyway (notifyLanguishing checks for a recent
        // OpenPosts notification before adding another), so 23 of the 24 daily scans
        // could never do anything. It runs once a day on its own schedule instead.
        if ($languishingOnly) {
            $this->info('Notifying about languishing posts...');
            $languishing = $service->notifyLanguishing($dryRun);
            $this->info("  Found {$languishing} languishing posts");

            Log::info('Chase-up languishing scan complete', ['languishing' => $languishing]);

            return Command::SUCCESS;
        }

        // V1: chaseup.php calls these three operations before the main chaseUp().
        // The first two are cheap and bounded, so they stay hourly.
        $this->info('Tidying dull outcome comments...');
        $tidied = $service->tidyOutcomes($dryRun);
        $this->info("  Tidied {$tidied} outcomes");

        $this->info('Processing intended outcomes...');
        $intended = $service->processIntendedOutcomes($dryRun);
        $this->info("  Processed {$intended} intended outcomes");

        if (!$skipLanguishing) {
            $this->info('Notifying about languishing posts...');
            $languishing = $service->notifyLanguishing($dryRun);
            $this->info("  Found {$languishing} languishing posts");
        }

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
