<?php

namespace App\Console\Commands\Mail;

use App\Services\NewsfeedModNotifService;
use App\Traits\LogsBatchJob;
use Illuminate\Console\Command;

class NewsfeedModNotifCommand extends Command
{
    use LogsBatchJob;

    protected $signature = 'mail:newsfeed-mod-notif
                            {--dry-run : Count eligible notifications without sending emails}';

    protected $description = 'Notify group mods about new chitchat posts from their members';

    public function handle(NewsfeedModNotifService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no emails will be sent.');
        }

        return $this->runWithLogging(function () use ($service, $dryRun) {
            $stats = $service->notifyMods($dryRun);

            $verb = $dryRun ? 'Would notify' : 'Notified';
            $this->info("{$verb} {$stats['notified']} mod(s) out of {$stats['mods_checked']} checked.");

            return Command::SUCCESS;
        });
    }
}
