<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Refreshes IP ranges into spam_whitelist_ips so that shared-egress IPs are
 * fully exempt from the IP-abuse check (Discourse #9768).
 *
 * Two sources are refreshed:
 *
 *  1. UK mobile carriers (via RIPEstat by ASN) — carrier-grade NAT pools many
 *     unrelated subscribers behind a small set of public IPs, making the
 *     ">5 distinct accounts from one IP" signal fire on well-behaved members.
 *
 *  2. Cloudflare CDN egress (via https://www.cloudflare.com/ips-v4) — Cloudflare
 *     WARP, Cloudflare-proxied sites, and Cloudflare Email Routing all share the
 *     same egress pool, so members with no connection to each other share an IP.
 *
 * Stale entries are PRUNED: rows previously written by this job (identified by
 * their comment markers) that are no longer in the freshly-fetched set are
 * deleted, so the table stays accurate as ranges change over time. Manually-
 * added rows (no recognised marker, or a different comment) are never touched.
 *
 * Run on a monthly schedule.
 */
class MobileNetworkService
{
    public const RIPESTAT_URL = 'https://stat.ripe.net/data/announced-prefixes/data.json';
    public const CLOUDFLARE_IPV4_URL = 'https://www.cloudflare.com/ips-v4';

    /** Comment prefix written for every Cloudflare row — used as ownership marker for pruning. */
    public const CLOUDFLARE_COMMENT = 'Cloudflare CDN egress (auto)';

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
     * Refresh UK mobile-carrier AND Cloudflare IP ranges in spam_whitelist_ips.
     * Stale job-owned rows are pruned. Returns total CIDR rows written.
     */
    public function refresh(): int
    {
        $total = 0;
        $total += $this->refreshMobileCidrs();
        $total += $this->refreshCloudflareCidrs();
        return $total;
    }

    /**
     * Fetch each carrier's announced IPv4 prefixes and upsert them.
     * Prunes stale "UK mobile: …" rows after each ASN is processed.
     */
    private function refreshMobileCidrs(): int
    {
        $now = now();
        $total = 0;
        $allFetched = [];

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

                $allFetched[] = $cidr;
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

        // Prune stale mobile rows (owned by this job) that are no longer in any ASN's current set.
        if (!empty($allFetched)) {
            DB::table('spam_whitelist_ips')
                ->where('comment', 'like', 'UK mobile:%')
                ->whereNotIn('ip', $allFetched)
                ->delete();
        }

        return $total;
    }

    /**
     * Fetch Cloudflare's published IPv4 egress ranges and upsert them.
     * Prunes stale Cloudflare rows after fetching the current list.
     */
    private function refreshCloudflareCidrs(): int
    {
        try {
            $resp = Http::timeout(30)
                ->retry(2, 500)
                ->get(self::CLOUDFLARE_IPV4_URL);
        } catch (\Throwable $e) {
            Log::warning('MobileNetworkService: Cloudflare ips-v4 fetch failed: ' . $e->getMessage());
            return 0;
        }

        if (!$resp->successful()) {
            Log::warning('MobileNetworkService: Cloudflare ips-v4 HTTP ' . $resp->status());
            return 0;
        }

        $now = now();
        $rows = [];
        $fetched = [];

        foreach (explode("\n", trim($resp->body())) as $line) {
            $cidr = trim($line);
            if ($cidr === '' || str_contains($cidr, ':')) {
                continue; // skip blank lines and IPv6
            }
            $fetched[] = $cidr;
            $rows[] = [
                'ip' => $cidr,
                'comment' => self::CLOUDFLARE_COMMENT,
                'date' => $now,
            ];
        }

        if (!empty($rows)) {
            DB::table('spam_whitelist_ips')->upsert($rows, ['ip'], ['comment', 'date']);

            // Prune stale Cloudflare rows no longer in the published list.
            DB::table('spam_whitelist_ips')
                ->where('comment', self::CLOUDFLARE_COMMENT)
                ->whereNotIn('ip', $fetched)
                ->delete();
        }

        return count($rows);
    }
}
