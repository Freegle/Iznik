<?php

namespace App\Console\Commands\Cleanup;

use App\Services\CommonDomainsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateCommonDomainsCommand extends Command
{
    protected $signature = 'domains:update-common
                            {--dry-run : Show what would be updated without making changes}';

    protected $description = 'Update domains_common table with email domains used by > 1000 users';

    public function handle(CommonDomainsService $service): int
    {
        if ($this->option('dry-run')) {
            $this->info('Dry run — no changes made.');
            return Command::SUCCESS;
        }

        Log::info('Starting common domains update');

        $result = $service->updateCommonDomains();

        $this->info("Inserted/updated {$result['domains_inserted']} common domains.");
        Log::info('Common domains update complete', $result);

        return Command::SUCCESS;
    }
}
