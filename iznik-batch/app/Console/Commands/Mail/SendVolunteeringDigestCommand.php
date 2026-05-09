<?php

namespace App\Console\Commands\Mail;

use App\Services\VolunteeringDigestService;
use App\Traits\LogsBatchJob;
use Illuminate\Console\Command;

class SendVolunteeringDigestCommand extends Command
{
    use LogsBatchJob;

    protected $signature = 'mail:volunteering-digest
                            {--dry-run : Count eligible sends without actually sending emails}';

    protected $description = 'Send volunteering opportunity roundup emails to group members';

    public function handle(VolunteeringDigestService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no emails will be sent.');
        }

        return $this->runWithLogging(function () use ($service, $dryRun) {
            $stats = $service->sendVolunteeringDigests($dryRun);

            $verb = $dryRun ? 'Would send' : 'Sent';
            $this->info("{$verb} {$stats['sent']} email(s) for {$stats['groups_processed']} group(s).");

            return Command::SUCCESS;
        });
    }
}
