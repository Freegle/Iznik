<?php

namespace App\Console\Commands\Chat;

use App\Console\Concerns\PreventsOverlapping;
use App\Models\ChatRoom;
use App\Services\ChatNotificationService;
use App\Services\EmailSpoolerService;
use App\Traits\GracefulShutdown;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class NotifyMod2ModCommand extends Command
{
    use GracefulShutdown;
    use PreventsOverlapping;

    /**
     * The name and signature of the console command.
     *
     * For manual testing, use:
     *   php artisan mail:chat:mod2mod --chat=123 --delay=0 --force --once
     *
     * This will send notifications for chat 123 immediately, even if already sent.
     */
    protected $signature = 'mail:chat:mod2mod
                            {--chat= : Process only a specific chat ID}
                            {--delay=30 : Delay in seconds before sending notification (use 0 for immediate)}
                            {--since=24 : How many hours back to look for messages}
                            {--force : Force sending even for already mailed messages}
                            {--once : Run once and exit (for manual testing)}
                            {--max-iterations=120 : Maximum iterations before exiting}
                            {--spool : Spool emails instead of sending directly}
                            {--dry-run : Show what would be sent without actually sending}';

    /**
     * The console command description.
     */
    protected $description = 'Send email notifications for unread Mod2Mod chat messages';

    /**
     * Execute the console command.
     */
    public function handle(ChatNotificationService $notificationService, EmailSpoolerService $spooler): int
    {
        if (!$this->acquireLock()) {
            $this->info('Already running, exiting.');
            return Command::SUCCESS;
        }

        try {
            return $this->doHandle($notificationService, $spooler);
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * The actual command logic.
     */
    protected function doHandle(ChatNotificationService $notificationService, EmailSpoolerService $spooler): int
    {
        $chatId = $this->option('chat') ? (int) $this->option('chat') : null;
        $delay = (int) $this->option('delay');
        $sinceHours = (int) $this->option('since');
        $forceAll = (bool) $this->option('force');
        $runOnce = (bool) $this->option('once');
        $maxIterations = $runOnce ? 1 : (int) $this->option('max-iterations');
        $spool = (bool) $this->option('spool');
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no emails will be sent.');
        }

        // Inject spooler if spooling is enabled.
        if ($spool) {
            $notificationService->setSpooler($spooler);
        }

        $this->registerShutdownHandlers();

        Log::info('Starting Mod2Mod chat notification', [
            'chat_id' => $chatId,
            'delay' => $delay,
            'since_hours' => $sinceHours,
            'force' => $forceAll,
            'once' => $runOnce,
            'spool' => $spool,
        ]);

        $this->info('Processing Mod2Mod chat notifications...');
        if ($runOnce) {
            $this->info('Running once (manual test mode).');
        }
        $totalNotified = 0;
        $iteration = 0;

        do {
            if ($this->shouldAbort()) {
                $this->warn('Aborting due to shutdown signal.');
                break;
            }

            $startTime = now();
            $this->line('Starting iteration ' . ($iteration + 1) . ' at ' . $startTime->format('Y-m-d H:i:s'));

            $count = $notificationService->notifyByEmail(
                ChatRoom::TYPE_MOD2MOD,
                $chatId,
                $delay,
                $sinceHours,
                $forceAll,
                $dryRun
            );

            $totalNotified += $count;
            $iteration++;

            if ($count > 0) {
                $this->info("Sent {$count} notifications.");
            } else {
                if ($runOnce) {
                    $this->info("No messages to notify.");
                } else {
                    // No messages to process, sleep before next iteration.
                    sleep(1);
                }
            }
        } while ($iteration < $maxIterations);

        $this->newLine();
        $this->info("Mod2Mod notification complete. Total notifications sent: {$totalNotified}");

        Log::info('Mod2Mod chat notification complete', [
            'total_notified' => $totalNotified,
            'iterations' => $iteration,
        ]);

        return Command::SUCCESS;
    }
}
