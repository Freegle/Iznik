<?php

namespace App\Console\Commands\Mail;

use App\Mail\Traits\FeatureFlags;
use App\Services\UnifiedDigestService;
use Illuminate\Console\Command;

class SendUnifiedDigestCommand extends Command
{
    use FeatureFlags;

    /**
     * The name and signature of the console command.
     */
    protected $signature = "mail:digest:unified
                            {--mode=daily : Digest mode - 'daily' or 'immediate'}
                            {--user= : Process only this user ID (for testing)}
                            {--limit= : Cap users processed per run (default: unlimited)}
                            {--dry-run : Show what would be sent without actually sending}";

    /**
     * The console command description.
     */
    protected $description = "Send unified Freegle digests containing posts from all user's communities";

    private const EMAIL_TYPE = 'UnifiedDigest';

    /**
     * Execute the console command.
     */
    public function handle(UnifiedDigestService $service): int
    {
        // Check if UnifiedDigest emails are enabled for this batch system.
        if (! self::isEmailTypeEnabled(self::EMAIL_TYPE)) {
            $this->info("UnifiedDigest emails are not enabled in iznik-batch. Set FREEGLE_MAIL_ENABLED_TYPES in .env to include 'UnifiedDigest'.");

            return Command::SUCCESS;
        }

        $mode = $this->option('mode');
        $userId = $this->option('user') ? (int) $this->option('user') : null;
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $dryRun = $this->option('dry-run');

        // Validate mode.
        if (! in_array($mode, [UnifiedDigestService::MODE_DAILY, UnifiedDigestService::MODE_IMMEDIATE])) {
            $this->error("Invalid mode '{$mode}'. Must be 'daily' or 'immediate'.");

            return Command::FAILURE;
        }

        if ($dryRun) {
            $this->info('DRY RUN — no changes will be made.');
        }

        $limitLabel = $limit !== null ? (string) $limit : 'unlimited';
        $this->info("Sending unified digests (mode: {$mode}, limit: {$limitLabel})...");

        if ($userId) {
            $this->info("Processing single user ID: {$userId}");
        }

        // Run the service.
        $stats = $service->sendDigests($mode, $userId, $limit, $dryRun);

        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['Users Processed', $stats['users_processed']],
                ['Emails Sent', $stats['emails_sent']],
                ['No New Posts', $stats['no_new_posts']],
                ['Errors', $stats['errors']],
            ]
        );

        if ($stats['errors'] > 0) {
            $this->warn("There were {$stats['errors']} errors. Check logs for details.");
        }

        return Command::SUCCESS;
    }
}
