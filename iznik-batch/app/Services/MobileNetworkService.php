<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Seeds UK mobile-carrier IP ranges into spam_whitelist_ips so that CGNAT
 * shared-egress IPs are fully exempt from the IP-abuse check.
 *
 * Mobile carriers route many thousands of unrelated subscribers through a small
 * pool of public egress IPs (carrier-grade NAT). That makes the ">5 distinct
 * accounts from one IP" signal in ContentCheckService::checkIpAbuse() fire on
 * normal, well-behaved members (Discourse #9768). Whitelisting the carriers'
 * announced prefixes exempts those IPs, exactly as Cloudflare ranges are.
 *
 * Ranges are pulled from RIPEstat (free, no API key) by ASN, so they stay
 * current as carriers re-allocate space; run on a monthly schedule. We exempt
 * fully (skip the check) rather than raising a threshold: for CGNAT you cannot
 * distinguish abuse from normal sharing, so a count threshold is meaningless.
 */
class MobileNetworkService
{
    public const RIPESTAT_URL = 'https://stat.ripe.net/data/announced-prefixes/data.json';

    /**
     * UK mobile network operators by ASN. The MVNOs (giffgaff, Tesco Mobile,
     * Sky Mobile, Lebara, Voxi, etc.) ride these operators' ranges, so covering
     * the four MNOs covers their egress too.
     */
    public const UK_MOBILE_ASNS = [
        12576 => 'EE',
        206067 => 'Three (Hutchison 3G UK)',
        25135 => 'Vodafone UK',
        35228 => 'O2 (Telefonica UK)',
    ];

    /**
     * Fetch each carrier's announced IPv4 prefixes and upsert them into
     * spam_whitelist_ips. Returns the number of CIDR rows written.
     *
     * Idempotent: re-running upserts by the unique `ip` column. A failure for
     * one ASN is logged and skipped so the rest still refresh.
     */
    public function refresh(): int
    {
        $now = now();
        $total = 0;

        foreach (self::UK_MOBILE_ASNS as $asn => $name) {
            try {
                $resp = Http::timeout(30)
                    ->retry(2, 500)
                    ->get(self::RIPESTAT_URL, ['resource' => 'AS' . $asn]);
            } catch (\Throwable $e) {
                Log::warning("MobileNetworkService: RIPEstat fetch failed for AS{$asn} ({$name}): " . $e->getMessage());
                continue;
            }

            if (!$resp->successful()) {
                Log::warning("MobileNetworkService: RIPEstat HTTP {$resp->status()} for AS{$asn} ({$name})");
                continue;
            }

            $prefixes = $resp->json('data.prefixes') ?? [];
            $rows = [];

            foreach ($prefixes as $entry) {
                $cidr = is_array($entry) ? ($entry['prefix'] ?? null) : null;
                // IPv4 only — the spam_whitelist_ips CIDR matcher (ip2long) is IPv4.
                if (!is_string($cidr) || $cidr === '' || str_contains($cidr, ':')) {
                    continue;
                }

                $rows[] = [
                    'ip' => $cidr,
                    'comment' => "UK mobile: {$name} (AS{$asn}) — CGNAT shared egress, exempt from IP-abuse (Discourse #9768)",
                    'date' => $now,
                ];
            }

            if (!empty($rows)) {
                DB::table('spam_whitelist_ips')->upsert($rows, ['ip'], ['comment', 'date']);
                $total += count($rows);
            }
        }

        return $total;
    }
}
