<?php

namespace Tests\Feature\CommunityNews;

use App\Models\CommunityNewsArea;
use App\Models\CommunityNewsItem;
use App\Services\CommunityNews\CommunityNewsResearchService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class CommunityNewsResearchServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Isolate from the repo's curated source store so these tests exercise
        // pure web-search research with no seed sources.
        config(['freegle.communitynews.sources_path' => sys_get_temp_dir() . '/cn-none-' . uniqid()]);
        // Default to the API path (no subscription token) so these tests are
        // deterministic even when CLAUDE_CODE_OAUTH_TOKEN leaks in from the env,
        // and never actually exec the CLI.
        config(['freegle.communitynews.oauth_token' => '']);
        Process::fake();
    }

    private function area(): CommunityNewsArea
    {
        return CommunityNewsArea::create([
            'anchorgroupid' => 1,
            'name' => 'Testville',
            'lat' => 51.5,
            'lng' => -0.12,
            'groupids' => [],
            'groupcount' => 0,
        ]);
    }

    private function svc(): CommunityNewsResearchService
    {
        return app(CommunityNewsResearchService::class);
    }

    public function test_research_stores_items_and_intro(): void
    {
        config(['freegle.communitynews.anthropic_api_key' => 'test-key']);

        $json = json_encode([
            'intro' => 'Lovely local bits this week.',
            'items' => [
                ['title' => 'Repair Café', 'blurb' => 'Fix your toaster for free on Saturday.', 'url' => 'https://example.org/repair', 'source' => 'Library'],
                ['title' => 'Litter pick', 'blurb' => 'Grabbers provided, biscuits after.', 'url' => 'https://example.org/litter', 'source' => 'Park Friends'],
            ],
        ]);

        Http::fake(['api.anthropic.com/*' => Http::response([
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => "Here you go:\n\n" . $json]],
        ], 200)]);

        $area = $this->area();
        $result = $this->svc()->researchArea($area);

        $this->assertTrue($result['ok']);
        $this->assertSame(2, $result['items']);
        $this->assertSame(2, CommunityNewsItem::where('areaid', $area->id)->count());

        $area->refresh();
        $this->assertNotNull($area->lastresearched);
        $this->assertSame('Lovely local bits this week.', $area->intro);

        $first = CommunityNewsItem::where('areaid', $area->id)->orderBy('id')->first();
        $this->assertSame('Repair Café', $first->title);
        $this->assertSame('https://example.org/repair', $first->url);

        // We actually asked for web search on the current tool version.
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.anthropic.com')
                && ($request['tools'][0]['type'] ?? null) === 'web_search_20260209';
        });
    }

    public function test_generate_seeds_curated_sources_and_adds_web_fetch(): void
    {
        config(['freegle.communitynews.anthropic_api_key' => 'test-key']);

        $json = json_encode(['intro' => 'x', 'items' => [
            ['title' => 'T', 'blurb' => 'B', 'url' => 'https://x.org', 'source' => 'S'],
        ]]);
        Http::fake(['api.anthropic.com/*' => Http::response([
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => $json]],
        ], 200)]);

        $seed = [['name' => 'Oxford City Council News', 'url' => 'https://www.oxford.gov.uk/rss/news', 'type' => 'rss']];
        $result = $this->svc()->generate($this->area(), $seed);

        $this->assertNotNull($result);
        Http::assertSent(function ($request) {
            $body = $request->data();
            $toolTypes = array_column($body['tools'] ?? [], 'type');
            $prompt = $body['messages'][0]['content'] ?? '';

            return in_array('web_fetch_20260209', $toolTypes, true)
                && str_contains($prompt, 'Oxford City Council News');
        });
    }

    public function test_research_handles_pause_turn_then_resumes(): void
    {
        config(['freegle.communitynews.anthropic_api_key' => 'test-key']);

        $json = json_encode(['intro' => 'Hi', 'items' => [
            ['title' => 'Thing', 'blurb' => 'A thing worth a look.', 'url' => 'https://x.org', 'source' => 'S'],
        ]]);

        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push(['stop_reason' => 'pause_turn', 'content' => [
                ['type' => 'server_tool_use', 'id' => 'srv', 'name' => 'web_search', 'input' => []],
            ]], 200)
            ->push(['stop_reason' => 'end_turn', 'content' => [
                ['type' => 'text', 'text' => $json],
            ]], 200),
        ]);

        $area = $this->area();
        $result = $this->svc()->researchArea($area);

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['items']);
        Http::assertSentCount(2);
    }

    public function test_research_uses_the_claude_cli_when_a_setup_token_is_present(): void
    {
        // A `claude setup-token` subscription token wins over the metered key:
        // research must shell out to the `claude` CLI, not touch the Messages API.
        config([
            'freegle.communitynews.oauth_token' => 'sk-ant-oat01-test',
            'freegle.communitynews.anthropic_api_key' => 'unused-key',
        ]);

        $json = json_encode([
            'intro' => 'Warm local bits.',
            'items' => [
                ['title' => 'Seed swap', 'blurb' => 'Bring spare seeds on Sunday.', 'url' => 'https://example.org/seeds', 'source' => 'Allotment'],
            ],
        ]);

        Http::fake();
        // claude -p --output-format json wraps the answer as {"result": "..."}.
        Process::fake([
            '*' => Process::result(output: json_encode(['type' => 'result', 'result' => $json, 'total_cost_usd' => 0.0])),
        ]);

        $area = $this->area();
        $result = $this->svc()->researchArea($area);

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['items']);
        $this->assertSame(1, CommunityNewsItem::where('areaid', $area->id)->count());

        // Went via the CLI (WebSearch, JSON output) and NOT the metered API...
        Http::assertNothingSent();
        Process::assertRan(function ($process) {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

            return str_contains($cmd, 'claude')
                && str_contains($cmd, '--allowedTools')
                && str_contains($cmd, 'WebSearch')
                && str_contains($cmd, '--output-format')
                // ...and the token travels in the environment, never on the argv.
                && !str_contains($cmd, 'sk-ant-oat01-test');
        });
    }

    public function test_research_without_any_credentials_is_a_noop(): void
    {
        config([
            'freegle.communitynews.anthropic_api_key' => '',
            'freegle.communitynews.oauth_token' => '',
        ]);
        Http::fake();

        $area = $this->area();
        $result = $this->svc()->researchArea($area);

        $this->assertFalse($result['ok']);
        $this->assertSame(0, CommunityNewsItem::where('areaid', $area->id)->count());
        Http::assertNothingSent();
        Process::assertNothingRan();
    }

    public function test_parse_strips_fence_and_drops_unsafe_url(): void
    {
        $text = "```json\n" . json_encode([
            'intro' => 'x',
            'items' => [
                ['title' => 'A', 'blurb' => 'a', 'url' => 'javascript:alert(1)', 'source' => 'S'],
                ['title' => 'B', 'blurb' => 'b', 'url' => 'https://ok.org', 'source' => 'S'],
                ['title' => '', 'blurb' => 'no title -> skipped', 'url' => 'https://z.org', 'source' => 'S'],
            ],
        ]) . "\n```";

        [$intro, $items] = $this->svc()->parse($text, 6);

        $this->assertSame('x', $intro);
        $this->assertCount(2, $items);           // empty-title item dropped
        $this->assertNull($items[0]['url']);     // javascript: dropped
        $this->assertSame('https://ok.org', $items[1]['url']);
    }
}
