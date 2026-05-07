<?php

namespace App\Console\Commands\Notification;

use App\Services\NotificationChaseUpService;
use Illuminate\Console\Command;

/**
 * Send chaseup emails for unseen, unmailed site notifications (comments, loves, etc.).
 *
 * Migrated from V1 notification_chaseup.php → Notifications::sendEmails().
 * Runs every 5 minutes in production (see routes/console.php).
 */
class ChaseUpNotificationsCommand extends Command
{
    protected $signature = 'mail:notifications:chaseup
        {--user= : Only process this user ID}
        {--before=5 : Minimum age of notification in minutes before sending}
        {--since=24 : Maximum age of notification in hours}
        {--dry-run : Show what would be sent without sending}';

    protected $description = 'Send chaseup emails for unseen site notifications (comments, loves, etc.)';

    public function handle(NotificationChaseUpService $service): int
    {
        $userId        = $this->option('user') ? (int) $this->option('user') : null;
        $beforeMinutes = (int) $this->option('before');
        $sinceHours    = (int) $this->option('since');
        $dryRun        = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('Dry run — no emails will be sent.');
        }

        $count = $service->sendEmails(
            userId:        $userId,
            beforeMinutes: $beforeMinutes,
            sinceHours:    $sinceHours,
            dryRun:        $dryRun
        );

        $this->info("Sent {$count} notification chaseup email(s).");

        return Command::SUCCESS;
    }
}
