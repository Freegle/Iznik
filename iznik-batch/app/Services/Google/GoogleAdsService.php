<?php

namespace App\Services\Google;

use Illuminate\Support\Facades\Http;

/**
 * Weekly check of the Google Ads (Ad Grants) account, read-only, through the Ads API:
 *
 * - the account itself is enabled (not suspended or cancelled);
 * - no live ad is disapproved or limited by policy, with the policy named;
 * - last month's click-through rate is above the Ad Grants floor (5%; Google
 *   deactivates a grant that stays under it);
 * - no live keyword has a Quality Score of 1 or 2 (also an Ad Grants rule).
 *
 * It never edits an ad or appeals anything. A person fixes what it reports.
 */
class GoogleAdsService
{
    public function __construct(private readonly GoogleAuth $auth)
    {
    }

    public function run(): GoogleCheckResult
    {
        $problems = [];
        $fine = [];
        $log = [];

        try {
            $token = $this->auth->adsToken();

            $this->checkAccount($token, $problems, $fine, $log);
            $this->checkPolicy($token, $problems, $fine, $log);
            $this->checkCtr($token, $problems, $fine, $log);
            $this->checkKeywords($token, $problems, $fine, $log);
        } catch (GoogleApiException $e) {
            $log[] = $e->getMessage();

            return new GoogleCheckResult(false, 'Could not check Google Ads: ' . $e->getMessage(), $log);
        }

        return $problems === []
            ? new GoogleCheckResult(true, implode('; ', $fine), $log)
            : new GoogleCheckResult(false, implode('; ', $problems), $log);
    }

    private function checkAccount(string $token, array &$problems, array &$fine, array &$log): void
    {
        $rows = $this->query($token, 'SELECT customer.id, customer.descriptive_name, customer.status FROM customer');
        $status = $rows[0]['customer']['status'] ?? 'UNKNOWN';
        $log[] = "Account status: {$status}";

        if ($status === 'ENABLED') {
            $fine[] = 'account enabled';
        } else {
            $problems[] = "Google Ads account status is {$status}";
        }
    }

    private function checkPolicy(string $token, array &$problems, array &$fine, array &$log): void
    {
        $rows = $this->query($token,
            'SELECT campaign.name, ad_group_ad.ad.id, ad_group_ad.policy_summary.approval_status, '
            . 'ad_group_ad.policy_summary.policy_topic_entries '
            . "FROM ad_group_ad WHERE ad_group_ad.status != 'REMOVED' AND campaign.status = 'ENABLED' "
            . "AND ad_group.status = 'ENABLED' "
            . "AND ad_group_ad.policy_summary.approval_status IN ('DISAPPROVED', 'AREA_OF_INTEREST_ONLY')");

        $by = [];
        foreach ($rows as $row) {
            $summary = $row['adGroupAd']['policySummary'] ?? [];
            $topics = [];
            foreach ($summary['policyTopicEntries'] ?? [] as $entry) {
                $topics[] = ($entry['topic'] ?? 'unknown policy') . ' (' . ($entry['type'] ?? '?') . ')';
            }
            $key = ($summary['approvalStatus'] ?? '?') . ' by ' . ($topics ? implode(' + ', $topics) : 'unknown policy')
                . ' in campaign ' . ($row['campaign']['name'] ?? '?');
            $by[$key] = ($by[$key] ?? 0) + 1;
            $log[] = 'Ad ' . ($row['adGroupAd']['ad']['id'] ?? '?') . ': ' . $key;
        }

        if ($by === []) {
            $fine[] = 'no ads disapproved';

            return;
        }

        foreach ($by as $what => $n) {
            $problems[] = "{$n} ad(s) {$what}. Fix the ad or its policy certificate in Policy manager";
        }
    }

    private function checkCtr(string $token, array &$problems, array &$fine, array &$log): void
    {
        $min = (float) config('freegle.google.ads.min_ctr');
        if ($min <= 0) {
            return;
        }

        $rows = $this->query($token, 'SELECT metrics.impressions, metrics.clicks, metrics.ctr FROM customer WHERE segments.date DURING LAST_MONTH');
        $impressions = (int) ($rows[0]['metrics']['impressions'] ?? 0);
        $ctr = (float) ($rows[0]['metrics']['ctr'] ?? 0);
        $log[] = sprintf('Last month: %d impressions, CTR %.2f%%', $impressions, $ctr * 100);

        if ($impressions === 0) {
            $problems[] = 'No impressions last month. Ads are not running';
        } elseif ($ctr < $min) {
            $problems[] = sprintf('Last month CTR %.1f%% is under the Ad Grants floor of %.0f%%', $ctr * 100, $min * 100);
        } else {
            $fine[] = sprintf('CTR %.1f%% last month', $ctr * 100);
        }
    }

    private function checkKeywords(string $token, array &$problems, array &$fine, array &$log): void
    {
        $rows = $this->query($token,
            'SELECT ad_group_criterion.keyword.text, ad_group_criterion.quality_info.quality_score FROM keyword_view '
            . "WHERE ad_group_criterion.status = 'ENABLED' AND campaign.status = 'ENABLED' "
            . 'AND ad_group_criterion.quality_info.quality_score <= 2');

        foreach ($rows as $row) {
            $log[] = 'Low Quality Score keyword: ' . ($row['adGroupCriterion']['keyword']['text'] ?? '?')
                . ' (' . ($row['adGroupCriterion']['qualityInfo']['qualityScore'] ?? '?') . ')';
        }

        if ($rows === []) {
            $fine[] = 'no keywords with Quality Score 1 or 2';
        } else {
            $problems[] = count($rows) . ' keyword(s) have Quality Score 1 or 2, which Ad Grants does not allow. Pause or improve them';
        }
    }

    /**
     * Run one GAQL query and return every row.
     *
     * @return list<array<string,mixed>>
     */
    private function query(string $token, string $gaql): array
    {
        $customer = preg_replace('/\D/', '', (string) config('freegle.google.ads.customer_id'));
        $version = (string) config('freegle.google.ads.api_version');
        $url = "https://googleads.googleapis.com/{$version}/customers/{$customer}/googleAds:search";

        $headers = ['developer-token' => (string) config('freegle.google.ads.developer_token')];
        $login = preg_replace('/\D/', '', (string) config('freegle.google.ads.login_customer_id'));
        if ($login !== '') {
            $headers['login-customer-id'] = $login;
        }

        $rows = [];
        $page = null;
        for ($i = 0; $i < 10; $i++) {
            $body = ['query' => $gaql];
            if ($page !== null) {
                $body['pageToken'] = $page;
            }

            $rsp = Http::withToken($token)->withHeaders($headers)->timeout(60)->post($url, $body);
            if (! $rsp->successful()) {
                $why = $rsp->json('error.message') ?? ('HTTP ' . $rsp->status());

                throw new GoogleApiException("Google Ads refused a query: {$why}");
            }

            $rows = array_merge($rows, $rsp->json('results') ?? []);
            $page = $rsp->json('nextPageToken');
            if (empty($page)) {
                break;
            }
        }

        return $rows;
    }
}
