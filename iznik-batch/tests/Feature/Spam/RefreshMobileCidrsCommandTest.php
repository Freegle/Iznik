<?php

namespace Tests\Feature\Spam;

use App\Services\MobileNetworkService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * spam:refresh-mobile-cidrs pulls UK mobile-carrier announced prefixes from
 * RIPEstat (by ASN) and seeds them into spam_whitelist_ips, so CGNAT shared
 * egress IPs are fully exempt from the IP-abuse check (Discourse #9768).
 */
class RefreshMobileCidrsCommandTest extends TestCase
{
    private function fakeRipestat(array $prefixes): void
    {
        Http::fake([
            'stat.ripe.net/*' => Http::response([
                'data' => [
                    'prefixes' => array_map(fn ($p) => ['prefix' => $p], $prefixes),
                ],
            ], 200),
        ]);
    }

    public function test_seeds_ipv4_mobile_cidrs_from_ripestat(): void
    {
        DB::table('spam_whitelist_ips')->where('comment', 'like', 'UK mobile:%')->delete();
        $this->fakeRipestat(['149.254.0.0/16', '188.28.0.0/14']);

        $this->artisan('spam:refresh-mobile-cidrs')->assertExitCode(0);

        $this->assertDatabaseHas('spam_whitelist_ips', ['ip' => '149.254.0.0/16']);
        $this->assertDatabaseHas('spam_whitelist_ips', ['ip' => '188.28.0.0/14']);
        // The comment identifies these as mobile exemptions for auditability.
        $row = DB::table('spam_whitelist_ips')->where('ip', '149.254.0.0/16')->first();
        $this->assertStringContainsString('UK mobile', $row->comment);
    }

    public function test_skips_ipv6_prefixes(): void
    {
        DB::table('spam_whitelist_ips')->where('comment', 'like', 'UK mobile:%')->delete();
        $this->fakeRipestat(['149.254.0.0/16', '2a02:c7c::/29']);

        $this->artisan('spam:refresh-mobile-cidrs')->assertExitCode(0);

        // IPv4 seeded; IPv6 skipped (the spam_whitelist_ips CIDR matcher is IPv4-only).
        $this->assertDatabaseHas('spam_whitelist_ips', ['ip' => '149.254.0.0/16']);
        $this->assertDatabaseMissing('spam_whitelist_ips', ['ip' => '2a02:c7c::/29']);
    }

    public function test_is_idempotent_on_rerun(): void
    {
        DB::table('spam_whitelist_ips')->where('comment', 'like', 'UK mobile:%')->delete();
        $this->fakeRipestat(['149.254.0.0/16']);

        $this->artisan('spam:refresh-mobile-cidrs')->assertExitCode(0);
        // Second run must not throw a unique-constraint violation and must not duplicate.
        $this->artisan('spam:refresh-mobile-cidrs')->assertExitCode(0);

        $this->assertSame(
            1,
            DB::table('spam_whitelist_ips')->where('ip', '149.254.0.0/16')->count()
        );
    }

    public function test_survives_ripestat_failure_for_one_asn(): void
    {
        DB::table('spam_whitelist_ips')->where('comment', 'like', 'UK mobile:%')->delete();
        // RIPEstat returns 500 — the command should not error out the whole run.
        Http::fake(['stat.ripe.net/*' => Http::response('upstream error', 500)]);

        $this->artisan('spam:refresh-mobile-cidrs')->assertExitCode(0);

        $this->assertSame(
            0,
            DB::table('spam_whitelist_ips')->where('comment', 'like', 'UK mobile:%')->count()
        );
    }

    public function test_service_targets_the_uk_mobile_mnos(): void
    {
        // Guard the carrier list so an accidental removal is caught.
        $names = implode(' ', MobileNetworkService::UK_MOBILE_ASNS);
        foreach (['EE', 'Three', 'Vodafone', 'O2'] as $carrier) {
            $this->assertStringContainsString($carrier, $names, "Expected UK MNO {$carrier} in the ASN list");
        }
    }
}
