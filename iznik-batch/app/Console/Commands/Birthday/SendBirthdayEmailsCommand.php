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
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('[DRY RUN] Birthday email dry run — no emails will be sent.');
        }

        $emailOverride = $this->option('email') ?: null;
        $groupIds = null;

        if ($this->option('groups')) {
            $groupIds = array_map('intval', explode(',', $this->option('groups')));
        }

        $count = $service->sendBirthdayEmails($emailOverride, $groupIds, $dryRun);

        $verb = $dryRun ? 'Would send' : 'Sent';
        $this->info("{$verb} {$count} birthday email(s).");

        return self::SUCCESS;
    }
}
