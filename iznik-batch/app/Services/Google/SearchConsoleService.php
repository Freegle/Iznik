<?php

namespace App\Services\Google;

use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Weekly check of Google Search Console for every property the service account can
 * see: are the sitemaps being read, are there sitemap errors or warnings, is the
 * sitemap we expect actually submitted, and do a few sample URLs still look
 * indexable to Google.
 *
 * Read-only unless $submitMissing is set, which submits the expected sitemap to a
 * property that has none.
 *
 * What it cannot see: the per-reason "Page indexing" report (for example "Server
 * error (5xx)") has no API. The sample URL inspection is the nearest thing, and
 * the sitemap error/warning counts are what Google reports for the sitemap files.
 */
class SearchConsoleService
{
    private const SITES = 'https://www.googleapis.com/webmasters/v3/sites';

    private const INSPECT = 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect';

    /** How the inspection API says Google failed to fetch a page. */
    private const BAD_FETCH_STATES = [
        'SOFT_404', 'BLOCKED_ROBOTS_TXT', 'NOT_FOUND', 'ACCESS_DENIED', 'SERVER_ERROR',
        'REDIRECT_ERROR', 'ACCESS_FORBIDDEN', 'BLOCKED_4XX', 'INTERNAL_CRAWL_ERROR', 'INVALID_URL',
    ];

    public function __construct(private readonly GoogleAuth $auth)
    {
    }

    public function run(bool $submitMissing = false): GoogleCheckResult
    {
        $problems = [];
        $fine = [];
        $log = [];

        try {
            $token = $this->auth->serviceAccountToken([
                $submitMissing
                    ? 'https://www.googleapis.com/auth/webmasters'
                    : 'https://www.googleapis.com/auth/webmasters.readonly',
            ]);

            $sites = $this->sites($token);
            if ($sites === []) {
                return new GoogleCheckResult(false, 'The Google service account cannot see any Search Console property. Add it as a user on each property.', []);
            }

            foreach ($sites as $site) {
                $this->checkSitemaps($token, $site, $submitMissing, $problems, $fine, $log);
            }

            $this->inspectSamples($token, $sites, $problems, $fine, $log);
        } catch (GoogleApiException $e) {
            $log[] = $e->getMessage();

            return new GoogleCheckResult(false, 'Could not check Search Console: ' . $e->getMessage(), $log);
        }

        return $problems === []
            ? new GoogleCheckResult(true, implode('; ', $fine), $log)
            : new GoogleCheckResult(false, implode('; ', $problems), $log);
    }

    /**
     * @return list<string> property URLs, for example sc-domain:example.org
     */
    private function sites(string $token): array
    {
        $rsp = Http::withToken($token)->timeout(30)->get(self::SITES);
        $this->ensure($rsp, 'listing Search Console properties');

        $only = array_filter(array_map('trim', explode(',', (string) config('freegle.google.search_console.sites'))));
        $sites = [];
        foreach ($rsp->json('siteEntry') ?? [] as $entry) {
            if (($entry['permissionLevel'] ?? '') === 'siteUnverifiedUser') {
                continue;
            }
            if ($only === [] || in_array($entry['siteUrl'], $only, true)) {
                $sites[] = $entry['siteUrl'];
            }
        }

        return $sites;
    }

    private function checkSitemaps(string $token, string $site, bool $submitMissing, array &$problems, array &$fine, array &$log): void
    {
        $rsp = Http::withToken($token)->timeout(30)->get(self::SITES . '/' . rawurlencode($site) . '/sitemaps');
        $this->ensure($rsp, "listing sitemaps for {$site}");

        $sitemaps = $rsp->json('sitemap') ?? [];
        $maxAge = (int) config('freegle.google.search_console.max_sitemap_age_days');
        $bad = [];

        foreach ($sitemaps as $sm) {
            $path = $sm['path'] ?? '?';
            $errors = (int) ($sm['errors'] ?? 0);
            $warnings = (int) ($sm['warnings'] ?? 0);
            $log[] = sprintf('%s %s: errors=%d warnings=%d pending=%s lastDownloaded=%s', $site, $path, $errors, $warnings, ! empty($sm['isPending']) ? 'yes' : 'no', $sm['lastDownloaded'] ?? 'never');

            if ($errors > 0) {
                $bad[] = "{$path} has {$errors} error(s)";
            }
            if ($warnings > 0) {
                $bad[] = "{$path} has {$warnings} warning(s)";
            }
            if (empty($sm['isPending'])) {
                if (empty($sm['lastDownloaded'])) {
                    $bad[] = "{$path} has never been read";
                } elseif (Carbon::parse($sm['lastDownloaded'])->lt(now()->subDays($maxAge))) {
                    $bad[] = "{$path} was last read " . (int) Carbon::parse($sm['lastDownloaded'])->diffInDays(now()) . ' days ago';
                }
            }
        }

        $expected = (string) config('freegle.google.search_console.sitemap_url');
        $match = (string) config('freegle.google.search_console.sitemap_site_match');
        if ($expected !== '' && $match !== '' && str_contains($site, $match)) {
            $listed = array_filter($sitemaps, fn ($sm) => ($sm['path'] ?? '') === $expected);
            if ($listed === []) {
                if ($submitMissing) {
                    $put = Http::withToken($token)->timeout(30)->withBody('', 'application/json')
                        ->put(self::SITES . '/' . rawurlencode($site) . '/sitemaps/' . rawurlencode($expected));
                    $this->ensure($put, "submitting {$expected} for {$site}");
                    $log[] = "Submitted {$expected} for {$site}";
                } else {
                    $bad[] = "{$expected} is not submitted (run with --submit-missing)";
                }
            }
        }

        if ($bad === []) {
            $fine[] = "{$site}: " . count($sitemaps) . ' sitemap(s) read, no errors';
        } else {
            $problems[] = "{$site}: " . implode(', ', $bad);
        }
    }

    /**
     * Ask Google how it sees a few pages. Only a page Google could not fetch is a
     * problem; "unknown to Google" is normal for a brand new post.
     */
    private function inspectSamples(string $token, array $sites, array &$problems, array &$fine, array &$log): void
    {
        $count = (int) config('freegle.google.search_console.inspect_posts');
        $expected = (string) config('freegle.google.search_console.sitemap_url');
        $match = (string) config('freegle.google.search_console.sitemap_site_match');
        if ($count <= 0 || $expected === '') {
            return;
        }

        $site = collect($sites)->first(fn ($s) => $match !== '' && str_contains($s, $match));
        if ($site === null) {
            return;
        }

        $urls = $this->sampleUrls($expected, $count);
        $failed = [];
        foreach ($urls as $url) {
            $rsp = Http::withToken($token)->timeout(60)->post(self::INSPECT, ['inspectionUrl' => $url, 'siteUrl' => $site]);
            if (! $rsp->successful()) {
                $log[] = "Inspecting {$url}: HTTP {$rsp->status()}";
                $failed[] = "{$url} could not be inspected (HTTP {$rsp->status()})";

                continue;
            }

            $index = $rsp->json('inspectionResult.indexStatusResult') ?? [];
            $fetch = $index['pageFetchState'] ?? 'UNKNOWN';
            $log[] = sprintf('Inspected %s: verdict=%s coverage=%s fetch=%s lastCrawl=%s', $url, $index['verdict'] ?? '?', $index['coverageState'] ?? '?', $fetch, $index['lastCrawlTime'] ?? 'never');

            if (in_array($fetch, self::BAD_FETCH_STATES, true)) {
                $failed[] = "{$url} fetch state {$fetch}";
            }
        }

        if ($failed === []) {
            $fine[] = count($urls) . ' sample page(s) fetched by Google without errors';
        } else {
            $problems[] = 'Google could not fetch: ' . implode(', ', $failed);
        }
    }

    /**
     * The home page plus a spread of live posts, read from our own sitemap.
     *
     * @return list<string>
     */
    private function sampleUrls(string $sitemapUrl, int $postCount): array
    {
        $home = preg_replace('#/[^/]*$#', '/', $sitemapUrl);
        $urls = [$home];

        $index = Http::timeout(30)->get($sitemapUrl);
        if (! $index->successful()) {
            return $urls;
        }

        preg_match_all('#<loc>([^<]*sitemap-posts/[^<]+)</loc>#', $index->body(), $children);
        $child = end($children[1]);
        if ($child === false) {
            return $urls;
        }

        $posts = Http::timeout(60)->get(html_entity_decode($child));
        if (! $posts->successful()) {
            return $urls;
        }

        preg_match_all('#<loc>([^<]+)</loc>#', $posts->body(), $locs);
        $all = $locs[1];
        for ($i = 0; $i < $postCount && $all !== []; $i++) {
            $urls[] = html_entity_decode($all[intdiv($i * count($all), $postCount)]);
        }

        return array_values(array_unique($urls));
    }

    private function ensure(Response $rsp, string $what): void
    {
        if ($rsp->successful()) {
            return;
        }

        $why = $rsp->json('error.message') ?? ('HTTP ' . $rsp->status());

        throw new GoogleApiException("Google refused {$what}: {$why}");
    }
}
