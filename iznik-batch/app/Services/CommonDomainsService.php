<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CommonDomainsService
{
    public function updateCommonDomains(): array
    {
        $emails = DB::table('users_emails')->pluck('email');
        $total = $emails->count();

        Log::info("CommonDomains: Found {$total} emails");

        $count = 0;
        $domains = [];

        foreach ($emails as $email) {
            $p = strpos($email, '@');

            if ($p > 0) {
                $domain = substr($email, $p + 1);
                $domains[$domain] = ($domains[$domain] ?? 0) + 1;
            }

            $count++;

            if ($count % 1000 === 0) {
                Log::info("CommonDomains: ...{$count} / {$total}");
            }
        }

        Log::info('CommonDomains: Got ' . count($domains) . ' domains');

        $inserted = 0;

        foreach ($domains as $domain => $domainCount) {
            if ($domainCount > 1000) {
                DB::table('domains_common')->upsert(
                    ['domain' => $domain, 'count' => $domainCount],
                    ['domain'],
                    ['count']
                );
                $inserted++;
            }
        }

        return ['domains_inserted' => $inserted];
    }
}
