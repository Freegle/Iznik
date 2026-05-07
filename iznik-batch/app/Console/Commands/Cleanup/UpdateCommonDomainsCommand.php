<?php

namespace App\Console\Commands\Cleanup;

use App\Services\CommonDomainsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateCommonDomainsCommand extends Command
{
    protected $signature = 'domains:update-common';

    protected $description = 'Update domains_common table with email domains used by > 1000 users';

    public function handle(CommonDomainsService $service): int
    {
        Log::info('Starting common domains update');

        $result = $service->updateCommonDomains();

        $this->info("Inserted/updated {$result['domains_inserted']} common domains.");
        Log::info('Common domains update complete', $result);

        return Command::SUCCESS;
    }
}
