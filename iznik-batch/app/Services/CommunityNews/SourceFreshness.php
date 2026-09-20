<?php

namespace App\Services\CommunityNews;

use App\Services\NewsfeedLinkPreviewService;
use Carbon\Carbon;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Catches the research model dressing up a stale source as something happening
 * now.
 *
 * The existing date guards (community_news_items.event_date and
 * MentionedDates) only ever look BACKWARDS - they drop an event that is already
 * over. They are blind to the opposite failure, which is what put a 2014
 * Shoreham RiverFest article on the newsfeed as "this bank holiday weekend" on
 * 2026-08-28: the model read the article's "August Bank Holiday weekend",
 * resolved it against THIS year's calendar, and supplied a perfectly future
 * event_date of 2026-08-31. Every filter waved it through, and a member replied
 * "this was from 12 years ago :)".
 *
 * So this checks the SOURCE rather than the claim, on two signals:
 *
 *  1. The page is a dated news ARTICLE published long ago. Measured against 200
 *     real sources: requiring og:type=article AND an explicit
 *     article:published_time is what makes this safe. On an evergreen page (a
 *     festival homepage, a venue's what's-on) published_time is merely when the
 *     page was created - the Loch Ness Marathon (2010), York Beer Festival
 *     (2022) and Cowbridge Music Festival (2022) pages all carry ancient
 *     published_time values while advertising genuinely current events, and
 *     trusting that date alone rejected 23 of 167 items, mostly wrongly.
 *
 *  2. The picture we are about to publish alongside the item advertises an
 *     earlier year. The RiverFest og:image - the one actually attached to the
 *     post - reads "23-25 AUGUST 2014" in giant type, which is both proof the
 *     item is stale and, on its own, a bad thing to show a member.
 *
 * Everything here is best-effort and conservative: any doubt (unfetchable page,
 * no published date, no credentials, a vision call that fails) returns null,
 * meaning "no evidence of staleness", and the item flows through exactly as it
 * does today. It only ever rejects on positive proof.
 *
 * The cost of that is a blind spot worth knowing the size of. Measured over the
 * 120 busiest source domains on 2026-09-05, 17 still cannot be read at all -
 * 11 answer with a 403, 5 are gone (404/410), and 1 redirects to a page that
 * fails in its own right - so for items from those the check is off. They are
 * logged rather than left invisible.
 * The archive is not a way round it: of the pages we cannot fetch, most are not
 * archived, and only one of them carried the og:type=article and published_time
 * this rule needs, so reading the archive would have rejected nothing.
 */
final class SourceFreshness
{
    private const ANTHROPIC_URL = 'https://api.anthropic.com/v1/messages';

    /** Anthropic caps an image at ~5MB base64, which is ~3.7MB of raw bytes. */
    private const MAX_IMAGE_BYTES = 3 * 1024 * 1024;

    private const VISION_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function __construct(private NewsfeedLinkPreviewService $previews)
    {
    }

    /**
     * Why this item's source contradicts an event happening now, or null when it
     * doesn't (which includes every case we cannot judge).
     *
     * @param ?string $eventDate The item's claimed event date (Y-m-d). When the
     *                           item is undated we judge against the current
     *                           year, since an undated item is implicitly "now".
     */
    public function staleReason(?string $url, ?string $eventDate = null): ?string
    {
        $url = trim((string) $url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return null;
        }

        // Same SSRF guard as the link-preview fetcher: these URLs come from the
        // research model, so they are no more trustworthy than member input.
        if (!$this->previews->isFetchableUrl($url)) {
            return null;
        }

        $html = $this->fetch($url);
        if ($html === null) {
            return null;
        }

        $xpath = $this->xpath($html);
        if ($xpath === null) {
            return null;
        }

        return $this->staleArticle($xpath) ?? $this->stalePoster($xpath, $eventDate);
    }

    /**
     * A news article published long before the event it is supposedly announcing.
     */
    private function staleArticle(DOMXPath $xpath): ?string
    {
        if (strtolower((string) $this->meta($xpath, 'og:type')) !== 'article') {
            return null;
        }

        $raw = $this->meta($xpath, 'article:published_time')
            ?? $this->meta($xpath, 'og:article:published_time');
        if ($raw === null) {
            return null;
        }

        // Deliberately strict, exactly like parseEventDate: article:published_time
        // is ISO 8601 by specification, and Carbon::parse() would otherwise read
        // "2014" as today and "last Tuesday" as this week - a loose parse both
        // misses real staleness and risks inventing it.
        if (!preg_match('/^(\d{4}-\d{2}-\d{2})/', $raw, $m)) {
            return null;
        }

        try {
            $published = Carbon::createFromFormat('Y-m-d', $m[1])->startOfDay();
        } catch (\Throwable $e) {
            return null;
        }
        // createFromFormat accepts overflow (2026-02-31 becomes 3 March), so a
        // nonsense date would silently become a real one.
        if ($published->format('Y-m-d') !== $m[1]) {
            return null;
        }
        if ($published->year < 1990 || $published->year > (int) now()->year + 1) {
            return null;
        }

        $maxAge = max(1, (int) config('freegle.communitynews.source_max_age_days', 365));
        if ($published->gte(now()->subDays($maxAge)->startOfDay())) {
            return null;
        }

        return sprintf(
            'source article published %s, over %d days ago',
            $published->toDateString(),
            $maxAge
        );
    }

    /**
     * The source's own picture advertises a year earlier than the one claimed.
     */
    private function stalePoster(DOMXPath $xpath, ?string $eventDate): ?string
    {
        if (!config('freegle.communitynews.check_image_year', true)) {
            return null;
        }
        if (trim((string) config('freegle.communitynews.anthropic_api_key', '')) === '') {
            return null;
        }

        $imageUrl = $this->meta($xpath, 'og:image') ?? $this->meta($xpath, 'twitter:image');
        if (!$imageUrl || !preg_match('#^https?://#i', $imageUrl) || !$this->previews->isFetchableUrl($imageUrl)) {
            return null;
        }

        $image = $this->fetchImage($imageUrl);
        if ($image === null) {
            return null;
        }

        $years = $this->posterYears($image['data'], $image['mime']);
        if (empty($years)) {
            return null;
        }

        $claimed = $eventDate && preg_match('/^(\d{4})-/', $eventDate, $m)
            ? (int) $m[1]
            : (int) now()->year;

        $latest = max($years);
        if ($latest >= $claimed) {
            return null;
        }

        return sprintf('source image advertises %d, but the item claims %d', $latest, $claimed);
    }

    /**
     * The years shown on a promotional image as the date the event happens.
     *
     * @return array<int,int>
     */
    private function posterYears(string $data, string $mime): array
    {
        $prompt = 'This picture illustrates a listing for a local event. Read any text in it. '
            . 'List ONLY years that form part of the date the event TAKES PLACE - a poster reading '
            . '"23-25 August 2014" gives [2014]. Ignore every other year: "established 1892", copyright '
            . 'notices, historical or anniversary references, prices, addresses, phone numbers, registration '
            . 'numbers. If no year of the event date is legible, return an empty list. '
            . 'Reply with ONLY JSON in this shape, no prose: {"years":[2014]}';

        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'x-api-key' => config('freegle.communitynews.anthropic_api_key'),
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ])
                ->post(self::ANTHROPIC_URL, [
                    'model' => config('freegle.communitynews.vision_model', 'claude-haiku-4-5'),
                    'max_tokens' => 200,
                    'messages' => [[
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'image',
                                'source' => [
                                    'type' => 'base64',
                                    'media_type' => $mime,
                                    'data' => base64_encode($data),
                                ],
                            ],
                            ['type' => 'text', 'text' => $prompt],
                        ],
                    ]],
                ]);
        } catch (\Throwable $e) {
            Log::info('CommunityNews poster-year check threw', ['error' => $e->getMessage()]);

            return [];
        }

        if (!$response->successful()) {
            Log::info('CommunityNews poster-year check failed', ['status' => $response->status()]);

            return [];
        }

        $text = '';
        foreach ($response->json('content') ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'];
            }
        }

        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end < $start) {
            return [];
        }
        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);

        $years = [];
        foreach ((array) ($decoded['years'] ?? []) as $y) {
            $y = (int) $y;
            // Anything outside a plausible poster year is a misread, and acting
            // on a misread would drop a perfectly good item.
            if ($y >= 1900 && $y <= (int) now()->year + 5) {
                $years[] = $y;
            }
        }

        return $years;
    }

    private function fetch(string $url): ?string
    {
        // Redirects stay off so a public URL cannot bounce the fetch to an
        // internal one behind the SSRF check. We do follow a redirect that stays
        // on the same host, because refusing those was costing us the page for
        // no safety gain: measured over the 120 busiest source domains on
        // 2026-09-05, 5 answer with a redirect and every one of them was the
        // site tidying its own URL (a canonical slug, a trailing slash), landing
        // on the host we already cleared.
        for ($hop = 0; $hop < 3; $hop++) {
            try {
                $response = Http::timeout(15)
                    ->withOptions(['allow_redirects' => false])
                    ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; Freegle/1.0)'])
                    ->get($url);
            } catch (\Throwable $e) {
                return null;
            }

            if ($response->successful()) {
                $body = $response->body();

                return trim($body) === '' ? null : $body;
            }

            $next = $response->redirect() ? $this->sameHostTarget($url, (string) $response->header('Location')) : null;
            if ($next === null) {
                Log::info('CommunityNews: could not read a source, so it goes unchecked', [
                    'url' => $url,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $url = $next;
        }

        Log::info('CommunityNews: source redirected too many times to read', ['url' => $url]);

        return null;
    }

    /**
     * Where a redirect points, but only when that is the same host we already
     * cleared - anything else we decline to follow.
     */
    private function sameHostTarget(string $from, string $location): ?string
    {
        $location = trim($location);
        if ($location === '') {
            return null;
        }

        $fromHost = strtolower((string) parse_url($from, PHP_URL_HOST));
        if ($fromHost === '') {
            return null;
        }

        // A relative Location stays on this host by definition.
        if (!preg_match('#^https?://#i', $location)) {
            $scheme = parse_url($from, PHP_URL_SCHEME) ?: 'https';
            $location = $scheme . '://' . $fromHost . '/' . ltrim($location, '/');
        }

        if (strtolower((string) parse_url($location, PHP_URL_HOST)) !== $fromHost) {
            return null;
        }

        return $this->previews->isFetchableUrl($location) ? $location : null;
    }

    /**
     * @return array{data:string, mime:string}|null
     */
    private function fetchImage(string $url): ?array
    {
        try {
            $response = Http::timeout(15)
                ->withOptions(['allow_redirects' => false])
                ->withHeaders(['User-Agent' => 'Freegle-CommunityNews'])
                ->get($url);
        } catch (\Throwable $e) {
            return null;
        }

        if (!$response->successful()) {
            return null;
        }

        $mime = strtolower(trim(explode(';', $response->header('Content-Type') ?? '')[0]));
        $data = $response->body();
        if (!in_array($mime, self::VISION_MIMES, true) || $data === '' || strlen($data) > self::MAX_IMAGE_BYTES) {
            return null;
        }

        return ['data' => $data, 'mime' => $mime];
    }

    private function xpath(string $html): ?DOMXPath
    {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        try {
            // loadHTML throws ValueError on an empty string (PHP 8) - fetch()
            // already rules that out, but a parse failure must not escape here.
            $dom->loadHTML($html, LIBXML_NOERROR);
        } catch (\Throwable $e) {
            return null;
        } finally {
            libxml_clear_errors();
        }

        return new DOMXPath($dom);
    }

    private function meta(DOMXPath $xpath, string $value): ?string
    {
        foreach (['property', 'name'] as $attr) {
            $nodes = $xpath->query("//meta[@{$attr}='{$value}']/@content");
            if ($nodes && $nodes->length > 0) {
                $content = trim((string) $nodes->item(0)->nodeValue);
                if ($content !== '') {
                    return $content;
                }
            }
        }

        return null;
    }
}
