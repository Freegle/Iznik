<?php

namespace App\Console\Commands\Newsfeed;

use App\Services\NewsfeedDigestService;
use App\Traits\LogsBatchJob;
use Illuminate\Console\Command;

class SendDigestCommand extends Command
{
    use LogsBatchJob;

    protected $signature = 'mail:newsfeed:digest
                            {--user= : Only build the digest for this user id}
                            {--dry-run : Count eligible digests without sending emails}';

    protected $description = 'Send the newsfeed (chitchat) digest of recent nearby posts to active users';

    public function handle(NewsfeedDigestService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $userId = $this->option('user') !== null ? (int) $this->option('user') : null;

        if ($dryRun) {
            $this->info('DRY RUN — no emails will be sent.');
        }

        return $this->runWithLogging(function () use ($service, $dryRun, $userId) {
            $count = $service->sendDigests($dryRun, $userId);

            $verb = $dryRun ? 'Would send' : 'Sent';
            $this->info("{$verb} {$count} newsfeed digest(s).");

            return Command::SUCCESS;
        });
    }
}
