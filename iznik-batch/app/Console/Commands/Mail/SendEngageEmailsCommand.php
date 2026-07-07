<?php

namespace App\Console\Commands\Mail;

use App\Services\EngageEmailService;
use Illuminate\Console\Command;

class SendEngageEmailsCommand extends Command
{
    protected $signature = 'mail:engage
                            {--dry-run : Report counts without sending emails or recording attempts}';

    protected $description = 'Send engagement emails to at-risk and inactive users';

    public function handle(EngageEmailService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $prefix = $dryRun ? '[DRY RUN] ' : '';

        $result = $service->processEngageEmails($dryRun);

        $this->info("{$prefix}At-risk emails sent: {$result['at_risk_sent']}");
        $this->info("{$prefix}Inactive emails sent: {$result['inactive_sent']}");

        return self::SUCCESS;
    }
}
