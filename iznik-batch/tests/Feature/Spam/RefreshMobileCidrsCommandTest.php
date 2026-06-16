<?php

namespace Tests\Feature\Spam;

use App\Services\MobileNetworkService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * spam:refresh-mobile-cidrs pulls UK mobile-carrier announced prefixes from
 * RIPEstat (by ASN) and Cloudflare egress ranges from the published ips-v4
 * list, then seeds them into spam_whitelist_ips, exempting CGNAT/CDN shared
 * egress IPs from the IP-abuse check (Discourse #9768).
 *
 * Stale job-owned rows (comment markers "UK mobile:…" or the Cloudflare
 * constant) that are no longer in the current upstream set are pruned.
 * Manually-added rows (absent or different comment) are never touched.
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

    private function fakeBoth(array $mobilePrefixes, array $cloudflarePrefixes): void
    {
        Http::fake([
            'stat.ripe.net/*' => Http::response([
                'data' => [
                    'prefixes' => array_map(fn ($p) => ['prefix' => $p], $mobilePrefixes),
                ],
            ], 200),
            'www.cloudflare.com/ips-v4' => Http::response(
                implode("\n", $cloudflarePrefixes),
                200
            ),
        ]);
    }

    // ── UK mobile (RIPEstat) ──────────────────────────────────────────────────

    public function test_seeds_ipv4_mobile_cidrs_from_ripestat(): void
    {
        DB::table('spam_whitelist_ips')->where('comment', 'like', 'UK mobile:%')->delete();
        $this->fakeBoth(['149.254.0.0/16', '188.28.0.0/14'], ['104.16.0.0/13']);

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
        $this->fakeBoth(['149.254.0.0/16', '2a02:c7c::/29'], []);

        $this->artisan('spam:refresh-mobile-cidrs')->assertExitCode(0);

        // IPv4 seeded; IPv6 skipped (the spam_whitelist_ips CIDR matcher is IPv4-only).
        $this->assertDatabaseHas('spam_whitelist_ips', ['ip' => '149.254.0.0/16']);
        $this->assertDatabaseMissing('spam_whitelist_ips', ['ip' => '2a02:c7c::/29']);
    }

    public function test_is_idempotent_on_rerun(): void
    {
        DB::table('spam_whitelist_ips')->where('comment', 'like', 'UK mobile:%')->delete();
        DB::table('spam_whitelist_ips')->where('comment', MobileNetworkService::CLOUDFLARE_COMMENT)->delete();
        $this->fakeBoth(['149.254.0.0/16'], ['104.16.0.0/13']);

        $this->artisan('spam:refresh-mobile-cidrs')->assertExitCode(0);
        // Second run must not throw a unique-constraint violation and must not duplicate.
        $this->artisan('spam:refresh-mobile-cidrs')->assertExitCode(0);

        $this->assertSame(
            1,
            DB::table('spam_whitelist_ips')->where('ip', '149.254.0.0/16')->count()
        );
        $this->assertSame(
            1,
            DB::table('spam_whitelist_ips')->where('ip', '104.16.0.0/13')->count()
        );
    }

    public function test_survives_ripestat_failure_for_one_asn(): void
    {
        DB::table('spam_whitelist_ips')->where('comment', 'like', 'UK mobile:%')->delete();
        // RIPEstat returns 500 — the command should not error out the whole run.
        Http::fake([
            'stat.ripe.net/*' => Http::response('upstream error', 500),
            'www.cloudflare.com/ips-v4' => Http::response('104.16.0.0/13', 200),
        ]);

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

    // ── Cloudflare ────────────────────────────────────────────────────────────

    public function test_seeds_cloudflare_cidrs_with_marker_comment(): void
    {
        DB::table('spam_whitelist_ips')->where('comment', MobileNetworkService::CLOUDFLARE_COMMENT)->delete();
        $this->fakeBoth([], ['104.16.0.0/13', '162.158.0.0/15']);

        $this->artisan('spam:refresh-mobile-cidrs')->assertExitCode(0);

        $this->assertDatabaseHas('spam_whitelist_ips', [
            'ip'      => '104.16.0.0/13',
            'comment' => MobileNetworkService::CLOUDFLARE_COMMENT,
        ]);
        $this->assertDatabaseHas('spam_whitelist_ips', [
            'ip'      => '162.158.0.0/15',
            'comment' => MobileNetworkService::CLOUDFLARE_COMMENT,
        ]);
    }

    public function test_cloudflare_idempotent_on_rerun(): void
    {
        DB::table('spam_whitelist_ips')->where('comment', MobileNetworkService::CLOUDFLARE_COMMENT)->delete();
        $this->fakeBoth([], ['104.16.0.0/13']);

        $this->artisan('spam:refresh-mobile-cidrs')->assertExitCode(0);
        $this->artisan('spam:refresh-mobile-cidrs')->assertExitCode(0);

        $this->assertSame(
            1,
            DB::table('spam_whitelist_ips')->where('ip', '104.16.0.0/13')->count()
        );
    }

    public function test_survives_cloudflare_fetch_failure(): void
    {
        DB::table('spam_whitelist_ips')->where('comment', MobileNetworkService::CLOUDFLARE_COMMENT)->delete();
        Http::fake([
            'stat.ripe.net/*' => Http::response([
                'data' => ['prefixes' => [['prefix' => '149.254.0.0/16']]],
            ], 200),
            'www.cloudflare.com/ips-v4' => Http::response('bad gateway', 502),
        ]);

        // Command must still succeed; mobile rows still seeded; Cloudflare rows absent.
        $this->artisan('spam:refresh-mobile-cidrs')->assertExitCode(0);

        $this->assertSame(
            0,
            DB::table('spam_whitelist_ips')->where('comment', MobileNetworkService::CLOUDFLARE_COMMENT)->count()
        );
    }

    // ── RFC 6598 CGNAT constant ───────────────────────────────────────────────

    public function test_cgnat_range_is_always_written_alongside_cloudflare(): void
    {
        // 100.64.0.0/10 is a static constant; it is included on every successful
        // Cloudflare fetch so it survives the pruning cycle under the same marker.
        DB::table('spam_whitelist_ips')->where('comment', MobileNetworkService::CLOUDFLARE_COMMENT)->delete();
        $this->fakeBoth([], ['104.16.0.0/13']);

        $this->artisan('spam:refresh-mobile-cidrs')->assertExitCode(0);

        $this->assertDatabaseHas('spam_whitelist_ips', [
            'ip'      => MobileNetworkService::CGNAT_CIDR,
            'comment' => MobileNetworkService::CLOUDFLARE_COMMENT,
        ]);
    }

    public function test_cgnat_range_is_idempotent_on_rerun(): void
    {
        DB::table('spam_whitelist_ips')->where('comment', MobileNetworkService::CLOUDFLARE_COMMENT)->delete();
        $this->fakeBoth([], []);

        $this->artisan('spam:refresh-mobile-cidrs')->assertExitCode(0);
        $this->artisan('spam:refresh-mobile-cidrs')->assertExitCode(0);

        $this->assertSame(
            1,
            DB::table('spam_whitelist_ips')->where('ip', MobileNetworkService::CGNAT_CIDR)->count(),
            '100.64.0.0/10 must appear exactly once even after two runs'
        );
    }

    // ── Pruning ───────────────────────────────────────────────────────────────

    public function test_prunes_stale_mobile_rows_no_longer_in_upstream(): void
    {
        // Seed a stale mobile row that will NOT appear in the fake RIPEstat response.
        DB::table('spam_whitelist_ips')->insertOrIgnore([
            'ip'      => '10.20.30.0/24',
            'comment' => 'UK mobile: EE (AS12576) — CGNAT shared egress, exempt from IP-abuse (Discourse #9768)',
            'date'    => now(),
        ]);

        $this->fakeBoth(['149.254.0.0/16'], ['104.16.0.0/13']);

        $this->artisan('spam:refresh-mobile-cidrs')->assertExitCode(0);

        // The stale row must be gone.
        $this->assertDatabaseMissing('spam_whitelist_ips', ['ip' => '10.20.30.0/24']);
        // Fresh rows must be present.
        $this->assertDatabaseHas('spam_whitelist_ips', ['ip' => '149.254.0.0/16']);

        // Cleanup
        DB::table('spam_whitelist_ips')->where('comment', 'like', 'UK mobile:%')->delete();
        DB::table('spam_whitelist_ips')->where('comment', MobileNetworkService::CLOUDFLARE_COMMENT)->delete();
    }

    public function test_prunes_stale_cloudflare_rows_no_longer_in_upstream(): void
    {
        // Seed a stale Cloudflare row not in the current published list.
        DB::table('spam_whitelist_ips')->insertOrIgnore([
            'ip'      => '198.51.100.0/24',
            'comment' => MobileNetworkService::CLOUDFLARE_COMMENT,
            'date'    => now(),
        ]);

        $this->fakeBoth([], ['104.16.0.0/13']);

        $this->artisan('spam:refresh-mobile-cidrs')->assertExitCode(0);

        // Stale Cloudflare row must be pruned.
        $this->assertDatabaseMissing('spam_whitelist_ips', ['ip' => '198.51.100.0/24']);
        // Current Cloudflare row must be present.
        $this->assertDatabaseHas('spam_whitelist_ips', ['ip' => '104.16.0.0/13']);

        // Cleanup
        DB::table('spam_whitelist_ips')->where('comment', MobileNetworkService::CLOUDFLARE_COMMENT)->delete();
    }

    public function test_does_not_prune_manually_added_rows(): void
    {
        // A manually-added row with no recognised job marker must survive the refresh.
        DB::table('spam_whitelist_ips')->insertOrIgnore([
            'ip'      => '203.0.113.0/24',
            'comment' => 'Manually whitelisted — support ticket 12345',
            'date'    => now(),
        ]);

        $this->fakeBoth(['149.254.0.0/16'], ['104.16.0.0/13']);

        $this->artisan('spam:refresh-mobile-cidrs')->assertExitCode(0);

        // Manual row must still be there.
        $this->assertDatabaseHas('spam_whitelist_ips', ['ip' => '203.0.113.0/24']);

        // Cleanup
        DB::table('spam_whitelist_ips')->where('ip', '203.0.113.0/24')->delete();
        DB::table('spam_whitelist_ips')->where('comment', 'like', 'UK mobile:%')->delete();
        DB::table('spam_whitelist_ips')->where('comment', MobileNetworkService::CLOUDFLARE_COMMENT)->delete();
    }
}
