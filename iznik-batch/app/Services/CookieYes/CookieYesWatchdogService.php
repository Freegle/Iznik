<?php

namespace App\Services\CookieYes;

use Carbon\Carbon;

/**
 * Checks every website in the CookieYes account is still compliant with no one
 * looking: banner live, GDPR on, every cookie categorised, a recent scan. Starts
 * a new scan when the last is getting old.
 *
 * Nothing here categorises cookies. CookieYes's AI Cookie Classifier does that
 * after a scan (set to publish everything it classifies), so an uncategorised
 * cookie means the classifier did not cope and a person has to look.
 *
 * "Uncategorised" is judged on what the banner publishes to visitors, read from
 * the banner script on CookieYes's CDN. The scan results are only logged: they
 * record what the scanner found before the classifier ran, so they keep listing
 * cookies as uncategorised after the classifier has placed them.
 */
class CookieYesWatchdogService
{
    public function __construct(
        private readonly CookieYesMcpClient $mcp,
        private readonly CookieYesPublishedBanner $published,
    ) {
    }

    public function run(): CookieYesCheckResult
    {
        $problems = [];
        $fine = [];
        $log = [];

        try {
            $websites = [];
            foreach ($this->mcp->callTool('list_domains') as $org) {
                foreach ($org['websites'] ?? [] as $website) {
                    $websites[] = $website;
                }
            }

            if ($websites === []) {
                return new CookieYesCheckResult(false, 'CookieYes account has no websites', []);
            }

            foreach ($websites as $website) {
                $this->checkWebsite($website, $problems, $fine, $log);
            }
        } catch (CookieYesException $e) {
            $log[] = $e->getMessage();

            return new CookieYesCheckResult(false, 'Could not check CookieYes: ' . $e->getMessage(), $log);
        }

        return $problems === []
            ? new CookieYesCheckResult(true, implode('; ', $fine), $log)
            : new CookieYesCheckResult(false, implode('; ', $problems), $log);
    }

    private function checkWebsite(array $website, array &$problems, array &$fine, array &$log): void
    {
        $url = (string) ($website['url'] ?? $website['id']);
        $id = (string) $website['id'];
        $wrong = [];

        if (($website['status'] ?? null) !== 'active') {
            $problems[] = "{$url}: website is " . ($website['status'] ?? 'missing') . ' in CookieYes';

            return;
        }

        $banner = $this->mcp->callTool('get_banner_status', ['websiteId' => $id]);
        $log[] = "{$url}: banner " . json_encode($banner);

        if (! empty($banner['is_banner_manually_disabled'])) {
            $wrong[] = 'banner switched off in CookieYes';
        } elseif (! empty($banner['is_banner_disabled_by_pageview'])) {
            $wrong[] = 'banner disabled by the plan\'s pageview limit';
        } elseif (empty($banner['is_active']) || (int) ($banner['banner_status'] ?? 0) !== 1) {
            $wrong[] = 'banner not live';
        }

        $compliance = $this->mcp->callTool('get_compliance_status', ['websiteId' => $id]);
        $laws = array_map('strtolower', $compliance['applicable_laws'] ?? []);
        $log[] = "{$url}: laws " . implode(', ', $laws) . ', targeting ' . ($compliance['targeted_location'] ?? '?');

        if (! in_array('gdpr', $laws, true)) {
            $wrong[] = 'GDPR not enabled';
        }

        // What the scanner found, and how it categorised it at the time. For the
        // record only: the classifier has since placed what it could.
        $scan = $this->mcp->callTool('get_scan_results', ['websiteId' => $id]);
        $scanCounts = [];
        foreach ($scan['categories'] ?? [] as $category) {
            $scanCounts[$category['name']] = (int) $category['cookie_count'];
        }
        $scanned = (int) ($scan['total_cookies'] ?? array_sum($scanCounts));
        $log[] = "{$url}: scan of " . ($scan['scan_date'] ?? '?') . ' (' . ($scan['scan_status'] ?? '?') . "), {$scanned} cookies on " . ($scan['total_pages'] ?? '?') . ' pages: ' . json_encode($scanCounts);

        // What visitors are shown.
        $published = $this->published->categories($this->scriptUrl($url, $this->mcp->callTool('get_embed_code', ['websiteId' => $id])));
        $publishedCounts = array_map('count', $published);
        $total = array_sum($publishedCounts);
        $uncategorised = $published['other'] ?? [];
        $log[] = "{$url}: banner publishes {$total} cookies: " . json_encode($publishedCounts);

        if ($total === 0) {
            $wrong[] = 'banner publishes no cookies at all';
        } elseif ($uncategorised !== []) {
            $log[] = "{$url}: uncategorised: " . implode(', ', $uncategorised);
            $wrong[] = count($uncategorised) . ' uncategorised ' . (count($uncategorised) === 1 ? 'cookie' : 'cookies') . ' published in the banner (the classifier could not place them; categorise in Cookie Manager)';
        }

        $age = empty($scan['scan_date'])
            ? null
            : (int) Carbon::parse($scan['scan_date'])->startOfDay()->diffInDays(now()->startOfDay());

        if ($age === null || $age > (int) config('freegle.cookieyes.rescan_after_days')) {
            $this->startScan($url, $id, $log);
        }

        if ($age === null) {
            $wrong[] = 'never scanned';
        } elseif ($age > (int) config('freegle.cookieyes.stale_after_days')) {
            $wrong[] = "last scanned {$age} days ago";
        }

        if ($wrong !== []) {
            $problems[] = "{$url}: " . implode(', ', $wrong);
        } else {
            $fine[] = "{$url}: banner live, all {$total} cookies categorised, scanned {$age} " . ($age === 1 ? 'day' : 'days') . ' ago';
        }
    }

    /**
     * The banner script's address, from the embed code CookieYes hands out for
     * the site. It is the one thing in the account that says what is published.
     */
    private function scriptUrl(string $url, array $embed): string
    {
        if (! preg_match('/\bsrc="(https:\/\/[^"]+)"/', (string) ($embed['embed_code'] ?? ''), $match)) {
            throw new CookieYesException("{$url}: the CookieYes embed code has no banner script address: " . json_encode($embed));
        }

        return $match[1];
    }

    /**
     * Ask for a full scan. A refusal (one already running, the plan's monthly
     * allowance used up) is logged rather than failed: if scans really have
     * stopped, the staleness check catches it.
     */
    private function startScan(string $url, string $id, array &$log): void
    {
        try {
            $details = $this->mcp->callTool('get_website_details', ['id' => $id]);
            $pageLimit = (int) ($details['scan_limit'] ?? 0);
            if ($pageLimit < 1) {
                throw new CookieYesException('get_website_details gave no scan_limit');
            }

            $this->mcp->callTool('trigger_cookie_scan', [
                'websiteId' => $id,
                'scanType' => 'normal',
                'pageLimit' => $pageLimit,
            ]);
            $log[] = "{$url}: Started a new scan ({$pageLimit} page limit)";
        } catch (CookieYesAuthException $e) {
            throw $e;
        } catch (CookieYesException $e) {
            $log[] = "{$url}: Could not start a new scan: " . $e->getMessage();
        }
    }
}
