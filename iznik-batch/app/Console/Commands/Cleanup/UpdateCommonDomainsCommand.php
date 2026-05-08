<?php

namespace App\Console\Commands\Cleanup;

use App\Services\CommonDomainsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdateCommonDomainsCommand extends Command
{
    protected $signature = 'domains:update-common
                            {--dry-run : Show what would be updated without making changes}';

    protected $description = 'Update domains_common table with email domains used by > 1000 users';

    public function handle(CommonDomainsService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('Dry run — scanning emails but not writing to domains_common.');
        } else {
            Log::info('Starting common domains update');
        }

        $result = $service->updateCommonDomains($dryRun);

        $this->info("Scanned {$result['emails_scanned']} emails, found {$result['distinct_domains']} distinct domains.");
        $this->info(($dryRun ? 'Would insert/update ' : 'Inserted/updated ') . "{$result['domains_inserted']} common domains (>1000 users).");

        if (!empty($result['writes'])) {
            $current = DB::table('domains_common')
                ->whereIn('domain', array_keys($result['writes']))
                ->pluck('count', 'domain')
                ->all();
            arsort($result['writes']);
            $this->line($dryRun ? 'Top would-write entries:' : 'Top wrote entries:');
            $shown = 0;
            foreach ($result['writes'] as $domain => $cnt) {
                $old = $current[$domain] ?? '(new)';
                $this->line(sprintf('  %-40s  %s → %d', $domain, (string) $old, $cnt));
                if (++$shown >= 20) {
                    $remaining = count($result['writes']) - $shown;
                    if ($remaining > 0) {
                        $this->line(sprintf('  ... and %d more', $remaining));
                    }
                    break;
                }
            }
        }

        if (!$dryRun) {
            Log::info('Common domains update complete', ['emails_scanned' => $result['emails_scanned'], 'domains_inserted' => $result['domains_inserted']]);
        }

        return Command::SUCCESS;
    }
}
