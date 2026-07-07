<?php

namespace App\Console\Commands\Message;

use App\Services\MessageExpiryService;
use App\Traits\GracefulShutdown;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessExpiredMessagesCommand extends Command
{
    use GracefulShutdown;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'messages:process-expired
                            {--spatial : Also process spatial index expiry}
                            {--dry-run : Show what would be processed without actually updating}';

    /**
     * The console command description.
     */
    protected $description = 'Process messages that have reached their deadline or expired';

    /**
     * Execute the console command.
     */
    public function handle(MessageExpiryService $expiryService): int
    {
        $this->registerShutdownHandlers();

        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no changes will be made.');
        }

        Log::info('Starting message expiry processing', ['dry_run' => $dryRun]);
        $this->info('Processing expired messages...');

        // Process deadline-expired messages.
        $stats = $expiryService->processDeadlineExpired($dryRun);

        $prefix = $dryRun ? '[DRY RUN] ' : '';
        $this->info("{$prefix}Deadline expired: {$stats['processed']} processed, {$stats['emails_sent']} emails sent");

        if ($stats['errors'] > 0) {
            $this->warn("Errors: {$stats['errors']}");
        }

        // Optionally process spatial index expiry.
        if ($this->option('spatial')) {
            if ($this->shouldAbort()) {
                $this->warn('Aborting due to shutdown signal.');
                return Command::SUCCESS;
            }

            $this->info('Processing spatial index expiry...');
            $spatialCount = $expiryService->processExpiredFromSpatialIndex($dryRun);
            $this->info("Spatial index: {$spatialCount} messages processed");
        }

        Log::info('Message expiry processing complete', $stats);

        return $stats['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
