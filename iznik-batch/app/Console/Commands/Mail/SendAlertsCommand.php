<?php

namespace App\Console\Commands\Mail;

use App\Services\AlertService;
use Illuminate\Console\Command;

class SendAlertsCommand extends Command
{
    protected $signature = 'mail:alerts:send
                            {--dry-run : Preview without sending}';

    protected $description = 'Process incomplete alerts and email them to group moderators';

    public function handle(AlertService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $count = $service->processAlerts(dryRun: $dryRun);

        $prefix = $dryRun ? '[DRY RUN] Would send' : 'Sent';
        $this->info("{$prefix} {$count} alert email(s).");

        return self::SUCCESS;
    }
}
