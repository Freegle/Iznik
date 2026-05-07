<?php

namespace App\Console\Commands\Data;

use App\Services\AppVersionFetcherService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class FetchAppVersionsCommand extends Command
{
    protected $signature = 'data:fetch-app-versions';

    protected $description = 'Fetch latest iOS and Android app versions from app stores and store in config';

    public function handle(AppVersionFetcherService $service): int
    {
        Log::info('FetchAppVersions: Starting');

        $result = $service->fetchAll();

        $this->info('Fetched: ' . implode(', ', $result['fetched']));

        if (!empty($result['failed'])) {
            $this->warn('Failed: ' . implode(', ', $result['failed']));
        }

        Log::info('FetchAppVersions: Done', $result);

        return Command::SUCCESS;
    }
}
