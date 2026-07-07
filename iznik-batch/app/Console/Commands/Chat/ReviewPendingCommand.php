<?php

namespace App\Console\Commands\Chat;

use App\Services\ChatReviewPendingService;
use App\Traits\LogsBatchJob;
use Illuminate\Console\Command;

class ReviewPendingCommand extends Command
{
    use LogsBatchJob;

    protected $signature = 'chats:review-pending
                            {--dry-run : Count pending messages without sending emails or auto-rejecting}';

    protected $description = 'Auto-reject stale chat review messages and notify mods about pending ones';

    public function handle(ChatReviewPendingService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no emails will be sent and no messages will be auto-rejected.');
        }

        return $this->runWithLogging(function () use ($service, $dryRun) {
            $result = $service->processReview($dryRun);

            $verb = $dryRun ? 'Would auto-reject' : 'Auto-rejected';
            $this->info("{$verb} {$result['auto_rejected']} message(s) stuck in review for "
                . ChatReviewPendingService::AUTO_REJECT_DAYS . '+ days.');

            $verb = $dryRun ? 'Would notify' : 'Notified';
            $this->info("{$verb} mods for {$result['groups_notified']} group(s) with messages pending "
                . ChatReviewPendingService::NOTIFY_HOURS . '+ hours.');

            return Command::SUCCESS;
        });
    }
}
