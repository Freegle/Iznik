<?php

namespace App\Console\Commands\Group;

use App\Services\GroupClosedReminderService;
use App\Traits\LogsBatchJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RemindClosedGroupsCommand extends Command
{
    use LogsBatchJob;

    protected $signature = 'groups:remind-closed
                            {--dry-run : Show what would be sent without actually emailing}';

    protected $description = 'Send weekly reminders to mods of groups that are currently closed';

    public function handle(GroupClosedReminderService $service): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no emails will be sent.');
        }

        return $this->runWithLogging(function () use ($service, $dryRun) {
            Log::info('Starting closed group reminders', ['dry_run' => $dryRun]);

            $stats = $service->sendReminders($dryRun);

            $this->info("Checked {$stats['groups_checked']} closed group(s): sent {$stats['sent']} reminder(s), skipped {$stats['skipped']}.");
            Log::info('Closed group reminders complete', $stats);

            return Command::SUCCESS;
        });
    }
}
