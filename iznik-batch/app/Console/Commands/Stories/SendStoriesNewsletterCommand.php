<?php

namespace App\Console\Commands\Stories;

use App\Services\StoriesNewsletterService;
use Illuminate\Console\Command;

class SendStoriesNewsletterCommand extends Command
{
    protected $signature = 'stories:newsletter
                            {--dry-run : Preview without sending or updating the database}';

    protected $description = 'Generate and send the stories newsletter to all eligible Freegle members';

    public function handle(StoriesNewsletterService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $result = $service->generateAndSend(dryRun: $dryRun);

        $prefix = $dryRun ? '[DRY RUN] ' : '';

        if ($result['stories'] === 0) {
            $this->info("{$prefix}Not enough stories to send a newsletter (minimum " . StoriesNewsletterService::MIN_STORIES . " required).");
        } else {
            $this->info("{$prefix}Stories included: {$result['stories']}. Emails sent: {$result['sent']}.");
        }

        return self::SUCCESS;
    }
}
