<?php

namespace App\Services\CommunityNews;

use App\Models\CommunityNewsArea;
use App\Models\Group;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Curated per-place source store (JSON files under `sources_path`).
 *
 * Hand-curated known-good LOCAL feeds (council news, local outlets, local
 * reuse/community/environment orgs) that seed the Community News research for an
 * area — the model checks them first, then supplements with web search. Ported
 * from the original zeitgeist "Oxford" persona.
 *
 * The store self-maintains:
 *  - health-checked on each research run — a feed that fails to fetch
 *    `source_dead_after` times in a row is marked 'dead' and dropped from the
 *    seed (one good fetch revives it). This is how dead feeds get spotted.
 *  - re-discovered roughly quarterly (`source_discovery_days`) — the model finds
 *    new local sources, each verified by fetching before being appended.
 *
 * See data/community-news-sources/README.md for the file format.
 */
class CommunityNewsSourceService
{
    private const ANTHROPIC_URL = 'https://api.anthropic.com/v1/messages';

    public function path(): string
    {
        return rtrim((string) config('freegle.communitynews.sources_path'), '/');
    }

    /** All place files as ['file' => path, 'data' => array]. */
    public function allPlaces(): array
    {
        $dir = $this->path();
        if ($dir === '' || !is_dir($dir)) {
            return [];
        }

        $out = [];
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            $data = json_decode((string) @file_get_contents($file), true);
            if (is_array($data) && isset($data['sources']) && is_array($data['sources'])) {
                $out[] = ['file' => $file, 'data' => $data];
            }
        }
        return $out;
    }

    /**
     * Place files matching an area — by group short-name intersection, or (as a
     * fallback) the place name appearing in the area name.
     */
    public function placesForArea(CommunityNewsArea $area): array
    {
        $groupShorts = $this->areaGroupShortNames($area);
        $areaName = mb_strtolower($area->name);

        $matched = [];
        foreach ($this->allPlaces() as $place) {
            $groups = array_map('mb_strtolower', (array) ($place['data']['groups'] ?? []));
            $placeName = mb_strtolower((string) ($place['data']['place'] ?? ''));

            $byGroup = !empty(array_intersect($groups, $groupShorts));
            $byName = $placeName !== '' && str_contains($areaName, $placeName);

            if ($byGroup || $byName) {
                $matched[] = $place;
            }
        }
        return $matched;
    }

    /** @return array<int, string> lower-cased group short names in the area. */
    private function areaGroupShortNames(CommunityNewsArea $area): array
    {
        $ids = array_map('intval', $area->groupids ?? []);
        if (empty($ids)) {
            return [];
        }

        return Group::whereIn('id', $ids)->pluck('nameshort')
            ->filter()
            ->map(fn ($n) => mb_strtolower($n))
            ->values()
            ->all();
    }

    /**
     * Live (non-dead) sources for an area, as ['name','url','type'].
     *
     * @return array<int, array{name:string, url:string, type:string}>
     */
    public function liveSourcesForArea(CommunityNewsArea $area): array
    {
        $sources = [];
        foreach ($this->placesForArea($area) as $place) {
            foreach ($place['data']['sources'] as $s) {
                if (($s['status'] ?? 'unchecked') === 'dead') {
                    continue;
                }
                $url = (string) ($s['url'] ?? '');
                if ($url === '') {
                    continue;
                }
                $sources[] = [
                    'name' => (string) ($s['name'] ?? $url),
                    'url' => $url,
                    'type' => (string) ($s['type'] ?? 'site'),
                ];
            }
        }
        return $sources;
    }

    /**
     * Health-check the sources for an area (the "maintain on execute" step),
     * writing status back to the JSON files.
     *
     * @return array{checked:int, ok:int, dead:int}
     */
    public function maintainArea(CommunityNewsArea $area, bool $force = false): array
    {
        return $this->maintainPlaces($this->placesForArea($area), $force);
    }

    /**
     * Health-check every place file (used by the quarterly maintenance command).
     *
     * @return array{checked:int, ok:int, dead:int}
     */
    public function maintainAll(bool $force = false): array
    {
        return $this->maintainPlaces($this->allPlaces(), $force);
    }

    /**
     * @return array{checked:int, ok:int, dead:int}
     */
    private function maintainPlaces(array $places, bool $force): array
    {
        $totals = ['checked' => 0, 'ok' => 0, 'dead' => 0];
        foreach ($places as $place) {
            $s = $this->maintainFile($place['file'], $place['data'], $force);
            $totals['checked'] += $s['checked'];
            $totals['ok'] += $s['ok'];
            $totals['dead'] += $s['dead'];
        }
        return $totals;
    }

    /**
     * @return array{checked:int, ok:int, dead:int}
     */
    public function maintainFile(string $file, array $data, bool $force = false): array
    {
        $recheckHours = (int) config('freegle.communitynews.source_recheck_hours', 24);
        $deadAfter = (int) config('freegle.communitynews.source_dead_after', 3);

        $checked = 0;
        $ok = 0;
        $dead = 0;
        $changed = false;

        foreach ($data['sources'] as &$src) {
            $last = $src['last_checked'] ?? null;
            if (!$force && $last && Carbon::parse($last)->gt(now()->subHours($recheckHours))) {
                continue; // checked recently — don't hammer it
            }

            $live = $this->fetchOk((string) ($src['url'] ?? ''));
            $checked++;
            $src['last_checked'] = now()->toIso8601String();

            if ($live) {
                $wasDead = ($src['status'] ?? '') === 'dead';
                $src['status'] = 'ok';
                $src['consecutive_failures'] = 0;
                $src['last_ok'] = now()->toIso8601String();
                $ok++;
                if ($wasDead) {
                    Log::info('CommunityNews source revived', ['url' => $src['url'] ?? '']);
                }
            } else {
                $src['consecutive_failures'] = (int) ($src['consecutive_failures'] ?? 0) + 1;
                if ($src['consecutive_failures'] >= $deadAfter) {
                    if (($src['status'] ?? '') !== 'dead') {
                        Log::warning('CommunityNews source marked dead', ['url' => $src['url'] ?? '']);
                    }
                    $src['status'] = 'dead';
                    $dead++;
                } else {
                    $src['status'] = 'failing';
                }
            }
            $changed = true;
        }
        unset($src);

        if ($changed) {
            $this->save($file, $data);
        }

        return ['checked' => $checked, 'ok' => $ok, 'dead' => $dead];
    }

    /** True if the URL fetches with a 2xx response. */
    public function fetchOk(string $url): bool
    {
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return false;
        }
        try {
            return Http::timeout(15)
                ->withHeaders(['User-Agent' => 'FreegleCommunityNews/1.0 (+https://www.ilovefreegle.org)'])
                ->get($url)
                ->successful();
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function dueForDiscovery(array $data): bool
    {
        $days = (int) config('freegle.communitynews.source_discovery_days', 90);
        $last = $data['last_discovered'] ?? null;

        return !$last || Carbon::parse($last)->lt(now()->subDays($days));
    }

    /**
     * Discover NEW local sources for every place that's due (or all, with $force),
     * appending verified feeds and stamping `last_discovered`.
     *
     * @return array{places:int, added:int}
     */
    public function discoverAll(bool $force = false): array
    {
        $totals = ['places' => 0, 'added' => 0];
        foreach ($this->allPlaces() as $place) {
            if (!$force && !$this->dueForDiscovery($place['data'])) {
                continue;
            }
            $totals['places']++;
            $totals['added'] += $this->discoverForPlace($place['file'], $place['data']);
        }
        return $totals;
    }

    /**
     * Ask the model for new local sources for one place, verify each URL fetches,
     * append the fresh ones, and stamp `last_discovered`.
     */
    public function discoverForPlace(string $file, array $data): int
    {
        $place = (string) ($data['place'] ?? '');
        $existing = array_values(array_filter(array_map(
            fn ($s) => (string) ($s['url'] ?? ''),
            $data['sources']
        )));

        $candidates = $this->askForSources($place, $existing);

        $existingLc = array_map('mb_strtolower', $existing);
        $added = 0;
        foreach ($candidates as $c) {
            $url = trim((string) ($c['url'] ?? ''));
            if ($url === '' || !preg_match('#^https?://#i', $url)) {
                continue;
            }
            if (in_array(mb_strtolower($url), $existingLc, true)) {
                continue; // already have it
            }
            if (!$this->fetchOk($url)) {
                continue; // verify it's live before adding
            }

            $data['sources'][] = [
                'name' => mb_substr(trim((string) ($c['name'] ?? $url)), 0, 250),
                'url' => $url,
                'type' => in_array(($c['type'] ?? ''), ['rss', 'podcast_rss', 'site'], true) ? $c['type'] : 'rss',
                'added' => now()->toDateString(),
                'last_checked' => now()->toIso8601String(),
                'last_ok' => now()->toIso8601String(),
                'status' => 'ok',
                'consecutive_failures' => 0,
            ];
            $existingLc[] = mb_strtolower($url);
            $added++;
        }

        $data['last_discovered'] = now()->toDateString();
        $this->save($file, $data);

        return $added;
    }

    /**
     * One Anthropic web_search call asking for new local source feeds.
     *
     * @return array<int, array{name?:string, url?:string, type?:string}>
     */
    private function askForSources(string $place, array $existingUrls): array
    {
        $apiKey = config('freegle.communitynews.anthropic_api_key');
        if (empty($apiKey)) {
            Log::warning('CommunityNews: ANTHROPIC_API_KEY not set; cannot discover sources', ['place' => $place]);
            return [];
        }

        $model = config('freegle.communitynews.model', 'claude-opus-4-8');
        $known = implode(', ', array_slice($existingUrls, 0, 60));

        $prompt = <<<PROMPT
        Find local news / community / reuse / environment sources for {$place}, UK, that a resident would value for a friendly local round-up — council news, local news outlets, and local community/environment/reuse organisations. Prefer ones with an RSS/Atom feed.

        Do NOT repeat any of these already-known URLs: {$known}

        Verify each is real and current via web search. Then reply with ONLY a JSON object, no prose or code fences:
        {"sources":[{"name":"source name","url":"the feed or page URL","type":"rss|podcast_rss|site"}]}
        PROMPT;

        try {
            $response = Http::timeout(180)
                ->withHeaders([
                    'x-api-key' => $apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ])
                ->post(self::ANTHROPIC_URL, [
                    'model' => $model,
                    'max_tokens' => 2048,
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                    'tools' => [[
                        'type' => 'web_search_20260209',
                        'name' => 'web_search',
                        'max_uses' => 6,
                        'user_location' => ['type' => 'approximate', 'country' => 'GB', 'city' => $place],
                    ]],
                ]);
        } catch (\Throwable $e) {
            Log::warning('CommunityNews source discovery threw', ['place' => $place, 'error' => $e->getMessage()]);
            return [];
        }

        if (!$response->successful()) {
            Log::warning('CommunityNews source discovery failed', ['place' => $place, 'status' => $response->status()]);
            return [];
        }

        $text = '';
        foreach ($response->json('content', []) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'];
            }
        }

        $json = $this->extractJson($text);
        $sources = is_array($json['sources'] ?? null) ? $json['sources'] : [];

        return array_values(array_filter($sources, 'is_array'));
    }

    private function extractJson(string $text): array
    {
        $text = trim($text);
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $text, $m)) {
            $text = $m[1];
        }
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end < $start) {
            return [];
        }
        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function save(string $file, array $data): void
    {
        @file_put_contents(
            $file,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
        );
    }
}
