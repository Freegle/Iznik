<?php

namespace App\Console\Commands\Cleanup;

use App\Services\WhatjobsSpamService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class WhatjobsSpamCommand extends Command
{
    protected $signature = 'cleanup:whatjobs-spam
                            {--dry-run : Show what would be deleted without making changes}';

    protected $description = 'Delete spammy WhatJobs postings (same body hash, > 50 occurrences)';

    public function handle(WhatjobsSpamService $service): int
    {
        if ($this->option('dry-run')) {
            $this->info('Dry run — no changes made.');
            return Command::SUCCESS;
        }

        Log::info('Starting WhatJobs spam cleanup');

        $result = $service->deleteSpammyJobs();

        $this->info("Deleted {$result['deleted']} jobs across {$result['spam_hashes']} spam hashes.");
        Log::info('WhatJobs spam cleanup complete', $result);

        return Command::SUCCESS;
    }
}
