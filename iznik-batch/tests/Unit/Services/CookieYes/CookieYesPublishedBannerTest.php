<?php

namespace Tests\Unit\Services\CookieYes;

use App\Services\CookieYes\CookieYesException;
use App\Services\CookieYes\CookieYesPublishedBanner;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Driven by the real banner script CookieYes served for ilovefreegle.org on
 * 27 Sep 2026 (tests/fixtures/cookieyes/script.js), after the AI Cookie
 * Classifier had placed every cookie the scan of that day found.
 */
class CookieYesPublishedBannerTest extends TestCase
{
    private const URL = 'https://cdn-cookieyes.com/client_data/fd4582b38fa7a9f269114304/script.js';

    private string $script;

    protected function setUp(): void
    {
        parent::setUp();
        $this->script = file_get_contents(base_path('tests/fixtures/cookieyes/script.js'));
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

    public function test_the_recorded_script_publishes_41_cookies_with_none_uncategorised(): void
    {
        $categories = (new CookieYesPublishedBanner())->parse($this->script);

        $this->assertSame(['necessary', 'functional', 'analytics', 'performance', 'advertisement', 'other'], array_keys($categories));
        $this->assertSame(
            ['necessary' => 8, 'functional' => 3, 'analytics' => 0, 'performance' => 0, 'advertisement' => 30, 'other' => 0],
            array_map('count', $categories)
        );
        $this->assertSame(41, array_sum(array_map('count', $categories)));
        $this->assertSame(['euconsent', 'm', '__stripe_mid', '__stripe_sid', '__cf_bm', 'loglevel', 'kas', 'cookieyes-*'], $categories['necessary']);
        $this->assertSame('cto_pub_test_tld', end($categories['advertisement']));
    }

    public function test_uncategorised_cookies_are_named(): void
    {
        $categories = (new CookieYesPublishedBanner())->parse($this->withUncategorised('_mystery', 'odd[1]'));

        $this->assertSame(['_mystery', 'odd[1]'], $categories['other']);
        $this->assertSame(43, array_sum(array_map('count', $categories)));
    }

    public function test_a_script_without_a_category_list_is_refused(): void
    {
        $this->expectException(CookieYesException::class);
        $this->expectExceptionMessage('no _categories list');

        (new CookieYesPublishedBanner())->parse('!function(){var e={};}();');
    }

    public function test_a_truncated_category_list_is_refused(): void
    {
        $this->expectException(CookieYesException::class);

        (new CookieYesPublishedBanner())->parse(substr($this->script, 0, strpos($this->script, 'slug:"advertisement"')));
    }

    public function test_the_script_is_fetched_from_the_cdn(): void
    {
        Http::fake([self::URL => Http::response($this->script)]);

        $categories = (new CookieYesPublishedBanner())->categories(self::URL);

        $this->assertSame(0, count($categories['other']));
        Http::assertSentCount(1);
    }

    public function test_a_cdn_error_is_reported_not_treated_as_categorised(): void
    {
        Http::fake([self::URL => Http::response('We can\'t find the page you are looking for.', 403)]);

        try {
            (new CookieYesPublishedBanner())->categories(self::URL);
            $this->fail('A 403 must not pass as a readable banner');
        } catch (CookieYesException $e) {
            $this->assertStringContainsString('HTTP 403', $e->getMessage());
            $this->assertStringContainsString(self::URL, $e->getMessage());
        }
    }

    public function test_an_unreachable_cdn_is_reported(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host'));

        $this->expectException(CookieYesException::class);
        $this->expectExceptionMessage('Could not resolve host');

        (new CookieYesPublishedBanner())->categories(self::URL);
    }
}
