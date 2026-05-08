<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CommonDomainsService
{
    public function updateCommonDomains(bool $dryRun = false): array
    {
        $total = DB::table('users_emails')->count();
        Log::info("CommonDomains: Found {$total} emails");

        $count = 0;
        $domains = [];

        DB::table('users_emails')
            ->select('id', 'email')
            ->orderBy('id')
            ->chunkById(10000, function ($rows) use (&$count, &$domains, $total) {
                foreach ($rows as $row) {
                    $p = strpos($row->email, '@');
                    if ($p > 0) {
                        $domain = substr($row->email, $p + 1);
                        $domains[$domain] = ($domains[$domain] ?? 0) + 1;
                    }
                    $count++;
                }
                Log::info("CommonDomains: ...{$count} / {$total}");
            });

        Log::info('CommonDomains: Got ' . count($domains) . ' domains');

        $inserted = 0;
        $writes = [];

        foreach ($domains as $domain => $domainCount) {
            if ($domainCount > 1000) {
                $writes[$domain] = $domainCount;
                if (!$dryRun) {
                    DB::table('domains_common')->upsert(
                        ['domain' => $domain, 'count' => $domainCount],
                        ['domain'],
                        ['count']
                    );
                }
                $inserted++;
            }
        }

        return [
            'emails_scanned' => $total,
            'distinct_domains' => count($domains),
            'domains_inserted' => $inserted,
            'writes' => $writes,
        ];
    }
}
