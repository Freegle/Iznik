<?php

namespace Tests\Unit\Services\CookieYes;

use App\Services\CookieYes\CookieYesAuthException;
use App\Services\CookieYes\CookieYesException;
use App\Services\CookieYes\CookieYesMcpClient;
use App\Services\CookieYes\CookieYesOAuth;
use App\Services\CookieYes\CookieYesPublishedBanner;
use App\Services\CookieYes\CookieYesTokenStore;
use App\Services\CookieYes\CookieYesWatchdogService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Driven by real answers recorded from Freegle's CookieYes account on
 * 27 Sep 2026 (tests/fixtures/cookieyes), with the one field each test is
 * about changed. The recorded scan lists 6 uncategorised cookies; the recorded
 * banner script, published after the classifier ran, has none.
 */
class CookieYesWatchdogServiceTest extends TestCase
{
    private const SCRIPT_URL = 'https://cdn-cookieyes.com/client_data/fd4582b38fa7a9f269114304/script.js';

    /** Tool name => answer array, Throwable, or callable(args): array. */
    private array $answers = [];

    /** @var list<array{0:string,1:array}> */
    private array $calls = [];

    private string $script;

    /** What the fake CDN serves for the banner script; changed per test through publishes(). */
    private string $served;

    private int $servedStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'freegle.cookieyes.rescan_after_days' => 30,
            'freegle.cookieyes.stale_after_days' => 45,
        ]);

        foreach (['list_domains', 'get_website_details', 'get_banner_status', 'get_scan_results', 'get_compliance_status', 'get_embed_code'] as $tool) {
            $this->answers[$tool] = json_decode(file_get_contents(base_path("tests/fixtures/cookieyes/{$tool}.json")), true);
        }
        $this->answers['trigger_cookie_scan'] = ['scan_id' => 1, 'scan_type' => 'normal'];

        $this->script = file_get_contents(base_path('tests/fixtures/cookieyes/script.js'));
        $this->served = $this->script;
        // One fake, resolved at request time, so a test can change what is served.
        Http::fake([self::SCRIPT_URL => fn () => Http::response($this->served, $this->servedStatus)]);
        Http::preventStrayRequests();

        // The recorded scan ran on 20 Sep; checking a week later.
        $this->travelTo(Carbon::parse('2026-09-27 10:30:00'));
    }

    public function answer(string $name, array $args): array
    {
        $this->calls[] = [$name, $args];
        $answer = $this->answers[$name];

        if ($answer instanceof \Throwable) {
            throw $answer;
        }

        return is_callable($answer) ? $answer($args) : $answer;
    }

    private function watchdog(): CookieYesWatchdogService
    {
        $mcp = new class (new CookieYesOAuth(new CookieYesTokenStore()), $this) extends CookieYesMcpClient {
            public function __construct(CookieYesOAuth $oauth, private readonly CookieYesWatchdogServiceTest $test)
            {
                parent::__construct($oauth);
            }

            public function callTool(string $name, array $arguments = []): array
            {
                return $this->test->answer($name, $arguments);
            }
        };

        return new CookieYesWatchdogService($mcp, new CookieYesPublishedBanner());
    }

    private function publishes(string $script, int $status = 200): void
    {
        $this->served = $script;
        $this->servedStatus = $status;
    }

    private function withUncategorised(string ...$cookies): string
    {
        $literal = implode(',', array_map(fn ($c) => '{cookieID:"' . $c . '",domain:".ilovefreegle.org"}', $cookies));

        return str_replace(
            '{slug:"other",isNecessary:!1,defaultConsent:{gdpr:!1,ccpa:!1},cookies:[]}',
            '{slug:"other",isNecessary:!1,defaultConsent:{gdpr:!1,ccpa:!1},cookies:[' . $literal . ']}',
            $this->script
        );
    }

    private function triggered(): array
    {
        return array_values(array_filter($this->calls, fn ($call) => $call[0] === 'trigger_cookie_scan'));
    }

    public function test_the_recorded_account_passes_on_what_it_publishes_not_on_what_the_scan_found(): void
    {
        $result = $this->watchdog()->run();

        $this->assertTrue($result->ok, $result->summary);
        $this->assertSame('ilovefreegle.org: banner live, all 41 cookies categorised, scanned 7 days ago', $result->summary);
        $log = implode("\n", $result->log);
        $this->assertStringContainsString('"Uncategorized":6', $log, 'The scan is still logged for the record');
        $this->assertStringContainsString('banner publishes 41 cookies: {"necessary":8,"functional":3,"analytics":0,"performance":0,"advertisement":30,"other":0}', $log);
        $this->assertSame([], $this->triggered(), 'A week-old scan is fresh enough');
        Http::assertSent(fn ($request) => $request->url() === self::SCRIPT_URL);
    }

    public function test_uncategorised_cookies_in_the_published_banner_fail_and_are_named(): void
    {
        $this->publishes($this->withUncategorised('_mystery', 'odd'));

        $result = $this->watchdog()->run();

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('ilovefreegle.org: 2 uncategorised cookies published in the banner', $result->summary);
        $this->assertStringContainsString('categorise in Cookie Manager', $result->summary);
        $this->assertStringContainsString('ilovefreegle.org: uncategorised: _mystery, odd', implode("\n", $result->log));
    }

    public function test_one_uncategorised_cookie_is_singular(): void
    {
        $this->publishes($this->withUncategorised('_mystery'));

        $this->assertStringContainsString('1 uncategorised cookie published', $this->watchdog()->run()->summary);
    }

    public function test_a_banner_publishing_no_cookies_at_all_fails(): void
    {
        $this->publishes(preg_replace('/cookies:\[[^\]]*\]/', 'cookies:[]', $this->script));

        $result = $this->watchdog()->run();

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('banner publishes no cookies at all', $result->summary);
    }

    public function test_an_unreadable_banner_script_fails_rather_than_passing(): void
    {
        $this->publishes('We can\'t find the page you are looking for.', 403);

        $result = $this->watchdog()->run();

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('HTTP 403', $result->summary);
    }

    public function test_an_embed_code_without_a_script_address_fails(): void
    {
        $this->answers['get_embed_code'] = ['embed_code' => '<!-- Start cookieyes banner --><!-- End cookieyes banner -->'];

        $result = $this->watchdog()->run();

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('embed code has no banner script address', $result->summary);
        Http::assertNothingSent();
    }

    public function test_every_way_the_banner_can_be_off_fails(): void
    {
        $cases = [
            'is_active' => [false, 'banner not live'],
            'banner_status' => [0, 'banner not live'],
            'is_banner_manually_disabled' => [true, 'banner switched off in CookieYes'],
            'is_banner_disabled_by_pageview' => [true, 'banner disabled by the plan\'s pageview limit'],
        ];

        $recorded = $this->answers['get_banner_status'];

        foreach ($cases as $field => [$value, $reason]) {
            $this->answers['get_banner_status'] = array_merge($recorded, [$field => $value]);

            $result = $this->watchdog()->run();

            $this->assertFalse($result->ok, $field);
            $this->assertStringContainsString($reason, $result->summary, $field);
        }
    }

    public function test_gdpr_switched_off_fails(): void
    {
        $this->answers['get_compliance_status']['applicable_laws'] = ['ccpa'];

        $result = $this->watchdog()->run();

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('GDPR not enabled', $result->summary);
    }

    public function test_an_old_scan_starts_a_new_one_at_the_plan_scan_limit(): void
    {
        $this->travelTo(Carbon::parse('2026-10-25 10:30:00'));

        $result = $this->watchdog()->run();

        $this->assertTrue($result->ok, 'A 35-day-old scan is due a rescan but not yet stale');
        $this->assertSame(
            [['trigger_cookie_scan', ['websiteId' => '440076', 'scanType' => 'normal', 'pageLimit' => 8000]]],
            $this->triggered()
        );
        $this->assertStringContainsString('Started a new scan', implode("\n", $result->log));
    }

    public function test_a_stale_scan_fails(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 10:30:00'));

        $result = $this->watchdog()->run();

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('last scanned 51 days ago', $result->summary);
        $this->assertCount(1, $this->triggered(), 'It still tries to start a scan');
    }

    public function test_a_refused_scan_is_logged_and_left_to_the_staleness_check(): void
    {
        $this->travelTo(Carbon::parse('2026-10-25 10:30:00'));
        $this->answers['trigger_cookie_scan'] = new CookieYesException('CookieYes tool trigger_cookie_scan failed: A scan is already in progress');

        $result = $this->watchdog()->run();

        $this->assertTrue($result->ok);
        $this->assertStringContainsString('A scan is already in progress', implode("\n", $result->log));
    }

    public function test_a_lost_login_fails_with_what_to_do(): void
    {
        $this->answers['list_domains'] = new CookieYesAuthException('The CookieYes login has expired or been revoked (invalid_grant). Run php artisan cookieyes:authorize to log in again.');

        $result = $this->watchdog()->run();

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('cookieyes:authorize', $result->summary);
    }

    public function test_an_unreachable_server_fails(): void
    {
        $this->answers['get_scan_results'] = new CookieYesException('CookieYes MCP server returned HTTP 503 for tools/call: down');

        $result = $this->watchdog()->run();

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('HTTP 503', $result->summary);
    }

    public function test_an_inactive_website_fails(): void
    {
        $this->answers['list_domains'][0]['websites'][0]['status'] = 'inactive';

        $result = $this->watchdog()->run();

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('ilovefreegle.org: website is inactive in CookieYes', $result->summary);
    }

    public function test_an_account_with_no_websites_fails(): void
    {
        $this->answers['list_domains'][0]['websites'] = [];

        $result = $this->watchdog()->run();

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('no websites', $result->summary);
    }

    public function test_every_website_is_checked_and_only_the_failing_one_is_named(): void
    {
        $second = array_merge($this->answers['list_domains'][0]['websites'][0], ['id' => 555, 'url' => 'modtools.org']);
        $this->answers['list_domains'][0]['websites'][] = $second;

        $banner = $this->answers['get_banner_status'];
        $this->answers['get_banner_status'] = fn (array $args) => $args['websiteId'] === '555'
            ? array_merge($banner, ['is_active' => false])
            : $banner;

        $result = $this->watchdog()->run();

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('modtools.org: banner not live', $result->summary);
        $this->assertStringNotContainsString('ilovefreegle.org', $result->summary);
    }
}
