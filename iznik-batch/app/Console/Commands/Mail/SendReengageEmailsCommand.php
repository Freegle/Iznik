<?php

namespace App\Console\Commands\Mail;

use App\Services\ReengageService;
use Illuminate\Console\Command;

class SendReengageEmailsCommand extends Command
{
    protected $signature = 'mail:reengage
                            {--dry-run : Report counts without sending emails or recording sends}
                            {--preview= : Send the whole tip sequence with sample data to this address (for mailpit review); bypasses the dark-ship gates}';

    protected $description = 'Send the first-week onboarding tip sequence to new members (dark unless FREEGLE_REENGAGE_ALLOWLIST + type gate are set)';

    public function handle(ReengageService $service): int
    {
        $preview = $this->option('preview');
        if ($preview) {
            $count = $service->sendPreview((string) $preview);
            $this->info("Sent {$count} preview tip email(s) to {$preview}");

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $prefix = $dryRun ? '[DRY RUN] ' : '';

        $result = $service->processReengageEmails($dryRun);

        $this->info("{$prefix}Tips sent: {$result['sent']}");
        $this->info("{$prefix}Control (holdout, recorded not sent): {$result['control']}");
        $this->info("{$prefix}Sequences completed: {$result['completed']}");
        $this->info("{$prefix}Not yet due / skipped: {$result['skipped']}");

        return self::SUCCESS;
    }
}
