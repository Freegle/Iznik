<?php

namespace Tests\Feature\CommunityNews;

use App\Models\CommunityNewsArea;
use App\Models\CommunityNewsItem;
use App\Services\CommunityNews\CommunityNewsResearchService;
use App\Services\NewsfeedLinkPreviewService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class CommunityNewsResearchServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(NewsfeedLinkPreviewService::class, new UnreachableLinkPreviewService());
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
            // Read the env the CLI was given (PendingProcess::$environment) via a bound closure.
            $env = (array) (function () {
                return $this->environment ?? [];
            })->call($process);

            return str_contains($cmd, 'claude')
                && str_contains($cmd, '--allowedTools')
                && str_contains($cmd, 'WebSearch')
                && str_contains($cmd, '--output-format')
                // ...and the token travels in the environment, never on the argv.
                && !str_contains($cmd, 'sk-ant-oat01-test')
                // Subscription must win: the inherited metered key is stripped from the child
                // env (false => Symfony Process removes it) so `claude` uses the setup-token.
                && array_key_exists('ANTHROPIC_API_KEY', $env)
                && $env['ANTHROPIC_API_KEY'] === false;
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

    public function test_parse_replaces_em_dashes_with_hyphens(): void
    {
        $text = json_encode([
            'intro' => 'News — fresh this week',
            'items' => [
                ['title' => 'Fete—this Saturday', 'blurb' => 'Cakes, stalls — and more.', 'url' => 'https://ok.org', 'source' => 'S'],
            ],
        ]);

        [$intro, $items] = $this->svc()->parse($text, 6);

        $this->assertSame('News - fresh this week', $intro);
        $this->assertSame('Fete - this Saturday', $items[0]['title']);
        $this->assertSame('Cakes, stalls - and more.', $items[0]['blurb']);
    }

    /**
     * The newsletter can only skip a past event if research recorded when the
     * event is. researched_at says when WE looked, which is a different thing.
     */
    public function test_parse_captures_an_event_date(): void
    {
        $text = json_encode([
            'intro' => 'x',
            'items' => [
                ['title' => 'Fete', 'blurb' => 'b', 'url' => 'https://ok.org', 'source' => 'S', 'date' => '2026-09-12'],
            ],
        ]);

        [, $items] = $this->svc()->parse($text, 6);

        $this->assertSame('2026-09-12', $items[0]['date']);
    }

    /** Most items are not dated events, and must come back with no date at all. */
    public function test_parse_leaves_an_undated_item_undated(): void
    {
        $text = json_encode([
            'intro' => 'x',
            'items' => [
                ['title' => 'New cycle path', 'blurb' => 'b', 'url' => 'https://ok.org', 'source' => 'S'],
            ],
        ]);

        [, $items] = $this->svc()->parse($text, 6);

        $this->assertNull($items[0]['date']);
    }

    /**
     * A wrong date is worse than none: it either buries an event that is still
     * to come or lets a past one through, which is the whole point of the field.
     * Anything that is not an exact, real, plausible YYYY-MM-DD is dropped.
     */
    public function test_parse_rejects_dates_it_cannot_trust(): void
    {
        $cases = [
            'Saturday',            // not a date at all
            '12/09/2026',          // wrong format, and ambiguous day/month
            '2026-13-01',          // no such month
            '2026-02-31',          // overflows to 3 March if parsed loosely
            '2035-01-01',          // implausibly far out for a local listing
            '',
        ];

        foreach ($cases as $raw) {
            $text = json_encode([
                'intro' => 'x',
                'items' => [
                    ['title' => 'T', 'blurb' => 'b', 'url' => 'https://ok.org', 'source' => 'S', 'date' => $raw],
                ],
            ]);

            [, $items] = $this->svc()->parse($text, 6);

            $this->assertNull($items[0]['date'], "expected '{$raw}' to be rejected as an event date");
        }
    }

    /**
     * Welsh-named areas coax the model into token Welsh greetings ("Croeso i
     * mid August", "Shwmae, Caernarfon!") on an otherwise-English intro. The
     * deterministic backstop strips the greeting sentence at parse time so a
     * mixed-language intro can never be stored, however the model phrases it.
     */
    public function test_parse_strips_a_token_welsh_greeting_from_the_intro(): void
    {
        $text = json_encode([
            'intro' => 'Croeso i mid August, Wrecsam! The balloons are inflating and the parks are humming.',
            'items' => [
                ['title' => 'T', 'blurb' => 'b', 'url' => 'https://ok.org', 'source' => 'S'],
            ],
        ]);

        [$intro] = $this->svc()->parse($text, 6);

        $this->assertSame('The balloons are inflating and the parks are humming.', $intro);
    }

    /** Item titles are exempt: a Welsh EVENT name is a name, not a greeting. */
    public function test_parse_leaves_welsh_named_items_alone(): void
    {
        $text = json_encode([
            'intro' => 'x',
            'items' => [
                ['title' => 'Croeso i Gaerdydd festival', 'blurb' => 'A weekend of Welsh music.', 'url' => 'https://ok.org', 'source' => 'S'],
            ],
        ]);

        [, $items] = $this->svc()->parse($text, 6);

        $this->assertSame('Croeso i Gaerdydd festival', $items[0]['title']);
    }

    /** The prompts must pin the output language, intro included. */
    // -------------------------------------------------------------------------
    // Stale sources and repeats never reach the table

    /** Make item URLs reachable so the source-freshness check actually runs. */
    private function makeSourcesReachable(): void
    {
        $this->app->instance(NewsfeedLinkPreviewService::class, new FetchableLinkPreviewService());
    }

    private function articlePage(string $published): string
    {
        return '<html><head><meta property="og:type" content="article" />'
            . '<meta property="article:published_time" content="' . $published . '" />'
            . '</head><body>x</body></html>';
    }

    private function researchReturning(array $items): array
    {
        return [
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => json_encode(['intro' => 'Hi', 'items' => $items])]],
        ];
    }

    public function test_item_from_a_stale_news_article_is_not_stored(): void
    {
        // The RiverFest shape: the model hands back a plausible future date for
        // an article written over a decade ago.
        config(['freegle.communitynews.anthropic_api_key' => 'test-key']);
        config(['freegle.communitynews.check_image_year' => false]);
        $this->makeSourcesReachable();

        Http::fake([
            'api.anthropic.com/*' => Http::response($this->researchReturning([
                ['title' => 'RiverFest', 'blurb' => 'Dragon boats this weekend.', 'url' => 'https://stale.example.org/riverfest', 'source' => 'Mag', 'date' => '2026-08-31'],
                ['title' => 'Book sale', 'blurb' => 'Paperbacks on Saturday.', 'url' => 'https://fresh.example.org/books', 'source' => 'Library', 'date' => '2026-08-31'],
            ]), 200),
            'stale.example.org/*' => Http::response($this->articlePage('2014-08-14T14:57:00Z'), 200),
            'fresh.example.org/*' => Http::response($this->articlePage('2026-08-20T09:00:00Z'), 200),
        ]);

        $area = $this->area();
        $result = $this->svc()->researchArea($area);

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['items'], 'the stale item should not be counted as stored');

        $stored = CommunityNewsItem::where('areaid', $area->id)->pluck('title')->all();
        $this->assertSame(['Book sale'], $stored);
    }

    public function test_unreachable_source_still_stores_the_item(): void
    {
        // A check we cannot complete must never cost us a good item.
        config(['freegle.communitynews.anthropic_api_key' => 'test-key']);
        config(['freegle.communitynews.check_image_year' => false]);
        $this->makeSourcesReachable();

        Http::fake([
            'api.anthropic.com/*' => Http::response($this->researchReturning([
                ['title' => 'Book sale', 'blurb' => 'Paperbacks on Saturday.', 'url' => 'https://down.example.org/books', 'source' => 'Library', 'date' => '2026-08-31'],
            ]), 200),
            'down.example.org/*' => Http::response('gone', 503),
        ]);

        $area = $this->area();
        $this->svc()->researchArea($area);

        $this->assertSame(1, CommunityNewsItem::where('areaid', $area->id)->count());
    }

    public function test_url_already_researched_for_the_area_is_not_stored_again(): void
    {
        // The RiverFest article was harvested for Hove twice, six days apart:
        // the first copy expired unposted, the second went out.
        config(['freegle.communitynews.anthropic_api_key' => 'test-key']);

        $area = $this->area();
        CommunityNewsItem::create([
            'areaid' => $area->id,
            'title' => 'RiverFest (first harvest)',
            'snippet' => 'Dragon boats.',
            'url' => 'https://example.org/riverfest',
            'researched_at' => now()->subDays(6),
        ]);

        Http::fake(['api.anthropic.com/*' => Http::response($this->researchReturning([
            ['title' => 'RiverFest again', 'blurb' => 'Dragon boats this weekend.', 'url' => 'https://example.org/riverfest', 'source' => 'Mag'],
            ['title' => 'Book sale', 'blurb' => 'Paperbacks on Saturday.', 'url' => 'https://example.org/books', 'source' => 'Library'],
        ]), 200)]);

        $this->svc()->researchArea($area);

        $titles = CommunityNewsItem::where('areaid', $area->id)->orderBy('id')->pluck('title')->all();
        $this->assertSame(['RiverFest (first harvest)', 'Book sale'], $titles);
    }

    public function test_the_same_url_is_still_stored_for_a_different_area(): void
    {
        // Neighbouring areas legitimately share an event; dedup is per area.
        config(['freegle.communitynews.anthropic_api_key' => 'test-key']);

        $other = CommunityNewsArea::create([
            'anchorgroupid' => 2,
            'name' => 'Nextville',
            'lat' => 51.6,
            'lng' => -0.2,
            'groupids' => [],
            'groupcount' => 0,
        ]);
        CommunityNewsItem::create([
            'areaid' => $other->id,
            'title' => 'Shared fair',
            'snippet' => 'A fair.',
            'url' => 'https://example.org/fair',
            'researched_at' => now(),
        ]);

        Http::fake(['api.anthropic.com/*' => Http::response($this->researchReturning([
            ['title' => 'Shared fair', 'blurb' => 'Worth the trip on Saturday.', 'url' => 'https://example.org/fair', 'source' => 'Council'],
        ]), 200)]);

        $area = $this->area();
        $this->svc()->researchArea($area);

        $this->assertSame(1, CommunityNewsItem::where('areaid', $area->id)->count());
    }

    public function test_research_prompts_demand_a_current_source(): void
    {
        // The code-side guards are the backstop; the model is still told not to
        // write up a previous year's edition of a recurring event in the first
        // place, which is exactly how the 2014 RiverFest article got picked up.
        config(['freegle.communitynews.anthropic_api_key' => 'test-key']);

        Http::fake(['api.anthropic.com/*' => Http::response($this->researchReturning([
            ['title' => 'T', 'blurb' => 'B', 'url' => 'https://x.org', 'source' => 'S'],
        ]), 200)]);

        $this->svc()->generate($this->area());

        Http::assertSent(function ($request) {
            $system = strtolower($request->data()['system'] ?? '');

            return str_contains($system, "previous year's edition")
                && str_contains($system, 'when the page was published');
        });
    }

    public function test_research_prompts_demand_english_only(): void
    {
        config(['freegle.communitynews.anthropic_api_key' => 'test-key']);

        $json = json_encode(['intro' => 'x', 'items' => [
            ['title' => 'T', 'blurb' => 'b', 'url' => 'https://ok.org', 'source' => 'S'],
        ]]);
        Http::fake(['api.anthropic.com/*' => Http::response([
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => $json]],
        ], 200)]);

        $this->svc()->researchArea($this->area());

        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_contains($body['system'] ?? '', 'ENTIRELY in English')
                && str_contains($body['messages'][0]['content'] ?? '', 'in UK English');
        });
    }
}
