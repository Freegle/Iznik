<?php

namespace App\Console\Commands\Stories;

use App\Services\StoriesAskService;
use Illuminate\Console\Command;

class AskForStoriesCommand extends Command
{
    protected $signature = 'stories:ask
                            {--dry-run : Report counts without sending emails or recording requests}';

    protected $description = 'Ask eligible users to share their Freegle story (migrated from stories.php)';

    public function handle(StoriesAskService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $result = $service->askForStories($dryRun);

        $this->line("Considered: {$result['considered']}");
        $this->line("Asked: {$result['asked']}");

        if ($dryRun) {
            $this->line('[dry-run] No emails sent, no records written.');
        }

        return Command::SUCCESS;
    }
}
