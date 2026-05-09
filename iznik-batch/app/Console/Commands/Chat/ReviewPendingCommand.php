<?php

namespace App\Console\Commands\Chat;

use App\Services\ChatReviewService;
use Illuminate\Console\Command;

class ReviewPendingCommand extends Command
{
    protected $signature = 'chats:review-pending
                            {--dry-run : Report counts without sending emails or updating records}';

    protected $description = 'Auto-reject stale chat review messages (7+ days) and notify group mods of pending reviews (48+ hours)';

    public function handle(ChatReviewService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $prefix = $dryRun ? '[DRY RUN] ' : '';

        $result = $service->processReview($dryRun);

        $this->info("{$prefix}{$result['rejected']} messages auto-rejected (stuck > 7 days)");
        $this->info("{$prefix}{$result['notified_groups']} groups notified, {$result['total_pending']} messages pending review (> 48 hours)");

        return self::SUCCESS;
    }
}
