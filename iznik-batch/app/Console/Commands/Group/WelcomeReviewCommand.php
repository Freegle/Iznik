<?php

namespace App\Console\Commands\Group;

use App\Services\WelcomeReviewService;
use Illuminate\Console\Command;

class WelcomeReviewCommand extends Command
{
    protected $signature = 'groups:welcome-review
                            {--dry-run : Report counts without sending emails or updating records}';

    protected $description = 'Send a copy of each group\'s welcome mail to mods for annual review (migrated from group_welcomereview.php)';

    public function handle(WelcomeReviewService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $result = $service->sendWelcomeReviews($dryRun);

        $this->line("Groups processed: {$result['groups_processed']}");
        $this->line("Emails sent: {$result['sent']}");

        if ($dryRun) {
            $this->line('[dry-run] No emails sent, no records updated.');
        }

        return Command::SUCCESS;
    }
}
