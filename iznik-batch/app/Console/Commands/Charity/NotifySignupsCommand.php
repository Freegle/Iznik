<?php

namespace App\Console\Commands\Charity;

use App\Services\CharitySignupNotifyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class NotifySignupsCommand extends Command
{
    protected $signature = 'charity:notify-signups
                            {--dry-run : Show what would be sent without emailing}';

    protected $description = 'Email geeks about new Charity Partner signups in the charities table';

    public function handle(CharitySignupNotifyService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no email will be sent.');
        }

        $stats = $service->process($dryRun);

        $prefix = $dryRun ? '[DRY RUN] ' : '';
        $this->info("{$prefix}New charity signups notified: {$stats['notified']}");

        Log::info('charity:notify-signups complete', $stats);

        return Command::SUCCESS;
    }
}
