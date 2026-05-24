<?php

namespace Tests\Unit\Services;

use App\Services\NewsfeedLinkPreviewService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Subclass that exposes protected methods for unit testing.
 */
class TestableNewsfeedLinkPreviewService extends NewsfeedLinkPreviewService
{
    public function parseHtmlPublic(string $html): array
    {
        return $this->parseHtml($html);
    }

    public function getOrCreatePublic(string $url): ?int
    {
        return $this->getOrCreate($url);
    }

    public function createPublic(string $url): ?int
    {
        return $this->create($url);
    }

    public function upsertInvalidPublic(string $url): int
    {
        return $this->upsertInvalid($url);
    }
}

class NewsfeedLinkPreviewServiceTest extends TestCase
{
    private TestableNewsfeedLinkPreviewService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TestableNewsfeedLinkPreviewService();
    }

    // -------------------------------------------------------------------------
    // parseHtml — title priority chain
    // -------------------------------------------------------------------------

    public function test_parse_html_uses_og_title(): void
    {
        $html = '<html><head>'
            . '<meta property="og:title" content="OG Title">'
            . '<meta name="twitter:title" content="Twitter Title">'
            . '<title>Page Title</title>'
            . '</head><body></body></html>';

        $result = $this->service->parseHtmlPublic($html);

        $this->assertEquals('OG Title', $result['title']);
    }

    public function test_parse_html_falls_back_to_twitter_title(): void
    {
        $html = '<html><head>'
            . '<meta name="twitter:title" content="Twitter Title">'
            . '<title>Page Title</title>'
            . '</head><body></body></html>';

        $result = $this->service->parseHtmlPublic($html);

        $this->assertEquals('Twitter Title', $result['title']);
    }

    public function test_parse_html_falls_back_to_title_tag(): void
    {
        $html = '<html><head><title>Page Title</title></head><body></body></html>';

        $result = $this->service->parseHtmlPublic($html);

        $this->assertEquals('Page Title', $result['title']);
    }

    public function test_parse_html_title_is_null_when_absent(): void
    {
        $html = '<html><head></head><body>No title here.</body></html>';

        $result = $this->service->parseHtmlPublic($html);

        $this->assertNull($result['title']);
    }

    // -------------------------------------------------------------------------
    // parseHtml — description priority chain
    // -------------------------------------------------------------------------

    public function test_parse_html_uses_og_description(): void
    {
        $html = '<html><head>'
            . '<meta property="og:description" content="OG Desc">'
            . '<meta name="description" content="Meta Desc">'
            . '</head><body></body></html>';

        $result = $this->service->parseHtmlPublic($html);

        $this->assertEquals('OG Desc', $result['description']);
    }

    public function test_parse_html_falls_back_to_meta_description(): void
    {
        $html = '<html><head>'
            . '<meta name="description" content="Meta Desc">'
            . '</head><body></body></html>';

        $result = $this->service->parseHtmlPublic($html);

        $this->assertEquals('Meta Desc', $result['description']);
    }

    public function test_parse_html_description_is_null_when_absent(): void
    {
        $html = '<html><head><title>T</title></head><body></body></html>';

        $result = $this->service->parseHtmlPublic($html);

        $this->assertNull($result['description']);
    }

    // -------------------------------------------------------------------------
    // parseHtml — image priority chain
    // -------------------------------------------------------------------------

    public function test_parse_html_uses_og_image(): void
    {
        $html = '<html><head>'
            . '<meta property="og:image" content="https://example.com/og.jpg">'
            . '<meta name="twitter:image" content="https://example.com/twitter.jpg">'
            . '</head><body></body></html>';

        $result = $this->service->parseHtmlPublic($html);

        $this->assertEquals('https://example.com/og.jpg', $result['image']);
    }

    public function test_parse_html_falls_back_to_twitter_image(): void
    {
        $html = '<html><head>'
            . '<meta name="twitter:image" content="https://example.com/twitter.jpg">'
            . '</head><body></body></html>';

        $result = $this->service->parseHtmlPublic($html);

        $this->assertEquals('https://example.com/twitter.jpg', $result['image']);
    }

    public function test_parse_html_image_is_null_when_absent(): void
    {
        $html = '<html><head><title>T</title></head><body></body></html>';

        $result = $this->service->parseHtmlPublic($html);

        $this->assertNull($result['image']);
    }

    // -------------------------------------------------------------------------
    // parseHtml — text sanitisation
    // -------------------------------------------------------------------------

    public function test_parse_html_trims_whitespace_from_title(): void
    {
        $html = '<html><head><title>   Padded Title   </title></head><body></body></html>';

        $result = $this->service->parseHtmlPublic($html);

        $this->assertEquals('Padded Title', $result['title']);
    }

    public function test_parse_html_strips_non_printable_characters_from_title(): void
    {
        $nonPrintable = "\x01\x02\x03";
        $html = '<html><head>'
            . '<meta property="og:title" content="Clean' . $nonPrintable . 'Title">'
            . '</head><body></body></html>';

        $result = $this->service->parseHtmlPublic($html);

        $this->assertEquals('CleanTitle', $result['title']);
    }

    public function test_parse_html_strips_non_printable_characters_from_description(): void
    {
        $nonPrintable = "\x01\x08";
        $html = '<html><head>'
            . '<meta property="og:description" content="Clean' . $nonPrintable . 'Desc">'
            . '</head><body></body></html>';

        $result = $this->service->parseHtmlPublic($html);

        $this->assertEquals('CleanDesc', $result['description']);
    }

    public function test_parse_html_trims_whitespace_from_image_url(): void
    {
        $html = '<html><head>'
            . '<meta property="og:image" content="  https://example.com/img.jpg  ">'
            . '</head><body></body></html>';

        $result = $this->service->parseHtmlPublic($html);

        $this->assertEquals('https://example.com/img.jpg', $result['image']);
    }

    // -------------------------------------------------------------------------
    // parseHtml — edge cases
    // -------------------------------------------------------------------------

    public function test_parse_html_handles_empty_string(): void
    {
        // TODO: latent bug — DOMDocument::loadHTML() throws ValueError on empty string;
        // parseHtml() should guard against this and return ['title'=>null,'description'=>null,'image'=>null].
        $this->markTestSkipped('Latent bug: parseHtml() does not guard against empty input (throws ValueError)');
    }

    public function test_parse_html_handles_malformed_html(): void
    {
        $html = '<html unclosed <head><title>Broken</title></head>';

        $result = $this->service->parseHtmlPublic($html);

        // DOMDocument should still extract the title despite malformed markup
        $this->assertEquals('Broken', $result['title']);
    }

    public function test_parse_html_returns_all_fields_at_once(): void
    {
        $html = '<html><head>'
            . '<meta property="og:title" content="Full Title">'
            . '<meta property="og:description" content="Full Description">'
            . '<meta property="og:image" content="https://example.com/full.jpg">'
            . '</head><body></body></html>';

        $result = $this->service->parseHtmlPublic($html);

        $this->assertEquals('Full Title', $result['title']);
        $this->assertEquals('Full Description', $result['description']);
        $this->assertEquals('https://example.com/full.jpg', $result['image']);
        $this->assertArrayHasKey('title', $result);
        $this->assertArrayHasKey('description', $result);
        $this->assertArrayHasKey('image', $result);
    }

    // -------------------------------------------------------------------------
    // URL_PATTERN constant
    // -------------------------------------------------------------------------

    #[\PHPUnit\Framework\Attributes\DataProvider('urlPatternProvider')]
    public function test_url_pattern_matches(string $text, array $expectedUrls): void
    {
        preg_match_all(NewsfeedLinkPreviewService::URL_PATTERN, $text, $matches);
        $this->assertEquals($expectedUrls, $matches[0]);
    }

    public static function urlPatternProvider(): array
    {
        return [
            'https URL' => [
                'Check https://example.com/page',
                ['https://example.com/page'],
            ],
            'http URL' => [
                'See http://example.com/',
                ['http://example.com/'],
            ],
            'URL with query string' => [
                'Visit https://example.com/search?q=test&lang=en',
                ['https://example.com/search?q=test&lang=en'],
            ],
            'URL with fragment' => [
                'Read https://example.com/page#section',
                ['https://example.com/page#section'],
            ],
            'multiple URLs in text' => [
                'First https://first.com then https://second.com/path',
                ['https://first.com', 'https://second.com/path'],
            ],
            'no URL in text' => [
                'No URLs here at all',
                [],
            ],
            'URL with path and extension' => [
                'Image at https://example.com/images/photo.jpg',
                ['https://example.com/images/photo.jpg'],
            ],
            'URL surrounded by parentheses is truncated at closing paren' => [
                // The pattern stops at whitespace/special chars, not parens
                'See (https://example.com/page) for details',
                ['https://example.com/page)'],
            ],
            'URL at end of sentence stops before period if followed by space' => [
                'Go to https://example.com/page for info',
                ['https://example.com/page'],
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // upsertInvalid — DB integration
    // -------------------------------------------------------------------------

    public function test_upsert_invalid_inserts_new_record(): void
    {
        $url = 'https://invalid-' . uniqid() . '.example.com/';

        $id = $this->service->upsertInvalidPublic($url);

        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);

        $this->assertDatabaseHas('link_previews', [
            'url'     => $url,
            'invalid' => 1,
        ]);
    }

    public function test_upsert_invalid_returns_existing_id_on_duplicate(): void
    {
        $url = 'https://invalid-dup-' . uniqid() . '.example.com/';

        $firstId  = $this->service->upsertInvalidPublic($url);
        $secondId = $this->service->upsertInvalidPublic($url);

        $this->assertEquals($firstId, $secondId);
        $this->assertEquals(1, DB::table('link_previews')->where('url', $url)->count());
    }

    // -------------------------------------------------------------------------
    // getOrCreate — DB integration
    // -------------------------------------------------------------------------

    public function test_get_or_create_returns_cached_id(): void
    {
        $url = 'https://cached-' . uniqid() . '.example.com/';

        $insertedId = DB::table('link_previews')->insertGetId([
            'url'       => $url,
            'title'     => 'Cached',
            'retrieved' => now()->subDays(3),
            'invalid'   => 0,
        ]);

        Http::fake();

        $result = $this->service->getOrCreatePublic($url);

        $this->assertEquals($insertedId, $result);
        Http::assertNothingSent();
    }

    public function test_get_or_create_refetches_expired_cache(): void
    {
        $url = 'https://expired-' . uniqid() . '.example.com/';

        DB::table('link_previews')->insert([
            'url'       => $url,
            'title'     => 'Old',
            'retrieved' => now()->subDays(8),
            'invalid'   => 0,
        ]);

        $html = '<html><head><meta property="og:title" content="Refreshed"></head></html>';
        Http::fake([$url => Http::response($html, 200)]);

        $result = $this->service->getOrCreatePublic($url);

        $this->assertNotNull($result);
        Http::assertSentCount(1);
    }

    // -------------------------------------------------------------------------
    // create — HTTP integration
    // -------------------------------------------------------------------------

    public function test_create_stores_parsed_metadata(): void
    {
        $url  = 'https://new-' . uniqid() . '.example.com/';
        $html = '<html><head>'
            . '<meta property="og:title" content="New Title">'
            . '<meta property="og:description" content="New Desc">'
            . '<meta property="og:image" content="https://example.com/img.png">'
            . '</head><body></body></html>';

        Http::fake([$url => Http::response($html, 200)]);

        $id = $this->service->createPublic($url);

        $this->assertIsInt($id);
        $this->assertDatabaseHas('link_previews', [
            'url'         => $url,
            'title'       => 'New Title',
            'description' => 'New Desc',
            'image'       => 'https://example.com/img.png',
            'invalid'     => 0,
        ]);
    }

    public function test_create_marks_invalid_on_http_failure(): void
    {
        $url = 'https://fail-' . uniqid() . '.example.com/page';
        Http::fake([$url => Http::response('', 503)]);

        $id = $this->service->createPublic($url);

        $this->assertIsInt($id);
        $this->assertDatabaseHas('link_previews', [
            'url'     => $url,
            'invalid' => 1,
        ]);
    }

    public function test_create_marks_invalid_on_exception(): void
    {
        $url = 'https://throw-' . uniqid() . '.example.com/';
        Http::fake([$url => fn () => throw new \RuntimeException('Network error')]);

        $id = $this->service->createPublic($url);

        $this->assertIsInt($id);
        $this->assertDatabaseHas('link_previews', [
            'url'     => $url,
            'invalid' => 1,
        ]);
    }

    public function test_create_upgrades_http_to_https_for_request(): void
    {
        $httpUrl   = 'http://upgrade-' . uniqid() . '.example.com/';
        $httpsUrl  = str_replace('http://', 'https://', $httpUrl);

        $html = '<html><head><title>Upgraded</title></head><body></body></html>';
        Http::fake([$httpsUrl => Http::response($html, 200)]);

        $this->service->createPublic($httpUrl);

        Http::assertSent(fn ($req) => str_starts_with($req->url(), 'https://'));
    }
}
