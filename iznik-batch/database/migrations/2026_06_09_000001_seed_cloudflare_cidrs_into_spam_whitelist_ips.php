<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Cloudflare publishes its egress IP ranges at https://www.cloudflare.com/ips-v4.
     * These are shared CDN/proxy IPs used by many unrelated members (including users
     * of Cloudflare WARP, Cloudflare-proxied services, and Cloudflare Email Routing).
     * Without an entry in spam_whitelist_ips, ContentCheckService::checkIpAbuse()
     * triggers false-positive IP abuse warnings for well-behaved members whose only
     * commonality is routing through the same Cloudflare egress node (Discourse #9768).
     *
     * Also adds RFC 6598 CGNAT (100.64.0.0/10) — carrier-grade NAT shared by many
     * mobile/broadband subscribers — for the same reason.
     */
    public function up(): void
    {
        $ranges = [
            ['ip' => '103.21.244.0/22',  'comment' => 'Cloudflare CDN egress'],
            ['ip' => '103.22.200.0/22',  'comment' => 'Cloudflare CDN egress'],
            ['ip' => '103.31.4.0/22',    'comment' => 'Cloudflare CDN egress'],
            ['ip' => '104.16.0.0/13',    'comment' => 'Cloudflare CDN egress'],
            ['ip' => '104.24.0.0/14',    'comment' => 'Cloudflare CDN egress'],
            ['ip' => '108.162.192.0/18', 'comment' => 'Cloudflare CDN egress'],
            ['ip' => '131.0.72.0/22',    'comment' => 'Cloudflare CDN egress'],
            ['ip' => '141.101.64.0/18',  'comment' => 'Cloudflare CDN egress'],
            ['ip' => '162.158.0.0/15',   'comment' => 'Cloudflare CDN egress'],
            ['ip' => '172.64.0.0/13',    'comment' => 'Cloudflare CDN egress'],
            ['ip' => '173.245.48.0/20',  'comment' => 'Cloudflare CDN egress'],
            ['ip' => '188.114.96.0/20',  'comment' => 'Cloudflare CDN egress'],
            ['ip' => '190.93.240.0/20',  'comment' => 'Cloudflare CDN egress'],
            ['ip' => '197.234.240.0/22', 'comment' => 'Cloudflare CDN egress'],
            ['ip' => '198.41.128.0/17',  'comment' => 'Cloudflare CDN egress'],
            ['ip' => '100.64.0.0/10',    'comment' => 'RFC 6598 CGNAT (shared carrier-grade NAT)'],
        ];

        foreach ($ranges as &$row) {
            $row['date'] = now();
        }

        DB::table('spam_whitelist_ips')->insertOrIgnore($ranges);
    }

    public function down(): void
    {
        $ips = [
            '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
            '104.16.0.0/13', '104.24.0.0/14', '108.162.192.0/18',
            '131.0.72.0/22', '141.101.64.0/18', '162.158.0.0/15',
            '172.64.0.0/13', '173.245.48.0/20', '188.114.96.0/20',
            '190.93.240.0/20', '197.234.240.0/22', '198.41.128.0/17',
            '100.64.0.0/10',
        ];
        DB::table('spam_whitelist_ips')->whereIn('ip', $ips)->delete();
    }
};
