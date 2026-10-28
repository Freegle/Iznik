<?php

namespace Tests\Feature\Spam;

use App\Services\MobileNetworkService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Exercises MobileNetworkService::refresh() end to end against the real
 * spam_whitelist_ips table, with RIPEstat and Cloudflare stubbed.
 */
class MobileNetworkServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Http::retry() sleeps between attempts; don't actually wait.
        Sleep::fake();
        // Start from a table with none of the job-owned rows (rolled back after each test).
        DB::table('spam_whitelist_ips')
            ->where(function ($q) {
                $q->where('comment', 'like', 'UK mobile:%')
                    ->orWhere('comment', MobileNetworkService::CLOUDFLARE_COMMENT);
            })->delete();
    }

    private function ripe(array $cidrs)
    {
        return Http::response([
            'data' => ['prefixes' => array_map(fn ($p) => ['prefix' => $p], $cidrs)],
        ], 200);
    }

    private function asnOf(Request $request): int
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);

        return (int) substr($q['resource'], 2);
    }

    private function mobileComment(int $asn): string
    {
        return "UK mobile: " . MobileNetworkService::UK_MOBILE_ASNS[$asn]
            . " (AS{$asn}) — CGNAT shared egress, exempt from IP-abuse (Discourse #9768)";
    }

    private function row(string $ip): ?object
    {
        return DB::table('spam_whitelist_ips')->where('ip', $ip)->first();
    }

    private function ownedCount(): int
    {
        return DB::table('spam_whitelist_ips')
            ->where(function ($q) {
                $q->where('comment', 'like', 'UK mobile:%')
                    ->orWhere('comment', MobileNetworkService::CLOUDFLARE_COMMENT);
            })->count();
    }

    public function test_happy_path_writes_every_asn_and_cloudflare(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'stat.ripe.net')) {
                $asn = $this->asnOf($request);
                // One distinct prefix per ASN.
                return $this->ripe(['10.' . ($asn % 200) . '.0.0/16']);
            }

            return Http::response("104.16.0.0/13\n104.24.0.0/14\n", 200);
        });

        $total = (new MobileNetworkService())->refresh();

        foreach (array_keys(MobileNetworkService::UK_MOBILE_ASNS) as $asn) {
            $row = $this->row('10.' . ($asn % 200) . '.0.0/16');
            $this->assertNotNull($row, "AS{$asn} prefix missing");
            $this->assertSame($this->mobileComment($asn), $row->comment);
        }

        foreach (['104.16.0.0/13', '104.24.0.0/14', MobileNetworkService::CGNAT_CIDR] as $ip) {
            $this->assertSame(MobileNetworkService::CLOUDFLARE_COMMENT, $this->row($ip)->comment);
        }

        // 4 mobile + 2 Cloudflare + CGNAT.
        $this->assertSame(7, $total);
        $this->assertSame($total, $this->ownedCount());
    }

    public function test_sends_asn_resource_to_ripestat(): void
    {
        Http::fake([
            'stat.ripe.net/*' => $this->ripe(['10.1.0.0/16']),
            'www.cloudflare.com/*' => Http::response('', 200),
        ]);

        (new MobileNetworkService())->refresh();

        foreach (array_keys(MobileNetworkService::UK_MOBILE_ASNS) as $asn) {
            Http::assertSent(fn (Request $r) => str_starts_with($r->url(), MobileNetworkService::RIPESTAT_URL)
                && $this->asnOf($r) === $asn);
        }
    }

    public static function ipv6AndBlankProvider(): array
    {
        return [
            'ipv6 only' => [['2a02:c7c::/29'], []],
            'ipv6 mixed with ipv4' => [['149.254.0.0/16', '2a02:c7c::/29'], ['149.254.0.0/16']],
            'blank strings skipped' => [['', '188.28.0.0/14'], ['188.28.0.0/14']],
            'ipv6 loopback' => [['::1/128', '188.28.0.0/14'], ['188.28.0.0/14']],
        ];
    }

    /**
     * @dataProvider ipv6AndBlankProvider
     */
    public function test_mobile_skips_ipv6_and_blank_prefixes(array $input, array $expected): void
    {
        Http::fake([
            'stat.ripe.net/*' => $this->ripe($input),
            'www.cloudflare.com/*' => Http::response('', 200),
        ]);

        $total = (new MobileNetworkService())->refresh();

        $written = DB::table('spam_whitelist_ips')->where('comment', 'like', 'UK mobile:%')->pluck('ip')->all();
        sort($expected);
        sort($written);
        $this->assertSame($expected, $written);
        // Same prefixes come back for each of the 4 ASNs; upsert dedupes by ip but the count is per row written.
        $this->assertSame(count($expected) * 4 + 1, $total);
        $this->assertNull(DB::table('spam_whitelist_ips')->where('ip', 'like', '%:%')->first());
    }

    public function test_mobile_ignores_malformed_prefix_entries(): void
    {
        Http::fake([
            'stat.ripe.net/*' => Http::response(['data' => ['prefixes' => [
                'not-an-array', ['nope' => 1], ['prefix' => null], ['prefix' => 123], ['prefix' => '188.28.0.0/14'],
            ]]], 200),
            'www.cloudflare.com/*' => Http::response('', 200),
        ]);

        (new MobileNetworkService())->refresh();

        $this->assertSame(
            ['188.28.0.0/14'],
            DB::table('spam_whitelist_ips')->where('comment', 'like', 'UK mobile:%')->pluck('ip')->all()
        );
    }

    public function test_cloudflare_skips_ipv6_and_blank_lines_and_trims(): void
    {
        Http::fake([
            'stat.ripe.net/*' => $this->ripe([]),
            'www.cloudflare.com/*' => Http::response("104.16.0.0/13\n\n  2400:cb00::/32  \n  172.64.0.0/13  \n\n", 200),
        ]);

        $total = (new MobileNetworkService())->refresh();

        $this->assertNotNull($this->row('104.16.0.0/13'));
        $this->assertNotNull($this->row('172.64.0.0/13'));
        $this->assertNull($this->row('2400:cb00::/32'));
        $this->assertSame(3, $total); // two ranges + CGNAT
    }

    public function test_ripestat_http_error_skips_only_that_asn(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'stat.ripe.net')) {
                return $this->asnOf($request) === 12576
                    ? Http::response('boom', 500)
                    : $this->ripe(['10.' . ($this->asnOf($request) % 200) . '.0.0/16']);
            }

            return Http::response('', 200);
        });
        Log::spy();

        $total = (new MobileNetworkService())->refresh();

        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains($m, 'AS12576'))->once();
        $this->assertNull($this->row('10.' . (12576 % 200) . '.0.0/16'));
        foreach ([206067, 25135, 35228] as $asn) {
            $this->assertNotNull($this->row('10.' . ($asn % 200) . '.0.0/16'));
        }
        $this->assertSame(3 + 1, $total); // 3 mobile + CGNAT
    }

    public function test_ripestat_all_asns_failing_logs_warning_per_asn_and_completes(): void
    {
        Http::fake([
            'stat.ripe.net/*' => Http::response('boom', 500),
            'www.cloudflare.com/*' => Http::response('104.16.0.0/13', 200),
        ]);
        Log::spy();

        $total = (new MobileNetworkService())->refresh();

        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains($m, 'MobileNetworkService: RIPEstat'))->times(4);
        $this->assertSame(2, $total); // Cloudflare range + CGNAT only
    }

    public function test_ripestat_connection_exception_is_skipped(): void
    {
        Http::fake([
            'stat.ripe.net/*' => fn () => throw new ConnectionException('timed out'),
            'www.cloudflare.com/*' => Http::response('104.16.0.0/13', 200),
        ]);
        Log::spy();

        $total = (new MobileNetworkService())->refresh();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($m) => str_contains($m, 'RIPEstat fetch failed') && str_contains($m, 'timed out'))
            ->times(4);
        $this->assertSame(2, $total);
        $this->assertNotNull($this->row('104.16.0.0/13'));
    }

    public static function emptyPrefixesProvider(): array
    {
        return [
            'prefixes empty array' => [['data' => ['prefixes' => []]]],
            'prefixes null' => [['data' => ['prefixes' => null]]],
            'prefixes missing' => [['data' => []]],
            'no data key' => [[]],
        ];
    }

    /**
     * @dataProvider emptyPrefixesProvider
     */
    public function test_empty_prefixes_write_no_mobile_rows(array $body): void
    {
        Http::fake([
            'stat.ripe.net/*' => Http::response($body, 200),
            'www.cloudflare.com/*' => Http::response('104.16.0.0/13', 200),
        ]);
        Log::spy();

        $total = (new MobileNetworkService())->refresh();

        Log::shouldNotHaveReceived('warning');
        $this->assertSame(0, DB::table('spam_whitelist_ips')->where('comment', 'like', 'UK mobile:%')->count());
        $this->assertSame(2, $total);
    }

    public function test_cloudflare_http_error_returns_zero_and_does_not_write_cgnat(): void
    {
        Http::fake([
            'stat.ripe.net/*' => $this->ripe(['188.28.0.0/14']),
            'www.cloudflare.com/*' => Http::response('nope', 503),
        ]);
        Log::spy();
        // A stale Cloudflare row must survive: the branch returns before pruning.
        DB::table('spam_whitelist_ips')->insert([
            'ip' => '104.99.0.0/16', 'comment' => MobileNetworkService::CLOUDFLARE_COMMENT, 'date' => now(),
        ]);

        $total = (new MobileNetworkService())->refresh();

        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains($m, 'Cloudflare'))->once();
        $this->assertSame(4, $total); // 4 ASNs each returning the same single prefix
        $this->assertNull($this->row(MobileNetworkService::CGNAT_CIDR));
        $this->assertNotNull($this->row('104.99.0.0/16'));
    }

    public function test_cloudflare_connection_exception_returns_zero(): void
    {
        Http::fake([
            'stat.ripe.net/*' => $this->ripe([]),
            'www.cloudflare.com/*' => fn () => throw new ConnectionException('dns failure'),
        ]);
        Log::spy();

        $total = (new MobileNetworkService())->refresh();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($m) => str_contains($m, 'Cloudflare ips-v4 fetch failed') && str_contains($m, 'dns failure'))
            ->once();
        $this->assertSame(0, $total);
        $this->assertNull($this->row(MobileNetworkService::CGNAT_CIDR));
    }

    public function test_prunes_stale_mobile_rows_but_never_manual_rows(): void
    {
        DB::table('spam_whitelist_ips')->insert([
            ['ip' => '10.200.0.0/16', 'comment' => 'UK mobile: Old (AS1) — stale', 'date' => now()],
            ['ip' => '10.201.0.0/16', 'comment' => 'Manually added office', 'date' => now()],
            ['ip' => '10.202.0.0/16', 'comment' => null, 'date' => now()],
            ['ip' => '10.203.0.0/16', 'comment' => 'Mentions UK mobile: but not a prefix', 'date' => now()],
        ]);
        Http::fake([
            'stat.ripe.net/*' => $this->ripe(['188.28.0.0/14']),
            'www.cloudflare.com/*' => Http::response('', 200),
        ]);

        (new MobileNetworkService())->refresh();

        $this->assertNull($this->row('10.200.0.0/16'));
        $this->assertNotNull($this->row('188.28.0.0/14'));
        foreach (['10.201.0.0/16', '10.202.0.0/16', '10.203.0.0/16'] as $ip) {
            $this->assertNotNull($this->row($ip), "$ip should be untouched");
        }
    }

    public function test_refresh_updates_existing_row_comment_and_keeps_it(): void
    {
        DB::table('spam_whitelist_ips')->insert([
            'ip' => '188.28.0.0/14', 'comment' => 'UK mobile: stale name', 'date' => '2020-01-01 00:00:00',
        ]);
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'stat.ripe.net')) {
                return $this->asnOf($request) === 12576 ? $this->ripe(['188.28.0.0/14']) : $this->ripe([]);
            }

            return Http::response('', 200);
        });

        (new MobileNetworkService())->refresh();

        $row = $this->row('188.28.0.0/14');
        $this->assertSame($this->mobileComment(12576), $row->comment);
        $this->assertGreaterThan('2020-01-01 00:00:00', $row->date);
        $this->assertSame(1, DB::table('spam_whitelist_ips')->where('ip', '188.28.0.0/14')->count());
    }

    public function test_prunes_stale_cloudflare_rows_and_keeps_cgnat(): void
    {
        DB::table('spam_whitelist_ips')->insert([
            ['ip' => '104.99.0.0/16', 'comment' => MobileNetworkService::CLOUDFLARE_COMMENT, 'date' => now()],
            ['ip' => '10.201.0.0/16', 'comment' => 'Manually added office', 'date' => now()],
            ['ip' => '10.202.0.0/16', 'comment' => 'UK mobile: Old (AS1) — stale', 'date' => now()],
        ]);
        Http::fake([
            'stat.ripe.net/*' => $this->ripe([]),
            'www.cloudflare.com/*' => Http::response("104.16.0.0/13\n", 200),
        ]);

        (new MobileNetworkService())->refresh();

        $this->assertNull($this->row('104.99.0.0/16'));
        $this->assertNotNull($this->row('104.16.0.0/13'));
        $this->assertSame(MobileNetworkService::CLOUDFLARE_COMMENT, $this->row(MobileNetworkService::CGNAT_CIDR)->comment);
        $this->assertNotNull($this->row('10.201.0.0/16'));
        // Cloudflare pruning must not touch mobile rows, and vice versa (empty mobile fetch = no mobile prune).
        $this->assertNotNull($this->row('10.202.0.0/16'));
    }

    public function test_cgnat_is_reclaimed_by_cloudflare_marker_when_preseeded_manually(): void
    {
        DB::table('spam_whitelist_ips')->insert([
            'ip' => MobileNetworkService::CGNAT_CIDR, 'comment' => 'old cgnat note', 'date' => now(),
        ]);
        Http::fake([
            'stat.ripe.net/*' => $this->ripe([]),
            'www.cloudflare.com/*' => Http::response('', 200),
        ]);

        $total = (new MobileNetworkService())->refresh();

        $this->assertSame(1, $total);
        $this->assertSame(MobileNetworkService::CLOUDFLARE_COMMENT, $this->row(MobileNetworkService::CGNAT_CIDR)->comment);
    }

    public function test_mobile_prune_skipped_when_nothing_fetched(): void
    {
        DB::table('spam_whitelist_ips')->insert([
            'ip' => '10.200.0.0/16', 'comment' => 'UK mobile: Old (AS1) — keep me', 'date' => now(),
        ]);
        Http::fake([
            'stat.ripe.net/*' => Http::response('boom', 500),
            'www.cloudflare.com/*' => Http::response('', 200),
        ]);

        (new MobileNetworkService())->refresh();

        $this->assertNotNull($this->row('10.200.0.0/16'));
    }

    public function test_mobile_prune_skipped_when_only_ipv6_fetched(): void
    {
        DB::table('spam_whitelist_ips')->insert([
            'ip' => '10.200.0.0/16', 'comment' => 'UK mobile: Old (AS1) — keep me', 'date' => now(),
        ]);
        Http::fake([
            'stat.ripe.net/*' => $this->ripe(['2a02:c7c::/29']),
            'www.cloudflare.com/*' => Http::response('', 200),
        ]);

        (new MobileNetworkService())->refresh();

        $this->assertNotNull($this->row('10.200.0.0/16'));
    }

    public function test_partial_asn_failure_still_prunes_against_fetched_set(): void
    {
        DB::table('spam_whitelist_ips')->insert([
            'ip' => '10.200.0.0/16', 'comment' => 'UK mobile: Old (AS1) — stale', 'date' => now(),
        ]);
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'stat.ripe.net')) {
                return $this->asnOf($request) === 25135
                    ? $this->ripe(['188.28.0.0/14'])
                    : Http::response('boom', 500);
            }

            return Http::response('', 200);
        });

        (new MobileNetworkService())->refresh();

        $this->assertNull($this->row('10.200.0.0/16'));
        $this->assertNotNull($this->row('188.28.0.0/14'));
    }

    public function test_refresh_is_idempotent(): void
    {
        Http::fake([
            'stat.ripe.net/*' => $this->ripe(['188.28.0.0/14']),
            'www.cloudflare.com/*' => Http::response("104.16.0.0/13\n", 200),
        ]);

        $service = new MobileNetworkService();
        $service->refresh();
        $first = $this->ownedCount();
        $service->refresh();

        $this->assertSame($first, $this->ownedCount());
        $this->assertSame(3, $first);
    }
}
