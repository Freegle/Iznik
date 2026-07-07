<?php

namespace App\Console\Commands\Spam;

use App\Services\SpamCleanupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckSpammersCommand extends Command
{
    protected $signature = 'users:remove-spammers
                            {--dry-run : Show counts without making changes}';

    protected $description = 'Remove spam members from groups and clean up their content (V1: check_spammers.php)';

    public function handle(SpamCleanupService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no changes will be made.');
        }

        $stats = $service->removeSpamMembers($dryRun);

        $verb = $dryRun ? 'would' : 'did';
        $this->info(
            "users:remove-spammers {$verb}: " .
            "memberships={$stats['memberships']} messages={$stats['messages']} " .
            "chat_messages={$stats['chat_messages']} newsfeed={$stats['newsfeed']} " .
            "notifications={$stats['notifications']} expected={$stats['expected']} " .
            "sessions={$stats['sessions']}"
        );

        Log::info('users:remove-spammers complete', $stats + ['dry_run' => $dryRun]);

        return Command::SUCCESS;
    }
}
