<?php

namespace App\Console\Commands\CommunityNews;

use App\Services\CommunityNews\CommunityNewsSourceService;
use Illuminate\Console\Command;

class DiscoverCommunityNewsSourcesCommand extends Command
{
    protected $signature = 'community-news:discover-sources
                            {--force : Health-check + discover even if not due (ignore the ~quarterly gate and recheck throttle)}';

    protected $description = 'Maintain the curated source store: health-check feeds (spot dead ones) and, roughly quarterly, discover new local sources';

    public function handle(CommunityNewsSourceService $service): int
    {
        $force = (bool) $this->option('force');

        $maintain = $service->maintainAll($force);
        $this->info("Health-check: {$maintain['checked']} checked, {$maintain['ok']} ok, {$maintain['dead']} newly dead.");

        $discover = $service->discoverAll($force);
        $this->info(
            "Discovery: {$discover['added']} new source(s) across {$discover['places']} place(s)"
            . ($force ? '.' : ' due for re-discovery.')
        );

        return self::SUCCESS;
    }
}
