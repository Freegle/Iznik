<?php

namespace Tests\Feature\Hosting;

use App\Models\DomainHosting;
use App\Services\Hosting\DnsResolver;
use App\Services\Hosting\DomainHostingService;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The scanner behind the Krystal hosting ad: which domains it looks at, what a look finds, and what it
 * writes. The network is faked: one Http::fake closure keyed on the host (a second fake would be ignored,
 * the first stub wins) and a DnsResolver bound in the container.
 */
class DomainHostingServiceTest extends TestCase
{
    private const NOW = '2026-10-30 12:00:00';

    private array $nameservers = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::NOW);
        $this->nameservers = [];
        $test = $this;
        $this->app->instance(DnsResolver::class, new class($test) extends DnsResolver
        {
            public function __construct(private DomainHostingServiceTest $test) {}

            public function nameservers(string $domain): array
            {
                return $this->test->nameserversFor($domain);
            }
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return list<string> */
    public function nameserversFor(string $domain): array
    {
        return $this->nameservers[$domain] ?? [];
    }

    private function service(): DomainHostingService
    {
        return $this->app->make(DomainHostingService::class);
    }

    private function unique(string $label): string
    {
        return strtolower($label.'-'.uniqid().'.example');
    }

    private function member(string $domain, ?string $lastaccess = self::NOW): void
    {
        $this->createTestUser([
            'email_preferred' => 'm'.uniqid().'@'.$domain,
            'lastaccess' => $lastaccess,
        ]);
    }

    public function test_eligible_domain_keeps_a_hostname_and_drops_providers_public_sector_and_junk(): void
    {
        $this->assertTrue(DomainHostingService::eligibleDomain('alligatorbooks.co.uk'));
        $this->assertTrue(DomainHostingService::eligibleDomain('Some-Site.ORG'));
        $this->assertFalse(DomainHostingService::eligibleDomain('gmail.com'));
        $this->assertFalse(DomainHostingService::eligibleDomain('user.trashnothing.com'));
        $this->assertFalse(DomainHostingService::eligibleDomain('someone.ac.uk'));
        $this->assertFalse(DomainHostingService::eligibleDomain('council.gov.uk'));
        $this->assertFalse(DomainHostingService::eligibleDomain('localhost'));
        $this->assertFalse(DomainHostingService::eligibleDomain(''));
        $this->assertFalse(DomainHostingService::eligibleDomain('bad domain.com'));
        $this->assertFalse(DomainHostingService::eligibleDomain('-leading.com'));
        $this->assertFalse(DomainHostingService::eligibleDomain(str_repeat('a', 190).'.com'));
    }

    public function test_candidates_are_the_rare_domains_of_active_members(): void
    {
        $own = $this->unique('own');
        $inactive = $this->unique('inactive');
        $busy = $this->unique('busy');
        $this->member($own);
        $this->member($inactive, '2026-01-01 00:00:00');
        for ($i = 0; $i <= DomainHostingService::MAX_USERS; $i++) {
            $this->member($busy);
        }

        $candidates = $this->service()->candidates(10000);

        $this->assertContains($own, $candidates);
        $this->assertNotContains($inactive, $candidates, 'a member last seen long ago does not count');
        $this->assertNotContains($busy, $candidates, 'a domain shared by many members is a provider, not a site');
        $this->assertNotContains('test.com', $candidates, 'the suite\'s own domain has too many members');
    }

    public function test_candidates_skip_a_recent_check_and_put_new_domains_before_stale_ones(): void
    {
        $fresh = $this->unique('aaa-fresh');
        $stale = $this->unique('aaa-stale');
        $recent = $this->unique('aaa-recent');
        foreach ([$fresh, $stale, $recent] as $d) {
            $this->member($d);
        }
        DomainHosting::create(['domain' => $stale, 'checked_at' => Carbon::parse(self::NOW)->subDays(DomainHostingService::RECHECK_DAYS + 1)]);
        DomainHosting::create(['domain' => $recent, 'checked_at' => Carbon::parse(self::NOW)->subDays(DomainHostingService::RECHECK_DAYS - 1)]);

        $candidates = $this->service()->candidates(10000);

        $this->assertContains($fresh, $candidates);
        $this->assertContains($stale, $candidates);
        $this->assertNotContains($recent, $candidates);
        $this->assertLessThan(array_search($stale, $candidates), array_search($fresh, $candidates), 'never checked comes first');
        $this->assertSame([], $this->service()->candidates(0));
        $this->assertCount(1, $this->service()->candidates(1));
    }

    private function fakeWeb(): void
    {
        Http::fake(function (Request $request) {
            $host = parse_url($request->url(), PHP_URL_HOST);
            $scheme = parse_url($request->url(), PHP_URL_SCHEME);

            return match (true) {
                str_starts_with($host, 'wp.') => Http::response('<html><link rel="stylesheet" href="https://'.$host.'/wp-content/themes/x/style.css"></html>', 200, ['Server' => 'nginx']),
                str_starts_with($host, 'gen.') => Http::response('<html><meta name="generator" content="WordPress 6.9" /></html>', 200),
                str_starts_with($host, 'krystalhdr.') => Http::response('<html>/wp-includes/js/x.js</html>', 200, ['Server' => 'LiteSpeed', 'X-Powered-By' => 'Krystal Hosting']),
                str_starts_with($host, 'plain.') => Http::response('<html><body>Hello</body></html>', 200, ['Server' => 'Apache']),
                str_starts_with($host, 'httponly.') && $scheme === 'https' => throw new ConnectionException('refused'),
                str_starts_with($host, 'httponly.') => Http::response('<html>/wp-json/</html>', 200),
                str_starts_with($host, 'down.') => throw new ConnectionException('timeout'),
                str_starts_with($host, 'gone.') => Http::response('', 404, ['Server' => 'cloudflare']),
                default => Http::response('', 500),
            };
        });
    }

    public function test_probe_reads_the_fingerprint_the_host_and_the_status(): void
    {
        $this->fakeWeb();
        $this->nameservers['wp.site.example'] = ['ns1.domaincontrol.com', 'ns2.domaincontrol.com'];
        $this->nameservers['krystalns.site.example'] = ['ns1.krystal.uk', 'ns2.krystal.uk'];

        $wp = $this->service()->probe('wp.site.example');
        $this->assertSame(['wordpress' => true, 'krystal' => false, 'nameserver' => 'ns1.domaincontrol.com', 'server' => 'nginx', 'status' => 200], $wp);

        $gen = $this->service()->probe('gen.site.example');
        $this->assertTrue($gen['wordpress'], 'the generator tag is a fingerprint');
        $this->assertNull($gen['nameserver']);
        $this->assertNull($gen['server']);

        $hdr = $this->service()->probe('krystalhdr.site.example');
        $this->assertTrue($hdr['wordpress']);
        $this->assertTrue($hdr['krystal'], 'a response header naming Krystal means it is already there');

        $ns = $this->service()->probe('krystalns.site.example');
        $this->assertTrue($ns['krystal'], 'a Krystal nameserver means it is already there');
        $this->assertFalse($ns['wordpress']);

        $plain = $this->service()->probe('plain.site.example');
        $this->assertSame(['wordpress' => false, 'krystal' => false, 'nameserver' => null, 'server' => 'Apache', 'status' => 200], $plain);

        $http = $this->service()->probe('httponly.site.example');
        $this->assertTrue($http['wordpress'], 'http is tried when https refuses');
        $this->assertSame(200, $http['status']);

        $down = $this->service()->probe('down.site.example');
        $this->assertSame(['wordpress' => false, 'krystal' => false, 'nameserver' => null, 'server' => null, 'status' => null], $down);

        $gone = $this->service()->probe('gone.site.example');
        $this->assertSame(404, $gone['status']);
        $this->assertFalse($gone['wordpress']);

        Http::assertSent(fn (Request $r) => $r->hasHeader('User-Agent', DomainHostingService::USER_AGENT));
    }

    public function test_looks_like_wordpress_matches_each_fingerprint_and_nothing_else(): void
    {
        $this->assertTrue(DomainHostingService::looksLikeWordPress('src="/wp-content/x.js"'));
        $this->assertTrue(DomainHostingService::looksLikeWordPress('src="/wp-includes/x.js"'));
        $this->assertTrue(DomainHostingService::looksLikeWordPress('<link rel="https://api.w.org/" href="https://x/wp-json/">'));
        $this->assertTrue(DomainHostingService::looksLikeWordPress('<meta name="generator" content="WordPress 6.9">'));
        $this->assertFalse(DomainHostingService::looksLikeWordPress('<meta name="generator" content="Squarespace">'));
        $this->assertFalse(DomainHostingService::looksLikeWordPress('wordpress is mentioned in prose'));
    }

    public function test_record_creates_then_replaces_the_row(): void
    {
        $domain = $this->unique('rec');
        $first = $this->service()->record($domain, ['wordpress' => true, 'krystal' => false, 'nameserver' => 'ns1.x', 'server' => 'nginx', 'status' => 200]);
        $this->assertTrue($first->wordpress);
        $this->assertSame(self::NOW, $first->checked_at->format('Y-m-d H:i:s'));

        $second = $this->service()->record(strtoupper($domain), ['wordpress' => false, 'krystal' => true, 'nameserver' => 'ns1.krystal.uk', 'server' => null, 'status' => 301], Carbon::parse('2026-11-01 00:00:00'));
        $this->assertSame($first->id, $second->id, 'one row per domain, whatever the case');
        $this->assertSame(1, DomainHosting::where('domain', $domain)->count());
        $row = DomainHosting::find($first->id);
        $this->assertFalse($row->wordpress);
        $this->assertTrue($row->krystal);
        $this->assertSame(301, $row->status);
        $this->assertSame('2026-11-01 00:00:00', $row->checked_at->format('Y-m-d H:i:s'));
    }

    public function test_scan_looks_at_the_candidates_writes_each_and_counts(): void
    {
        $this->fakeWeb();
        $wp = 'wp.'.$this->unique('scan');
        $down = 'down.'.$this->unique('scan');
        $krystal = 'krystalhdr.'.$this->unique('scan');
        foreach ([$wp, $down, $krystal] as $d) {
            $this->member($d);
        }

        $counts = $this->service()->scan(10000);

        $this->assertGreaterThanOrEqual(3, $counts['looked']);
        $this->assertGreaterThanOrEqual(2, $counts['wordpress']);
        $this->assertGreaterThanOrEqual(1, $counts['krystal']);
        $this->assertGreaterThanOrEqual(1, $counts['unreachable']);
        $this->assertTrue(DomainHosting::where('domain', $wp)->first()->wordpress);
        $this->assertNull(DomainHosting::where('domain', $down)->first()->status);
        $this->assertTrue(DomainHosting::where('domain', $krystal)->first()->krystal);

        $this->assertSame(0, $this->service()->scan(10000)['looked'], 'a domain just looked at is not looked at again');
    }
}
