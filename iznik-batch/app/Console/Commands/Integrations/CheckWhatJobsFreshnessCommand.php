<?php

namespace App\Console\Commands\Integrations;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Fails when the newest WhatJobs job is older than the limit (48 hours), so the SysAdmin Cron
 * Jobs tab goes red: the scheduler records the exit code (CronJobStatusListener) and the tab
 * counts a failed last run.
 *
 * The sync's own check watches jobs.seenat, which proves the sync ran. That stays green when
 * WhatJobs stops publishing, because the sync still rebuilds the table from the old file. On
 * 20 October 2026 the feed stopped and nobody noticed until every job on the site looked old.
 * The age of the newest job catches both: a feed that has stopped and a sync that has.
 */
#[AsCommand(name: 'integrations:check-whatjobs-freshness')]
class CheckWhatJobsFreshnessCommand extends Command
{
    protected $signature = 'integrations:check-whatjobs-freshness';

    protected $description = 'Fail when the newest WhatJobs job is more than 48 hours old';

    public function handle(): int
    {
        $feed = trim((string) config('freegle.whatjobs.feed1', ''));
        if ($feed === '') {
            $this->info('No WhatJobs feed configured: nothing to check.');

            return self::SUCCESS;
        }

        $limitHours = (int) config('freegle.whatjobs.max_feed_age_hours', 48);
        $newest = DB::table('jobs')->max('posted_at');

        if ($newest === null) {
            $this->error('There are no WhatJobs jobs at all. The sync has never loaded the feed, or emptied it.');

            return self::FAILURE;
        }

        $age = Carbon::parse($newest)->diffInHours(now());
        if ($age <= $limitHours) {
            $this->info("Newest WhatJobs job was posted {$newest}, {$age} hours ago.");

            return self::SUCCESS;
        }

        $modified = $this->feedLastModified($feed);
        $this->error(
            "The newest WhatJobs job was posted {$newest}, {$age} hours ago (limit {$limitHours}). "
            .($modified
                ? "WhatJobs last changed the feed {$modified}: if that is old too, the feed has stopped and WhatJobs need telling."
                : 'Could not ask WhatJobs when the feed last changed.')
            .' Otherwise see the integrations:sync-whatjobs cron log.'
        );

        return self::FAILURE;
    }

    /** When WhatJobs last changed the feed file, from its Last-Modified header. */
    private function feedLastModified(string $url): ?string
    {
        try {
            $response = Http::timeout(30)->head($url);

            return $response->successful() ? ($response->header('Last-Modified') ?: null) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
