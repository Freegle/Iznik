<?php

namespace App\Console\Commands\Integrations;

use App\Services\WhatJobsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SyncWhatJobsCommand extends Command
{
    protected $signature = 'integrations:sync-whatjobs
                            {--dry-run : Parse feeds and count jobs without writing to database}';

    protected $description = 'Sync WhatJobs job listings from XML feeds into the jobs table';

    public function handle(WhatJobsService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // The WhatJobs XML feed currently parses ~180k jobs into memory
        // before insertJobs() flushes them in chunks (parseFeed builds the
        // full $jobs[] array and returns it). At PHP's default 512M, this
        // tips into a FatalError as soon as the second (clickcast) feed is
        // merged in. Raise the ceiling here so the run completes; the
        // longer-term fix is to convert parseFeed to a generator and stream
        // straight into insertJobs (TODO tracked in the service).
        ini_set('memory_limit', '1536M');

        $lock = Cache::lock('sync-whatjobs', 3600);
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

            $prefix = $dryRun ? '[DRY RUN] Would insert' : 'Inserted';
            $this->info("$prefix {$result['inserted']} of {$result['total']} parsed jobs.");

            Log::info('WhatJobs sync command complete', $result);
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}
