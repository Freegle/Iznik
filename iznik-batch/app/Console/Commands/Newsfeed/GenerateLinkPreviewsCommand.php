<?php

namespace App\Console\Commands\Newsfeed;

use App\Services\NewsfeedLinkPreviewService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GenerateLinkPreviewsCommand extends Command
{
    protected $signature = 'newsfeed:generate-link-previews';

    protected $description = 'Fetch and cache link previews for URLs in recent newsfeed posts';

    public function handle(NewsfeedLinkPreviewService $service): int
    {
        $count = $service->generatePreviews();

        $this->info("Generated {$count} link previews.");
        Log::info('Newsfeed link previews complete', ['count' => $count]);

        return Command::SUCCESS;
    }
}
