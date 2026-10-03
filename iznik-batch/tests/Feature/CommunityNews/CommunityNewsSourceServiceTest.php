<?php

namespace Tests\Feature\CommunityNews;

use App\Models\CommunityNewsArea;
use App\Services\CommunityNews\CommunityNewsSourceService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CommunityNewsSourceServiceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/cn-sources-'.uniqid();
        @mkdir($this->dir, 0777, true);
        config(['freegle.communitynews.sources_path' => $this->dir]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function svc(): CommunityNewsSourceService
    {
        return app(CommunityNewsSourceService::class);
    }

    private function writePlace(string $file, array $data): string
    {
        $path = $this->dir.'/'.$file.'.json';
        file_put_contents($path, json_encode($data));

        return $path;
    }

    private function source(string $url, array $over = []): array
    {
        return array_merge([
            'name' => 'Src', 'url' => $url, 'type' => 'rss',
            'added' => '2026-07-01', 'last_checked' => null, 'last_ok' => null,
            'status' => 'unchecked', 'consecutive_failures' => 0,
        ], $over);
    }

    private function reload(string $file): array
    {
        return json_decode(file_get_contents($file), true);
    }

    public function test_places_for_area_matches_by_place_name(): void
    {
        $this->writePlace('elsewhere', ['place' => 'Nowhereton', 'sources' => [$this->source('https://a.example/feed')]]);
        $this->writePlace('byname', ['place' => 'Oxford', 'sources' => [$this->source('https://b.example/feed')]]);

        $areaByName = CommunityNewsArea::create(['authorityid' => 910301, 'name' => 'Oxford & nearby', 'lat' => 51.7, 'lng' => -1.2]);
        $matched = $this->svc()->placesForArea($areaByName);
        $this->assertCount(1, $matched);
        $this->assertSame('Oxford', $matched[0]['data']['place']);
    }

    public function test_live_sources_excludes_dead(): void
    {
        $area = CommunityNewsArea::create(['authorityid' => 910302, 'name' => 'Oxford', 'lat' => 51.7, 'lng' => -1.2]);
        $this->writePlace('oxford', ['place' => 'Oxford', 'sources' => [
            $this->source('https://live.example/feed', ['status' => 'ok']),
            $this->source('https://dead.example/feed', ['status' => 'dead']),
        ]]);

        $live = $this->svc()->liveSourcesForArea($area);
        $this->assertCount(1, $live);
        $this->assertSame('https://live.example/feed', $live[0]['url']);
    }

    public function test_health_check_marks_dead_after_failures_then_revives(): void
    {
        config(['freegle.communitynews.source_dead_after' => 2]);
        $file = $this->writePlace('oxford', ['place' => 'Oxford', 'sources' => [$this->source('https://feed.example/rss')]]);

        // Two failures then a success across the three health-checks. A single
        // fake with a sequence — re-calling Http::fake() would merge stubs and the
        // first-registered pattern keeps winning.
        Http::fake(['feed.example/*' => Http::sequence()
            ->push('', 500)
            ->push('', 500)
            ->push('<rss/>', 200)]);

        $this->svc()->maintainAll(true);
        $this->assertSame('failing', $this->reload($file)['sources'][0]['status']);

        $this->svc()->maintainAll(true);
        $this->assertSame('dead', $this->reload($file)['sources'][0]['status']);

        $this->svc()->maintainAll(true);
        $revived = $this->reload($file)['sources'][0];
        $this->assertSame('ok', $revived['status']);
        $this->assertSame(0, $revived['consecutive_failures']);
    }

    public function test_due_for_discovery(): void
    {
        config(['freegle.communitynews.source_discovery_days' => 90]);
        $svc = $this->svc();
        $this->assertTrue($svc->dueForDiscovery(['last_discovered' => null]));
        $this->assertTrue($svc->dueForDiscovery(['last_discovered' => now()->subDays(120)->toDateString()]));
        $this->assertFalse($svc->dueForDiscovery(['last_discovered' => now()->subDays(10)->toDateString()]));
    }

    public function test_discover_appends_only_verified_new_sources(): void
    {
        config(['freegle.communitynews.anthropic_api_key' => 'test-key']);
        $file = $this->writePlace('oxford', ['place' => 'Oxford', 'last_discovered' => null, 'sources' => [$this->source('https://known.example/feed')]]);

        $json = json_encode(['sources' => [
            ['name' => 'Fresh Local News', 'url' => 'https://fresh.example/feed', 'type' => 'rss'],
            ['name' => 'Known already', 'url' => 'https://known.example/feed', 'type' => 'rss'],
            ['name' => 'Dead candidate', 'url' => 'https://gone.example/feed', 'type' => 'rss'],
        ]]);
        Http::fake([
            'api.anthropic.com/*' => Http::response(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => $json]]], 200),
            'fresh.example/*' => Http::response('<rss/>', 200),
            'gone.example/*' => Http::response('', 404),
        ]);

        $result = $this->svc()->discoverAll(true);
        $this->assertSame(1, $result['added']); // only the fresh, verified, non-duplicate one

        $urls = array_column($this->reload($file)['sources'], 'url');
        $this->assertContains('https://fresh.example/feed', $urls);
        $this->assertNotContains('https://gone.example/feed', $urls);   // failed verification
        $this->assertNotNull($this->reload($file)['last_discovered']);
    }
}
