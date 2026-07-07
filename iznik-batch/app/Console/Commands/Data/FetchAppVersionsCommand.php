<?php

namespace App\Console\Commands\Data;

use App\Services\AppVersionFetcherService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FetchAppVersionsCommand extends Command
{
    protected $signature = 'data:fetch-app-versions
                            {--dry-run : Show what would be fetched without making changes}';

    protected $description = 'Fetch latest iOS and Android app versions from app stores and store in config';

    public function handle(AppVersionFetcherService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('Dry run — fetching versions but not writing to config.');
        } else {
            Log::info('FetchAppVersions: Starting');
        }

        $result = $service->fetchAll($dryRun);

        $this->info('Fetched: ' . implode(', ', $result['fetched']));

        if (!empty($result['failed'])) {
            $this->warn('Failed: ' . implode(', ', $result['failed']));
        }

        if (!empty($result['writes'])) {
            $current = DB::table('config')
                ->whereIn('key', array_keys($result['writes']))
                ->pluck('value', 'key')
                ->all();

            $this->line($dryRun ? 'Would write:' : 'Wrote:');
            foreach ($result['writes'] as $key => $value) {
                $old = $current[$key] ?? '(unset)';
                $marker = ($old === $value) ? '=' : '→';
                $this->line(sprintf('  %s  %-40s  %s %s %s', $marker, $key, $old, $marker === '=' ? '==' : '→', $value));
            }
        }

        if (!$dryRun) {
            Log::info('FetchAppVersions: Done', $result);
        }

        return Command::SUCCESS;
    }
}
