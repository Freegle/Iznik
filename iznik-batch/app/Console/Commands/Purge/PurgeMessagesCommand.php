<?php

namespace App\Console\Commands\Purge;

use App\Console\Concerns\PreventsOverlapping;
use App\Services\PurgeService;
use App\Traits\GracefulShutdown;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PurgeMessagesCommand extends Command
{
    use PreventsOverlapping;
    use GracefulShutdown;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'purge:messages
                            {--history-days=31 : Days to keep messages_history}
                            {--pending-days=90 : Days to keep pending messages}
                            {--draft-days=90 : Days to keep drafts}
                            {--deleted-retention=2 : Days to keep deleted messages}
                            {--dry-run : Show what would be purged without actually deleting}';

    /**
     * The console command description.
     */
    protected $description = 'Purge old message data';

    /**
     * Execute the console command.
     */
    public function handle(PurgeService $purgeService): int
    {
        if (!$this->acquireLock()) {
            $this->info('Already running, exiting.');
            return Command::SUCCESS;
        }

        try {
            $this->registerShutdownHandlers();

            $dryRun = $this->option('dry-run');

            if ($dryRun) {
                $this->info('DRY RUN — no changes will be made.');
                return Command::SUCCESS;
            }

            Log::info('Starting message purge', ['dry_run' => $dryRun]);
            $this->info('Purging message data...');

            $results = [];

            // Purge messages_history.
            $this->line('Purging messages_history...');
            $historyDays = (int) $this->option('history-days');
            $results['messages_history'] = $purgeService->purgeOldMessagesHistory($historyDays, $dryRun);
            $this->info("  Purged {$results['messages_history']} history records");

            if ($this->shouldAbort()) {
                return $this->abortWithResults($results);
            }

            // Purge pending messages.
            $this->line('Purging pending messages...');
            $pendingDays = (int) $this->option('pending-days');
            $results['pending_messages'] = $purgeService->purgePendingMessages($pendingDays, $dryRun);
            $this->info("  Purged {$results['pending_messages']} pending messages");

            if ($this->shouldAbort()) {
                return $this->abortWithResults($results);
            }

            // Purge old drafts.
            $this->line('Purging old drafts...');
            $draftDays = (int) $this->option('draft-days');
            $results['old_drafts'] = $purgeService->purgeOldDrafts($draftDays, $dryRun);
            $this->info("  Purged {$results['old_drafts']} drafts");

            if ($this->shouldAbort()) {
                return $this->abortWithResults($results);
            }

            // Purge non-Freegle messages.
            $this->line('Purging non-Freegle messages...');
            $results['non_freegle'] = $purgeService->purgeNonFreegleMessages(90, $dryRun);
            $this->info("  Purged {$results['non_freegle']} non-Freegle messages");

            if ($this->shouldAbort()) {
                return $this->abortWithResults($results);
            }

            // Purge deleted messages.
            $this->line('Purging deleted messages...');
            $deletedRetention = (int) $this->option('deleted-retention');
            $results['deleted_messages'] = $purgeService->purgeDeletedMessages($deletedRetention, $dryRun);
            $this->info("  Purged {$results['deleted_messages']} deleted messages");

            if ($this->shouldAbort()) {
                return $this->abortWithResults($results);
            }

            // Purge stranded messages.
            $this->line('Purging stranded messages...');
            $results['stranded_messages'] = $purgeService->purgeStrandedMessages(2, $dryRun);
            $this->info("  Purged {$results['stranded_messages']} stranded messages");

            if ($this->shouldAbort()) {
                return $this->abortWithResults($results);
            }

            // Purge HTML body.
            $this->line('Purging HTML body from old messages...');
            $results['html_body'] = $purgeService->purgeHtmlBody(2, $dryRun);
            $this->info("  Purged HTML body from {$results['html_body']} messages");

            if ($this->shouldAbort()) {
                return $this->abortWithResults($results);
            }

            // Purge message source for 30-60 day old messages.
            $this->line('Purging message source from 30-60 day old messages...');
            $results['message_source'] = $purgeService->purgeMessageSource($dryRun);
            $this->info("  Purged message source from {$results['message_source']} messages");

            if ($this->shouldAbort()) {
                return $this->abortWithResults($results);
            }

            // Purge orphaned isochrones.
            $this->line('Purging orphaned isochrones...');
            $results['orphaned_isochrones'] = $purgeService->purgeOrphanedIsochrones();
            $this->info("  Purged {$results['orphaned_isochrones']} orphaned isochrones");

            if ($this->shouldAbort()) {
                return $this->abortWithResults($results);
            }

            // Purge completed admins.
            $this->line('Purging completed admins...');
            $results['completed_admins'] = $purgeService->purgeCompletedAdmins();
            $this->info("  Purged {$results['completed_admins']} completed admin records");

            if ($this->shouldAbort()) {
                return $this->abortWithResults($results);
            }

            // Purge old users_nearby data.
            $this->line('Purging old users_nearby data...');
            $results['users_nearby'] = $purgeService->purgeUsersNearby();
            $this->info("  Purged {$results['users_nearby']} users_nearby records");

            if ($this->shouldAbort()) {
                return $this->abortWithResults($results);
            }

            // Purge unvalidated email addresses.
            $this->line('Purging unvalidated email addresses...');
            $results['unvalidated_emails'] = $purgeService->purgeUnvalidatedEmails();
            $this->info("  Purged {$results['unvalidated_emails']} unvalidated emails");

            $this->displayResults($results);

            Log::info('Message purge complete', $results);

            return Command::SUCCESS;
        } finally {
            $this->releaseLock();
        }
    }

    protected function abortWithResults(array $results): int
    {
        $this->warn('Aborting due to shutdown signal.');
        $this->displayResults($results);
        return Command::SUCCESS;
    }

    protected function displayResults(array $results): void
    {
        $this->newLine();
        $this->info('Message purge complete.');
        $this->table(
            ['Category', 'Purged'],
            collect($results)->map(fn ($count, $key) => [str_replace('_', ' ', ucfirst($key)), $count])->toArray()
        );
    }
}
