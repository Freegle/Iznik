<?php

namespace App\Console\Commands\Newsfeed;

use App\Services\NewsfeedLinkPreviewService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GenerateLinkPreviewsCommand extends Command
{
    protected $signature = 'newsfeed:generate-link-previews
                            {--dry-run : Show what would be fetched without making changes}';

    protected $description = 'Fetch and cache link previews for URLs in recent newsfeed posts';

    public function handle(NewsfeedLinkPreviewService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no HTTP requests or database writes will be made.');
        }

        Log::info('Starting newsfeed link preview generation', ['dry_run' => $dryRun]);

        $count = $service->generatePreviews($dryRun);

        $prefix = $dryRun ? '[DRY RUN] ' : '';
        $verb = $dryRun ? 'Would fetch' : 'Generated';
        $this->info("{$prefix}{$verb} {$count} link preview(s).");
        Log::info('Newsfeed link preview generation complete', ['count' => $count, 'dry_run' => $dryRun]);

        return Command::SUCCESS;
    }
}
