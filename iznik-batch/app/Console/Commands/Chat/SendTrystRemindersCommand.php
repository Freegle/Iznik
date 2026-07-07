<?php

namespace App\Console\Commands\Chat;

use App\Services\TrystService;
use Illuminate\Console\Command;

class SendTrystRemindersCommand extends Command
{
    protected $signature = 'chats:send-tryst-reminders
                            {--dry-run : Preview without sending}';

    protected $description = 'Send calendar invites and chat reminders for arranged handover trysts';

    public function handle(TrystService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $calendars = $service->sendCalendarsDue($dryRun);
        $reminders = $service->sendRemindersDue($dryRun);

        $prefix = $dryRun ? '[DRY RUN] Would send' : 'Sent';
        $this->info("{$prefix} {$calendars} calendar invite(s) and {$reminders} chat reminder(s).");

        return self::SUCCESS;
    }
}
