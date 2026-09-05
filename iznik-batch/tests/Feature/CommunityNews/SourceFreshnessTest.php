<?php

namespace Tests\Feature\CommunityNews;

use App\Services\CommunityNews\SourceFreshness;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SourceFreshnessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-28 10:00:00');
        config(['freegle.communitynews.source_max_age_days' => 365]);
        // The image check is exercised by its own tests; keep it out of the way
        // of the page-level ones.
        config(['freegle.communitynews.check_image_year' => false]);
        config(['freegle.communitynews.anthropic_api_key' => 'test-key']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function svc(): SourceFreshness
    {
        return new SourceFreshness(new FetchableLinkPreviewService());
    }

    private function page(string $ogType, ?string $published, string $extra = ''): string
    {
        $pub = $published ? "<meta property=\"article:published_time\" content=\"{$published}\" />" : '';

        return "<html><head><meta property=\"og:type\" content=\"{$ogType}\" />{$pub}{$extra}</head><body>Hello</body></html>";
    }

    // -------------------------------------------------------------------------
    // Can't judge: stay silent rather than guess

    public function test_missing_url_is_not_judged(): void
    {
        $this->assertNull($this->svc()->staleReason(null, '2026-08-31'));
        $this->assertNull($this->svc()->staleReason('', '2026-08-31'));
        $this->assertNull($this->svc()->staleReason('ftp://example.org/x', '2026-08-31'));
    }

    public function test_url_failing_the_ssrf_guard_is_not_fetched(): void
    {
        Http::fake();

        $this->assertNull($this->svc()->staleReason('http://127.0.0.1/local', '2026-08-31'));

        Http::assertNothingSent();
    }

    public function test_failed_fetch_is_not_judged(): void
    {
        Http::fake(['example.org/*' => Http::response('nope', 404)]);

        $this->assertNull($this->svc()->staleReason('https://example.org/gone', '2026-08-31'));
    }

    public function test_page_that_is_not_an_article_is_not_judged(): void
    {
        // An evergreen festival homepage: its published_time is the page's
        // creation date, not the content's, so it must never be trusted.
        // Measured against 200 real sources - this is what stops the check
        // rejecting the Loch Ness Marathon and York Beer Festival pages.
        Http::fake(['example.org/*' => Http::response($this->page('website', '2010-10-21T00:00:00Z'), 200)]);

        $this->assertNull($this->svc()->staleReason('https://example.org/festival', '2026-08-31'));
    }

    public function test_article_without_a_published_time_is_not_judged(): void
    {
        Http::fake(['example.org/*' => Http::response($this->page('article', null), 200)]);

        $this->assertNull($this->svc()->staleReason('https://example.org/story', '2026-08-31'));
    }

    // -------------------------------------------------------------------------
    // The rule

    public function test_recent_article_passes(): void
    {
        Http::fake(['example.org/*' => Http::response($this->page('article', '2026-07-01T09:00:00Z'), 200)]);

        $this->assertNull($this->svc()->staleReason('https://example.org/story', '2026-08-31'));
    }

    public function test_article_older_than_the_max_age_is_stale(): void
    {
        Http::fake(['example.org/*' => Http::response($this->page('article', '2014-08-14T14:57:00Z'), 200)]);

        $reason = $this->svc()->staleReason('https://example.org/riverfest', '2026-08-31');

        $this->assertNotNull($reason);
        $this->assertStringContainsString('2014-08-14', $reason);
    }

    public function test_og_prefixed_published_time_is_read(): void
    {
        // The shape Great British Life actually publishes, and the one that let
        // a 2014 RiverFest article be posted as this weekend's event.
        $html = '<html><head><meta property="og:type" content="article" />'
            . '<meta property="og:article:published_time" content="2014-08-14T14:57:00Z" />'
            . '</head><body>x</body></html>';
        Http::fake(['example.org/*' => Http::response($html, 200)]);

        $this->assertNotNull($this->svc()->staleReason('https://example.org/riverfest', '2026-08-31'));
    }

    public function test_max_age_is_configurable(): void
    {
        config(['freegle.communitynews.source_max_age_days' => 30]);
        Http::fake(['example.org/*' => Http::response($this->page('article', '2026-06-01T00:00:00Z'), 200)]);

        $this->assertNotNull($this->svc()->staleReason('https://example.org/story', '2026-08-31'));
    }

    public function test_date_only_published_time_is_read(): void
    {
        Http::fake(['example.org/*' => Http::response($this->page('article', '2014-08-14'), 200)]);

        $this->assertNotNull($this->svc()->staleReason('https://example.org/story', '2026-08-31'));
    }

    public function test_published_time_that_is_not_an_iso_date_is_ignored(): void
    {
        // Deliberately strict, like parseEventDate: Carbon::parse() reads "2014"
        // as today and "last Tuesday" as this week, so a loose parse would both
        // miss real staleness and risk inventing it. article:published_time is
        // ISO 8601 by specification; anything else we decline to judge.
        foreach (['last Tuesday', '2014', '14 August 2014', ''] as $value) {
            Http::fake(['example.org/*' => Http::response($this->page('article', $value), 200)]);

            $this->assertNull(
                $this->svc()->staleReason('https://example.org/story', '2026-08-31'),
                "published_time '{$value}' should not be judged"
            );
        }
    }

    // -------------------------------------------------------------------------
    // Redirects. Refusing to follow one costs us the page; following it off the
    // host would hand an attacker-supplied URL a second target. So: same host
    // only, re-checked against the SSRF guard.

    public function test_same_host_redirect_is_followed(): void
    {
        Http::fake([
            'example.org/story' => Http::response('', 301, ['Location' => 'https://example.org/story-canonical']),
            'example.org/story-canonical' => Http::response($this->page('article', '2014-08-14T14:57:00Z'), 200),
        ]);

        $this->assertNotNull($this->svc()->staleReason('https://example.org/story', '2026-08-31'));
    }

    public function test_relative_redirect_is_followed(): void
    {
        // wolverhamptonart.org.uk answers with a bare "/whats-on".
        Http::fake([
            'example.org/old' => Http::response('', 301, ['Location' => '/whats-on']),
            'example.org/whats-on' => Http::response($this->page('article', '2014-08-14T14:57:00Z'), 200),
        ]);

        $this->assertNotNull($this->svc()->staleReason('https://example.org/old', '2026-08-31'));
    }

    public function test_cross_host_redirect_is_not_followed(): void
    {
        Http::fake([
            'example.org/*' => Http::response('', 301, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
            '*' => Http::response($this->page('article', '2014-08-14T14:57:00Z'), 200),
        ]);

        $this->assertNull($this->svc()->staleReason('https://example.org/story', '2026-08-31'));

        Http::assertNotSent(fn ($r) => str_contains($r->url(), '169.254.169.254'));
    }

    public function test_a_redirect_loop_terminates(): void
    {
        Http::fake([
            'example.org/*' => Http::response('', 301, ['Location' => 'https://example.org/round']),
        ]);

        $this->assertNull($this->svc()->staleReason('https://example.org/round', '2026-08-31'));
    }

    // -------------------------------------------------------------------------
    // The poster image

    private function imagePage(): string
    {
        return $this->page('website', null, '<meta property="og:image" content="https://cdn.example.org/poster.jpg" />');
    }

    private function fakeWith(array $visionYears, int $status = 200): void
    {
        config(['freegle.communitynews.check_image_year' => true]);

        Http::fake([
            'cdn.example.org/*' => Http::response('JPEGBYTES', 200, ['Content-Type' => 'image/jpeg']),
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => json_encode(['years' => $visionYears])]],
            ], $status),
            'example.org/*' => Http::response($this->imagePage(), 200),
        ]);
    }

    public function test_poster_advertising_only_past_years_is_stale(): void
    {
        // The RiverFest og:image reads "23-25 AUGUST 2014" in giant type while
        // the item claims this weekend.
        $this->fakeWith([2014]);

        $reason = $this->svc()->staleReason('https://example.org/riverfest', '2026-08-31');

        $this->assertNotNull($reason);
        $this->assertStringContainsString('2014', $reason);
    }

    public function test_poster_showing_the_claimed_year_passes(): void
    {
        $this->fakeWith([2026]);

        $this->assertNull($this->svc()->staleReason('https://example.org/fair', '2026-08-31'));
    }

    public function test_poster_showing_a_later_year_passes(): void
    {
        $this->fakeWith([2027]);

        $this->assertNull($this->svc()->staleReason('https://example.org/fair', '2026-12-31'));
    }

    public function test_poster_with_no_years_passes(): void
    {
        $this->fakeWith([]);

        $this->assertNull($this->svc()->staleReason('https://example.org/fair', '2026-08-31'));
    }

    public function test_vision_failure_never_blocks_an_item(): void
    {
        $this->fakeWith([2014], 500);

        $this->assertNull($this->svc()->staleReason('https://example.org/fair', '2026-08-31'));
    }

    public function test_image_check_is_skipped_without_credentials(): void
    {
        config(['freegle.communitynews.anthropic_api_key' => '']);
        $this->fakeWith([2014]);

        $this->assertNull($this->svc()->staleReason('https://example.org/fair', '2026-08-31'));

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'api.anthropic.com'));
    }

    public function test_undated_item_is_judged_against_the_current_year(): void
    {
        $this->fakeWith([2014]);

        $this->assertNotNull($this->svc()->staleReason('https://example.org/fair', null));
    }
}
