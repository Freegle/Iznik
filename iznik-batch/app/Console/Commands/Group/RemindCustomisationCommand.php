<?php

namespace App\Console\Commands\Group;

use App\Services\GroupCustomisationService;
use App\Traits\LogsBatchJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RemindCustomisationCommand extends Command
{
    use LogsBatchJob;

    protected $signature = 'groups:remind-customisation
                            {--dry-run : Show what would be sent without actually emailing}';

    protected $description = 'Send monthly reminder emails to group mods about missing customisation attributes';

    public function handle(GroupCustomisationService $service): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no emails will be sent.');
        }

        return $this->runWithLogging(function () use ($service, $dryRun) {
            Log::info('Starting group customisation reminders', ['dry_run' => $dryRun]);

            $stats = $service->sendReminders($dryRun);

            $this->info("Checked {$stats['groups_checked']} groups: sent {$stats['sent']} reminder(s), skipped {$stats['skipped']}.");
            Log::info('Group customisation reminders complete', $stats);

            return Command::SUCCESS;
        });
    }
}
