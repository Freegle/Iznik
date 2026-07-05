<?php

namespace App\Services\CommunityNews;

use App\Models\CommunityNewsArea;
use App\Models\CommunityNewsItem;
use App\Models\Group;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

    /**
     * Research one area and (unless dry-run) store fresh CommunityNewsItem rows.
     *
     * @return array{ok:bool, items:int, intro:?string, preview?:array}
     */
    public function researchArea(CommunityNewsArea $area, bool $dryRun = false): array
    {
        $parsed = $this->generate($area);
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
    public function generate(CommunityNewsArea $area): ?array
    {
        $apiKey = config('freegle.communitynews.anthropic_api_key');
        if (empty($apiKey)) {
            Log::warning('CommunityNews: ANTHROPIC_API_KEY not set; cannot research', ['area' => $area->id]);
            return null;
        }

        $model = config('freegle.communitynews.model', 'claude-opus-4-8');
        $maxItems = (int) config('freegle.communitynews.items_per_area', 6);
        $maxIterations = (int) config('freegle.communitynews.max_search_iterations', 8);

        $messages = [[
            'role' => 'user',
            'content' => $this->userPrompt($area, $maxItems),
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

        $finalText = null;

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

            $finalText = $thisText;
            break;
        }

        if ($finalText === null || trim($finalText) === '') {
            Log::warning('CommunityNews: empty research response', ['area' => $area->id]);
            return null;
        }

        return $this->parse($finalText, $maxItems);
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

        $intro = isset($json['intro']) && is_string($json['intro']) ? trim($json['intro']) : '';

        $items = [];
        foreach ((array) ($json['items'] ?? []) as $it) {
            if (!is_array($it)) {
                continue;
            }
            $title = trim((string) ($it['title'] ?? ''));
            $blurb = trim((string) ($it['blurb'] ?? ''));
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

        Use web search to find REAL, CURRENT, interesting local things in and around {$name} — community events, repair cafés, litter picks, swaps and give-aways, festivals and fairs, library/park/museum happenings, local good-news stories, seasonal things to do, and environmental or reuse-flavoured bits — the sort of thing a friendly neighbour would tip you off about. Prefer this week and the next couple of weeks.

        Rules:
        - Only include things you actually found via search, each with a real source URL. Never invent an event, date, place or link.
        - Keep everything genuinely LOCAL to {$name} (or clearly within it). If you can't find enough real local material, return fewer items — quality over quantity.
        - UK English. Light, second-person, roughly 40-60 words per item: what it is and why someone might fancy it. No hashtags, no marketing-speak, at most the occasional emoji.
        - Don't plug Freegle itself unless a Freegle event genuinely comes up.
        SYS;
    }

    private function userPrompt(CommunityNewsArea $area, int $maxItems): string
    {
        $name = $area->name;
        $lat = $area->lat;
        $lng = $area->lng;
        $groups = $this->groupNames($area);

        return <<<USER
        Area: {$name} (roughly centred on {$lat}, {$lng}; it covers the Freegle communities: {$groups}).

        Find up to {$maxItems} interesting, genuinely local community happenings for readers here, this week or in the next couple of weeks.

        Then reply with ONLY a JSON object — no prose, no code fences — in exactly this shape:
        {"intro":"one or two warm, quirky sentences introducing this week's round-up for {$name}","items":[{"title":"punchy title","blurb":"~45-word friendly description","url":"the source URL you found","source":"the site or organisation name"}]}
        USER;
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
