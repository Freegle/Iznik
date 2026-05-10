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
