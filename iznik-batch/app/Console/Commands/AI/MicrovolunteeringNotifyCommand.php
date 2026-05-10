<?php

namespace App\Console\Commands\AI;

use App\Services\MicrovolunteeringNotifyService;
use App\Traits\LogsBatchJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class MicrovolunteeringNotifyCommand extends Command
{
    use LogsBatchJob;

    protected $signature = 'microvolunteering:notify
                            {--dry-run : Show what would be notified without inserting rows}';

    protected $description = 'Notify active users about messages awaiting microvolunteering review';

    public function handle(MicrovolunteeringNotifyService $service): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no notifications will be inserted.');
        }

        return $this->runWithLogging(function () use ($service, $dryRun) {
            Log::info('Starting microvolunteering notify', ['dry_run' => $dryRun]);

            $stats = $service->notifyForMessages($dryRun);

            $verb = $dryRun ? 'would notify' : 'notified';
            $this->info(
                "Considered {$stats['messages_considered']} messages: {$verb} {$stats['users_notified']} user(s), skipped {$stats['users_skipped']}."
            );

            Log::info('Microvolunteering notify complete', $stats);

            return Command::SUCCESS;
        });
    }
}
