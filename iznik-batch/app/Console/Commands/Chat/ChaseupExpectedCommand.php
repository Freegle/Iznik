<?php

namespace App\Console\Commands\Chat;

use App\Services\ChatChaseupExpectedService;
use App\Traits\LogsBatchJob;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'chats:chaseup-expected')]
class ChaseupExpectedCommand extends Command
{
    use LogsBatchJob;

    protected $signature = 'chats:chaseup-expected
                            {--dry-run : Count eligible chats without sending emails}
                            {--limit= : Send at most this many chase-ups in one run, oldest first}';

    protected $description = 'Email users who are expected to reply in a User2User chat but have not';

    public function handle(ChatChaseupExpectedService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = $this->option('limit');

        if ($limit !== null && (! ctype_digit((string) $limit) || (int) $limit < 1)) {
            $this->error('--limit must be a whole number of at least 1.');

            return Command::FAILURE;
        }
        $limit = $limit === null ? null : (int) $limit;

        if ($dryRun) {
            $this->info('DRY RUN — no emails will be sent.');
        }

        return $this->runWithLogging(function () use ($service, $dryRun, $limit) {
            $count = $service->chaseupExpected($dryRun, $limit);

            $verb = $dryRun ? 'Would chase up' : 'Chased up';
            $this->info("{$verb} {$count} message(s) waiting for reply.");

            return Command::SUCCESS;
        });
    }
}
