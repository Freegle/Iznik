<?php

namespace App\Services\CommunityNews;

use App\Models\CommunityNewsArea;
use App\Models\CommunityNewsItem;
use App\Models\Group;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Researches an area and writes a handful of warm, local Community News nuggets.
 *
 * One Anthropic call per area, using the web_search server tool to find genuinely
 * local goings-on, returning a friendly intro + a list of items as JSON. This is
 * the "rip-off" of the zeitgeist digest-brief: gather locality content, then one
 * model call writes the briefing. Everything else is deterministic PHP.
 */
class CommunityNewsResearchService
{
    private const ANTHROPIC_URL = 'https://api.anthropic.com/v1/messages';

    public function __construct(private CommunityNewsSourceService $sources)
    {
    }

    /**
     * Research one area and (unless dry-run) store fresh CommunityNewsItem rows.
     *
     * @return array{ok:bool, items:int, intro:?string, preview?:array}
     */
    public function researchArea(CommunityNewsArea $area, bool $dryRun = false): array
    {
        // Maintain the curated source store (spot dead feeds) on each real run,
        // then seed the model with the live known-good local sources.
        if (!$dryRun) {
            $this->sources->maintainArea($area);
        }
        $seedSources = $this->sources->liveSourcesForArea($area);

        $parsed = $this->generate($area, $seedSources);
        if ($parsed === null) {
            return ['ok' => false, 'items' => 0, 'intro' => null];
        }

        [$intro, $items] = $parsed;

        if ($dryRun) {
            return ['ok' => true, 'items' => count($items), 'intro' => $intro, 'preview' => $items];
        }

        foreach ($items as $it) {
            CommunityNewsItem::create([
                'areaid' => $area->id,
                'title' => mb_substr($it['title'], 0, 250),
                'snippet' => $it['blurb'],
                'url' => $it['url'],
                'source' => $it['source'] !== null ? mb_substr($it['source'], 0, 250) : null,
                // Null unless this is a dated event; the newsletter uses it to
                // skip anything already over by the time it sends.
                'event_date' => $it['date'] ?? null,
                'researched_at' => now(),
            ]);
        }

        $area->update([
            'lastresearched' => now(),
            'intro' => $intro !== '' ? $intro : $area->intro,
        ]);

        return ['ok' => true, 'items' => count($items), 'intro' => $intro];
    }

    /**
     * Make the model call (with web_search) and parse the result.
     *
     * @return array{0:string,1:array<int,array{title:string,blurb:string,url:?string,source:?string}>}|null
     */
    public function generate(CommunityNewsArea $area, array $seedSources = []): ?array
    {
        $mode = $this->driverMode();
        if ($mode === null) {
            Log::warning('CommunityNews: no Anthropic credentials — set CLAUDE_CODE_OAUTH_TOKEN (from `claude setup-token`) to run on a Claude subscription, or ANTHROPIC_API_KEY; cannot research', ['area' => $area->id]);
            return null;
        }

        $maxItems = (int) config('freegle.communitynews.items_per_area', 6);

        $finalText = $mode === 'subscription'
            ? $this->researchViaCli($area, $seedSources)
            : $this->researchViaApi($area, $seedSources);

        if ($finalText === null || trim($finalText) === '') {
            Log::warning('CommunityNews: empty research response', ['area' => $area->id]);
            return null;
        }

        return $this->parse($finalText, $maxItems);
    }

    /**
     * Which backend runs the research call.
     *
     *  - 'subscription': a `claude setup-token` OAuth token (CLAUDE_CODE_OAUTH_TOKEN)
     *    is configured — run headlessly on a Claude subscription via the `claude`
     *    CLI, with no metered API spend. Takes precedence when set.
     *  - 'api': only ANTHROPIC_API_KEY is set — the raw Messages-API call.
     *  - null: neither credential configured; research is a no-op.
     */
    private function driverMode(): ?string
    {
        if (trim((string) config('freegle.communitynews.oauth_token', '')) !== '') {
            return 'subscription';
        }
        if (trim((string) config('freegle.communitynews.anthropic_api_key', '')) !== '') {
            return 'api';
        }

        return null;
    }

    /**
     * Metered path: one raw Anthropic Messages-API call using the web_search
     * (and, when we have curated seeds, web_fetch) server tools. Returns the
     * model's final text, or null on failure.
     */
    private function researchViaApi(CommunityNewsArea $area, array $seedSources): ?string
    {
        $apiKey = config('freegle.communitynews.anthropic_api_key');
        $model = config('freegle.communitynews.model', 'claude-opus-4-8');
        $maxItems = (int) config('freegle.communitynews.items_per_area', 6);
        $maxIterations = (int) config('freegle.communitynews.max_search_iterations', 8);

        $messages = [[
            'role' => 'user',
            'content' => $this->userPrompt($area, $maxItems, $seedSources),
        ]];

        $tools = [[
            'type' => 'web_search_20260209',
            'name' => 'web_search',
            'max_uses' => 8,
            'user_location' => [
                'type' => 'approximate',
                'country' => 'GB',
                'city' => $this->plainAreaName($area),
            ],
        ]];

        // With curated local feeds to hand, let the model fetch them directly.
        // web_fetch only fetches URLs already in the conversation — i.e. the seed
        // list we put in the prompt — so it can't wander off to arbitrary pages.
        if (!empty($seedSources)) {
            $tools[] = [
                'type' => 'web_fetch_20260209',
                'name' => 'web_fetch',
                'max_uses' => 10,
            ];
        }

        for ($iter = 0; $iter < max(1, $maxIterations); $iter++) {
            try {
                $response = Http::timeout(180)
                    ->withHeaders([
                        'x-api-key' => $apiKey,
                        'anthropic-version' => '2023-06-01',
                        'content-type' => 'application/json',
                    ])
                    ->post(self::ANTHROPIC_URL, [
                        'model' => $model,
                        'max_tokens' => 4096,
                        'system' => $this->systemPrompt($area),
                        'messages' => $messages,
                        'tools' => $tools,
                    ]);
            } catch (\Throwable $e) {
                Log::warning('CommunityNews research call threw', ['area' => $area->id, 'error' => $e->getMessage()]);
                return null;
            }

            if (!$response->successful()) {
                Log::warning('CommunityNews research call failed', [
                    'area' => $area->id,
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 500),
                ]);
                return null;
            }

            $data = $response->json();
            $content = $data['content'] ?? [];
            $stop = $data['stop_reason'] ?? null;

            $thisText = '';
            foreach ($content as $block) {
                if (($block['type'] ?? '') === 'text') {
                    $thisText .= $block['text'];
                }
            }

            // web_search runs a server-side loop; pause_turn means "resume" —
            // echo the assistant turn back and continue (no new user message).
            if ($stop === 'pause_turn') {
                $messages[] = ['role' => 'assistant', 'content' => $content];
                continue;
            }

            return $thisText;
        }

        return null;
    }

    /**
     * Subscription path: run the same research headlessly through the `claude`
     * CLI on a `claude setup-token` OAuth token (CLAUDE_CODE_OAUTH_TOKEN) — no
     * metered API spend. The CLI's built-in WebSearch (and WebFetch, when we have
     * curated seed URLs) stands in for the Messages-API server tools. Returns the
     * model's final text, or null on failure.
     *
     * Runs with a clean per-run CLAUDE_CONFIG_DIR so this repo's Claude hooks /
     * skills / settings never load into an unattended batch job, and in non-bare
     * mode (bare mode ignores CLAUDE_CODE_OAUTH_TOKEN). --output-format json wraps
     * the answer as {"result": "<text>", ...}.
     */
    private function researchViaCli(CommunityNewsArea $area, array $seedSources): ?string
    {
        $token = trim((string) config('freegle.communitynews.oauth_token', ''));
        $model = (string) config('freegle.communitynews.model', 'claude-opus-4-8');
        $maxItems = (int) config('freegle.communitynews.items_per_area', 6);
        $bin = (string) config('freegle.communitynews.claude_bin', 'claude');

        $configDir = trim((string) config('freegle.communitynews.claude_config_dir', ''));
        if ($configDir === '') {
            $configDir = rtrim(sys_get_temp_dir(), '/') . '/cn-claude-' . $area->id;
        }
        @mkdir($configDir, 0700, true);

        $allowedTools = empty($seedSources) ? 'WebSearch' : 'WebSearch,WebFetch';

        $command = [
            $bin,
            '-p', $this->userPrompt($area, $maxItems, $seedSources),
            '--append-system-prompt', $this->systemPrompt($area),
            '--allowedTools', $allowedTools,
            '--model', $model,
            '--output-format', 'json',
        ];

        try {
            // A full Opus research run (several web searches + writing) can
            // comfortably exceed 4 minutes; the API path effectively gets
            // 180s × max_search_iterations, so give the one-shot CLI similar room.
            $result = Process::timeout(600)
                ->env([
                    'CLAUDE_CODE_OAUTH_TOKEN' => $token,
                    'CLAUDE_CONFIG_DIR' => $configDir,
                    // Make the subscription token win: the CLI bills the metered API whenever
                    // ANTHROPIC_API_KEY is present (the reason monitor-fsm/run-loop.sh unsets it),
                    // and the batch process inherits that key from its own environment. `false`
                    // removes it from the child env (Symfony Process convention) so `claude`
                    // authenticates with the setup-token subscription, not the API key.
                    'ANTHROPIC_API_KEY' => false,
                ])
                ->run($command);
        } catch (\Throwable $e) {
            Log::warning('CommunityNews claude CLI threw', ['area' => $area->id, 'error' => $e->getMessage()]);
            return null;
        }

        if (!$result->successful()) {
            Log::warning('CommunityNews claude CLI failed', [
                'area' => $area->id,
                'exit' => $result->exitCode(),
                'err' => mb_substr($result->errorOutput(), 0, 500),
            ]);
            return null;
        }

        // --output-format json => {"type":"result","result":"<text>","total_cost_usd":...}
        $decoded = json_decode($result->output(), true);
        if (!is_array($decoded) || !array_key_exists('result', $decoded)) {
            Log::warning('CommunityNews: unparseable claude CLI output', [
                'area' => $area->id,
                'raw' => mb_substr($result->output(), 0, 300),
            ]);
            return null;
        }

        return (string) $decoded['result'];
    }

    /**
     * @return array{0:string,1:array}|null
     */
    public function parse(string $text, int $maxItems): ?array
    {
        $json = $this->extractJson($text);
        if ($json === null) {
            Log::warning('CommunityNews: could not parse research JSON', ['preview' => mb_substr($text, 0, 300)]);
            return null;
        }

        $intro = isset($json['intro']) && is_string($json['intro']) ? $this->replaceEmDashes(trim($json['intro'])) : '';
        // Zero mixed language: a token "Shwmae, Caernarfon!" opening never
        // reaches the stored intro, however the model phrases it. Items are
        // exempt - a Welsh event NAME is a name, not a greeting.
        $intro = IntroLanguage::stripForeignGreeting($intro);

        $items = [];
        foreach ((array) ($json['items'] ?? []) as $it) {
            if (!is_array($it)) {
                continue;
            }
            $title = $this->replaceEmDashes(trim((string) ($it['title'] ?? '')));
            $blurb = $this->replaceEmDashes(trim((string) ($it['blurb'] ?? '')));
            if ($title === '' || $blurb === '') {
                continue;
            }
            $url = isset($it['url']) ? trim((string) $it['url']) : '';
            if ($url !== '' && !preg_match('#^https?://#i', $url)) {
                $url = '';
            }
            $items[] = [
                'title' => $title,
                'blurb' => $blurb,
                'url' => $url !== '' ? $url : null,
                'source' => isset($it['source']) && trim((string) $it['source']) !== '' ? trim((string) $it['source']) : null,
                'date' => $this->parseEventDate($it['date'] ?? null),
            ];
            if (count($items) >= $maxItems) {
                break;
            }
        }

        if (empty($items)) {
            return null;
        }

        return [$intro, $items];
    }

    /**
     * The day an item's event happens, or null when it isn't a dated event.
     *
     * Deliberately strict: only an exact YYYY-MM-DD is accepted. A loose parse
     * would happily read "Saturday" as some arbitrary date, and a wrong date is
     * far worse than none — it either hides an event that is still to come or
     * lets a past one through, which is the whole problem this exists to stop.
     * Anything unparseable, or absurdly far from now, is treated as "no date"
     * and the item simply flows through as undated.
     */
    private function parseEventDate(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $value = trim($raw);
        if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            $date = \Carbon\Carbon::createFromFormat('Y-m-d', $value);
        } catch (\Throwable $e) {
            return null;
        }
        // createFromFormat accepts overflow (2026-02-31 becomes 3 March), which
        // means a nonsense date would silently become a real one.
        if ($date === false || $date->format('Y-m-d') !== $value) {
            return null;
        }

        // A community listing is about the coming weeks. A date years out is a
        // parsing artefact (or a typo in the source), not a real local event, and
        // trusting it would keep a stale item eligible indefinitely.
        if ($date->lt(now()->subYear()) || $date->gt(now()->addYear())) {
            return null;
        }

        return $date->toDateString();
    }

    /**
     * House style: no em dashes in member-facing news text. Enforced in code
     * rather than the prompt so it holds however the model phrases things.
     */
    private function replaceEmDashes(string $text): string
    {
        return trim(preg_replace('/\s*—\s*/u', ' - ', $text));
    }

    private function extractJson(string $text): ?array
    {
        $text = trim($text);

        // Strip a ```json ... ``` fence if present.
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $text, $m)) {
            $text = $m[1];
        }

        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function systemPrompt(CommunityNewsArea $area): string
    {
        $name = $area->name;

        return <<<SYS
        You are the editor of "Community News", a warm, gently quirky round-up of genuinely local goings-on for {$name}.

        It goes out to ordinary Freegle members. Freegle is a UK network where neighbours give away things they no longer need, for free — keeping usable stuff out of landfill. Your readers are normal people (not professionals or activists) who care a little about their community and about not being wasteful. Write for them: friendly, down-to-earth, a touch of warmth and humour, never corporate, worthy or preachy.

        Use web search to find REAL, CURRENT, interesting local things in and around {$name} — community events, litter picks, swaps and give-aways, festivals and fairs, library/park/museum happenings, local good-news stories, seasonal things to do, and environmental or reuse-flavoured bits — the sort of thing a friendly neighbour would tip you off about. Prefer this week and the next couple of weeks.

        Rules:
        - Only include things you actually found via search, each with a real source URL. Never invent an event, date, place or link.
        - Keep everything genuinely LOCAL to {$name} (or clearly within it). If you can't find enough real local material, return fewer items — quality over quantity.
        - Do NOT include repair cafés or Restart/Fixit-style repair events — Freegle already lists these separately (synced from the Restart Project and Repair Café Wales), so they would be duplicates.
        - UK English. Light, second-person, roughly 40-60 words per item: what it is and why someone might fancy it. No hashtags, no marketing-speak, at most the occasional emoji.
        - Write ENTIRELY in English, the intro included, even for areas in Wales or Scotland. Never open with or sprinkle in greetings or phrases from Welsh, Gaelic or any other language ("Croeso", "Shwmae", "Bore da"): a half-and-half opening like "Croeso i mid August" reads as tokenism, not warmth. Welsh or Gaelic NAMES of places, events and organisations (the Eisteddfod, a Menter Iaith event) are fine when that is their real name.
        - Where it fits NATURALLY, end an item with a short, playful Freegle tie-in — one sentence linking the story to giving or asking for things on your local Freegle community ("Need dancing shoes? Ask on Freegle", "Got records gathering dust? Someone nearby would love them"). Skip it where it would feel forced; never salesy.
        - Give dates ABSOLUTELY ("Saturday 2 August", "until 14 September"), never relatively ("this Saturday", "tomorrow", "next week") — items may be published up to a couple of weeks after you write them, so relative dates go stale.
        - Don't plug Freegle itself unless a Freegle event genuinely comes up.
        SYS;
    }

    private function userPrompt(CommunityNewsArea $area, int $maxItems, array $seedSources = []): string
    {
        $name = $area->name;
        $lat = $area->lat;
        $lng = $area->lng;
        $groups = $this->groupNames($area);
        $seedBlock = $this->seedBlock($seedSources);
        // The model cannot resolve "this Saturday" without knowing today's date.
        $today = now()->toDateString();

        return <<<USER
        Area: {$name} (roughly centred on {$lat}, {$lng}; it covers the Freegle communities: {$groups}).
        {$seedBlock}
        Find up to {$maxItems} interesting, genuinely local community happenings for readers here, this week or in the next couple of weeks.

        If an item happens on a particular day, give that day as "date" in YYYY-MM-DD form. Today is {$today}, so work out the actual date of anything described as "Saturday" or "next week". Give the FINAL day for something running over several days — readers stop being shown an item once its date has passed, and a multi-day event is still worth going to until it ends. If your blurb mentions a specific day ("Saturday 8 August"), you MUST also supply the matching "date" — blurb and date must always agree. Leave "date" out only when the item genuinely has no date — an ongoing service, a new footpath, a refurbished library — and never guess: for an undated item, an omitted date is much better than a wrong one.

        Then reply with ONLY a JSON object — no prose, no code fences — in exactly this shape:
        {"intro":"one or two warm, quirky sentences in UK English introducing this week's round-up for {$name}","items":[{"title":"punchy title","blurb":"~45-word friendly description","url":"the source URL you found","source":"the site or organisation name","date":"YYYY-MM-DD or omitted"}]}
        USER;
    }

    /** A prompt block listing the curated local sources to check first. */
    private function seedBlock(array $seedSources): string
    {
        if (empty($seedSources)) {
            return '';
        }

        $lines = [];
        foreach (array_slice($seedSources, 0, 30) as $s) {
            $lines[] = '- ' . $s['name'] . ' (' . $s['url'] . ')';
        }
        $list = implode("\n", $lines);

        return "\nCheck these known-good LOCAL sources FIRST (fetch them) for what's on, then use web search to fill any gaps:\n{$list}\n";
    }

    private function groupNames(CommunityNewsArea $area): string
    {
        $ids = array_map('intval', $area->groupids ?? []);
        if (empty($ids)) {
            return $area->name;
        }

        $names = Group::whereIn('id', $ids)
            ->orderBy('id')
            ->limit(30)
            ->pluck('namefull', 'id')
            ->map(fn ($full, $id) => $full)
            ->filter()
            ->values()
            ->all();

        return $names ? implode(', ', $names) : $area->name;
    }

    private function plainAreaName(CommunityNewsArea $area): string
    {
        // Drop a trailing "& nearby" tag for the search location hint.
        return trim(preg_replace('/\s*&\s*nearby\s*$/i', '', $area->name)) ?: $area->name;
    }
}
