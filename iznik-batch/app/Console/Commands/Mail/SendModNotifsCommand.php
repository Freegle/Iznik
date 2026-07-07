<?php

namespace App\Console\Commands\Mail;

use App\Console\Concerns\PreventsOverlapping;
use App\Mail\Admin\ModNotifMail;
use App\Services\ModNotifService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendModNotifsCommand extends Command
{
    use PreventsOverlapping;

    protected $signature = 'mail:mod-notifs
                            {--dry-run : Show what would be sent without sending}
                            {--force : Send even outside 08:00–21:00 window}';

    protected $description = 'Send moderation notification emails to moderators with pending work (V1: mod_notifs.php)';

    private const HOUR_START = 8;

    private const HOUR_END = 21;

    public function handle(ModNotifService $service): int
    {
        if (! $this->acquireLock()) {
            $this->info('Already running, exiting.');

            return Command::SUCCESS;
        }

        try {
            return $this->runLocked($service);
        } finally {
            $this->releaseLock();
        }
    }

    private function runLocked(ModNotifService $service): int
    {
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');

        if (!$force) {
            $hour = (int) now()->setTimezone('Europe/London')->format('G');
            if ($hour < self::HOUR_START || $hour >= self::HOUR_END) {
                $this->info("Outside notification window ({$hour}:00, window is " . self::HOUR_START . ':00–' . self::HOUR_END . ':00). Use --force to override.');

                return Command::SUCCESS;
            }
        }

        if ($dryRun) {
            $this->info('DRY RUN — no emails will be sent.');
        }

        $notifications = $service->getNotificationsToSend();
        $this->info('Moderator notifications to send: ' . count($notifications));

        $sent = 0;

        foreach ($notifications as $notif) {
            Log::info('Sending mod notification', [
                'user_id' => $notif['user_id'],
                'email' => $notif['email'],
                'subject' => $notif['subject'],
            ]);

            if (!$dryRun) {
                $mail = new ModNotifMail(
                    $notif['name'],
                    $notif['email'],
                    $notif['user_id'],
                    $notif['subject'],
                    $notif['html_summary'],
                    $notif['text_summary']
                );

                // spool() returns the spool id on success and '' when the
                // address is a permanent failure (it records the bounce
                // internally and skips), so $delivered stays falsy in that
                // case — matching the old SafeMail::send() contract that
                // returned false and skipped recordSent. Anything spool()
                // re-throws (transient SMTP / MJML render error) is caught
                // here so one bad mod doesn't abort the whole notifications
                // run — the resilience SafeMail::send() used to provide.
                try {
                    $delivered = app(\App\Services\EmailSpoolerService::class)->spool($mail, $notif['email']);
                } catch (\Throwable $e) {
                    Log::warning('Skipping mod notification after spool failure; continuing loop', [
                        'user_id' => $notif['user_id'],
                        'email' => $notif['email'],
                        'error' => $e->getMessage(),
                    ]);
                    $delivered = '';
                }

                if ($delivered) {
                    $service->recordSent($notif['user_id'], $notif['text_summary']);
                }
            }

            $this->info("  [{$notif['user_id']}] {$notif['email']} — {$notif['subject']}");
            $sent++;
        }

        $prefix = $dryRun ? '[DRY RUN] ' : '';
        $this->info("{$prefix}Sent: {$sent}");
        Log::info('mod:notifs complete', ['sent' => $sent]);

        return Command::SUCCESS;
    }
}
