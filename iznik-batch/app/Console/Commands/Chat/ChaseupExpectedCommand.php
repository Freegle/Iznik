<?php

namespace App\Console\Commands\Chat;

use App\Services\ChatChaseupExpectedService;
use App\Traits\LogsBatchJob;
use Illuminate\Console\Command;

class ChaseupExpectedCommand extends Command
{
    use LogsBatchJob;

    protected $signature = 'chats:chaseup-expected
                            {--dry-run : Count eligible chats without sending emails}';

    protected $description = 'Email users who are expected to reply in a User2User chat but have not';

    public function handle(ChatChaseupExpectedService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no emails will be sent.');
        }

        return $this->runWithLogging(function () use ($service, $dryRun) {
            $count = $service->chaseupExpected($dryRun);

            $verb = $dryRun ? 'Would chase up' : 'Chased up';
            $this->info("{$verb} {$count} message(s) waiting for reply.");

            return Command::SUCCESS;
        });
    }
}
