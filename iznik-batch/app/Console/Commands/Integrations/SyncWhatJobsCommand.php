<?php

namespace App\Console\Commands\Integrations;

use App\Services\WhatJobsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SyncWhatJobsCommand extends Command
{
    protected $signature = 'integrations:sync-whatjobs
                            {--dry-run : Parse feeds and count jobs without writing to database}
                            {--force : Rebuild even if the feeds are unchanged since the last run}
                            {--refresh-geocode : Ignore the jobs-table geocode cache so every tuple re-geocodes fresh (one-time, to retro-correct mis-cached locations)}';

    protected $description = 'Sync WhatJobs job listings from XML feeds into the jobs table';

    public function handle(WhatJobsService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $service->forceRegeocode = (bool) $this->option('refresh-geocode');
        $service->forceFullSync = (bool) $this->option('force');
        if ($service->forceRegeocode) {
            $this->info('--refresh-geocode: bypassing jobs-table geocode cache (one-time full re-geocode).');
        }
        if ($service->forceFullSync) {
            $this->info('--force: rebuilding even if the feeds are unchanged.');
        }

        // The WhatJobs XML feed currently parses ~180k jobs into memory
        // before insertJobs() flushes them in chunks (parseFeed builds the
        // full $jobs[] array and returns it). At PHP's default 512M, this
        // tips into a FatalError as soon as the second (clickcast) feed is
        // merged in. Raise the ceiling here so the run completes; the
        // longer-term fix is to convert parseFeed to a generator and stream
        // straight into insertJobs (TODO tracked in the service).
        ini_set('memory_limit', '1536M');

        // TTL matches the every-3-hours schedule (routes/console.php) plus
        // a comfortable margin: a cold-cache run that hits Photon for every
        // distinct city tuple can run for hours, far longer than the prior
        // 1h TTL.
        $lock = Cache::lock('sync-whatjobs', 4 * 3600);
        if (!$lock->get()) {
            $this->warn('Another integrations:sync-whatjobs is already running, skipping.');
            return self::SUCCESS;
        }

        try {
            if ($dryRun) {
                $this->info('[DRY RUN] Parsing WhatJobs feeds — no database changes will be made.');
            }

            Log::info('WhatJobs sync starting', ['dry_run' => $dryRun]);

            $result = $service->sync($dryRun);

            if (!empty($result['skipped_unchanged'])) {
                $this->info('Feeds unchanged since the last rebuild - nothing to do. Use --force to rebuild anyway.');
            } else {
                $prefix = $dryRun ? '[DRY RUN] Would insert' : 'Inserted';
                $this->info("$prefix {$result['inserted']} of {$result['total']} parsed jobs.");
            }

            Log::info('WhatJobs sync command complete', $result);
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}
