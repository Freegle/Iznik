<?php

namespace App\Console\Commands\Mail;

use App\Services\EventsDigestService;
use App\Traits\LogsBatchJob;
use Illuminate\Console\Command;

class SendEventsDigestCommand extends Command
{
    use LogsBatchJob;

    protected $signature = 'mail:events-digest
                            {--dry-run : Count eligible sends without actually sending emails}';

    protected $description = 'Send community event roundup emails to group members';

    public function handle(EventsDigestService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no emails will be sent.');
        }

        return $this->runWithLogging(function () use ($service, $dryRun) {
            $stats = $service->sendEventDigests($dryRun);

            $verb = $dryRun ? 'Would send' : 'Sent';
            $this->info("{$verb} {$stats['sent']} email(s) for {$stats['groups_processed']} group(s).");

            return Command::SUCCESS;
        });
    }
}
