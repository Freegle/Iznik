<?php

namespace App\Console\Commands\Birthday;

use App\Services\BirthdayService;
use Illuminate\Console\Command;

class SendBirthdayEmailsCommand extends Command
{
    protected $signature = 'birthday:send-emails
                            {--email= : Send test email to this address (stops after one)}
                            {--groups= : Comma-separated group IDs to process}
                            {--dry-run : List eligible groups/members without sending}';

    protected $description = 'Send birthday emails to members of groups founded on today\'s date';

    public function handle(BirthdayService $service): int
    {
        if ($this->option('dry-run')) {
            $this->info('[DRY RUN] Birthday email dry run — no emails will be sent.');

            return self::SUCCESS;
        }

        $emailOverride = $this->option('email') ?: null;
        $groupIds = null;

        if ($this->option('groups')) {
            $groupIds = array_map('intval', explode(',', $this->option('groups')));
        }

        $count = $service->sendBirthdayEmails($emailOverride, $groupIds);

        $this->info("Sent {$count} birthday email(s).");

        return self::SUCCESS;
    }
}
