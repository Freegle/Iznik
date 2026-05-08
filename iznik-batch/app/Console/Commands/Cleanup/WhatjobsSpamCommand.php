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
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('Dry run — counting spam without deleting.');
        } else {
            Log::info('Starting WhatJobs spam cleanup');
        }

        $result = $service->deleteSpammyJobs($dryRun);

        $verb = $dryRun ? 'Would delete' : 'Deleted';
        $this->info("{$verb} {$result['deleted']} jobs across {$result['spam_hashes']} spam hashes.");

        if (!empty($result['samples'])) {
            $this->line('Sample spam hashes (up to 10):');
            foreach ($result['samples'] as $s) {
                $this->line(sprintf('  %s %s × %d', $s['bodyhash'], $s['title'], $s['count']));
            }
        }

        if (!$dryRun) {
            Log::info('WhatJobs spam cleanup complete', ['deleted' => $result['deleted'], 'spam_hashes' => $result['spam_hashes']]);
        }

        return Command::SUCCESS;
    }
}
