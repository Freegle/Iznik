<?php

namespace Tests\Feature\Newsfeed;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GenerateLinkPreviewsCommandTest extends TestCase
{
    private function insertNewsfeed(array $attrs): void
    {
        DB::table('newsfeed')->insert(array_merge([
            'type'      => 'Message',
            'timestamp' => now(),
            'added'     => now(),
            'position'  => DB::raw("ST_GeomFromText('POINT(0 0)', 3857)"),
        ], $attrs));
    }

    public function test_runs_cleanly_with_empty_newsfeed(): void
    {
        Http::fake();

        $this->artisan('newsfeed:generate-link-previews')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_skips_posts_without_urls(): void
    {
        Http::fake();

        $this->insertNewsfeed(['message' => 'No URLs in this post']);

        $this->artisan('newsfeed:generate-link-previews')
            ->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertEquals(0, DB::table('link_previews')->count());
    }

    public function test_creates_preview_for_new_url(): void
    {
        $html = '<html><head>'
            . '<meta property="og:title" content="Test Title">'
            . '<meta property="og:description" content="Test Description">'
            . '<meta property="og:image" content="https://example.com/img.jpg">'
            . '</head><body></body></html>';

        Http::fake([
            'https://example.com/article' => Http::response($html, 200),
        ]);

        $this->insertNewsfeed(['message' => 'Check this out https://example.com/article']);

        $this->artisan('newsfeed:generate-link-previews')
            ->assertExitCode(0);

        $this->assertDatabaseHas('link_previews', [
            'url'         => 'https://example.com/article',
            'title'       => 'Test Title',
            'description' => 'Test Description',
            'image'       => 'https://example.com/img.jpg',
            'invalid'     => 0,
        ]);
    }

    public function test_skips_url_with_fresh_cache(): void
    {
        DB::table('link_previews')->insert([
            'url'       => 'https://cached.com/',
            'title'     => 'Cached',
            'retrieved' => now()->subDays(3),
            'invalid'   => 0,
        ]);

        Http::fake();

        $this->insertNewsfeed(['message' => 'Check https://cached.com/']);

        $this->artisan('newsfeed:generate-link-previews')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_refreshes_expired_cache(): void
    {
        DB::table('link_previews')->insert([
            'url'       => 'https://stale.com/',
            'title'     => 'Old Title',
            'retrieved' => now()->subDays(8),
            'invalid'   => 0,
        ]);

        $html = '<html><head><meta property="og:title" content="New Title"></head><body></body></html>';
        Http::fake(['https://stale.com/' => Http::response($html, 200)]);

        $this->insertNewsfeed(['message' => 'Check https://stale.com/']);

        $this->artisan('newsfeed:generate-link-previews')
            ->assertExitCode(0);

        Http::assertSentCount(1);
    }

    public function test_marks_invalid_on_http_failure(): void
    {
        Http::fake(['https://broken.com/*' => Http::response('', 404)]);

        $this->insertNewsfeed(['message' => 'Check https://broken.com/page']);

        $this->artisan('newsfeed:generate-link-previews')
            ->assertExitCode(0);

        $this->assertDatabaseHas('link_previews', [
            'url'     => 'https://broken.com/page',
            'invalid' => 1,
        ]);
    }

    public function test_deduplicates_same_url_across_posts(): void
    {
        $html = '<html><head><title>Dedup Test</title></head><body></body></html>';
        Http::fake(['https://example.com/dup' => Http::response($html, 200)]);

        $this->insertNewsfeed(['message' => 'Post 1 https://example.com/dup']);
        $this->insertNewsfeed(['message' => 'Post 2 https://example.com/dup']);

        $this->artisan('newsfeed:generate-link-previews')
            ->assertExitCode(0);

        Http::assertSentCount(1);
        $this->assertEquals(1, DB::table('link_previews')->where('url', 'https://example.com/dup')->count());
    }

    public function test_ignores_newsfeed_posts_older_than_2_days(): void
    {
        Http::fake();

        $this->insertNewsfeed([
            'message'   => 'Old post https://old.example.com/',
            'timestamp' => now()->subDays(3),
            'added'     => now()->subDays(3),
        ]);

        $this->artisan('newsfeed:generate-link-previews')
            ->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertEquals(0, DB::table('link_previews')->count());
    }

    public function test_upgrades_http_urls_to_https(): void
    {
        $html = '<html><head><meta property="og:title" content="HTTPS Test"></head><body></body></html>';
        Http::fake(['https://example.com/page' => Http::response($html, 200)]);

        $this->insertNewsfeed(['message' => 'Check http://example.com/page']);

        $this->artisan('newsfeed:generate-link-previews')
            ->assertExitCode(0);

        Http::assertSent(fn ($req) => $req->url() === 'https://example.com/page');
        $this->assertDatabaseHas('link_previews', [
            'url' => 'http://example.com/page',
        ]);
    }
}
