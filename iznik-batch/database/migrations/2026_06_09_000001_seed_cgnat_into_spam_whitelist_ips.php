<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * RFC 6598 CGNAT range (100.64.0.0/10) is a static, standards-defined
     * shared address block used by ISPs for carrier-grade NAT. It never changes,
     * so it is seeded once here rather than being refreshed by the scheduled job.
     *
     * Cloudflare ranges (previously in this migration) are now fetched live from
     * https://www.cloudflare.com/ips-v4 by the spam:refresh-mobile-cidrs job, so
     * they stay current as Cloudflare adds or removes prefixes.
     */
    public function up(): void
    {
        DB::table('spam_whitelist_ips')->insertOrIgnore([
            [
                'ip'      => '100.64.0.0/10',
                'comment' => 'RFC 6598 CGNAT (shared carrier-grade NAT)',
                'date'    => now(),
            ],
        ]);
    }

    public function down(): void
    {
        DB::table('spam_whitelist_ips')->where('ip', '100.64.0.0/10')->delete();
    }
};
