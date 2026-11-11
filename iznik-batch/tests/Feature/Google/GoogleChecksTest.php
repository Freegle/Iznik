<?php

namespace Tests\Feature\Google;

use App\Mail\Housekeeper\HousekeeperResultsMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class GoogleChecksTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config([
            'freegle.mail.geeks_addr' => 'geeks@example.org',
            'freegle.google.service_account_b64' => '',
            'freegle.google.service_account_file' => '',
            'freegle.google.search_console.sites' => '',
            'freegle.google.search_console.inspect_posts' => 2,
            'freegle.google.ads.developer_token' => '',
            'freegle.google.ads.customer_id' => '',
            'freegle.google.ads.client_id' => '',
            'freegle.google.ads.client_secret' => '',
            'freegle.google.ads.refresh_token' => '',
            'freegle.google.ads.login_customer_id' => '',
        ]);
        DB::table('housekeeper_tasks')->whereIn('task_key', ['google-search-console', 'google-ads'])->delete();
    }

    private function serviceAccount(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        config(['freegle.google.service_account_b64' => base64_encode(json_encode([
            'client_email' => 'svc@example.iam.gserviceaccount.com',
            'private_key' => $pem,
        ]))]);
    }

    private function ads(): void
    {
        config([
            'freegle.google.ads.developer_token' => 'dev',
            'freegle.google.ads.customer_id' => '123-456-7890',
            'freegle.google.ads.client_id' => 'cid',
            'freegle.google.ads.client_secret' => 'secret',
            'freegle.google.ads.refresh_token' => 'refresh',
            'freegle.google.ads.api_version' => 'v26',
        ]);
    }

    private function row(string $key): object
    {
        return DB::table('housekeeper_tasks')->where('task_key', $key)->first();
    }

    public function test_search_console_without_credentials_says_what_is_missing(): void
    {
        Http::fake();

        $this->artisan('google:search-console-check')->assertSuccessful();

        $row = $this->row('google-search-console');
        $this->assertSame('failure', $row->last_status);
        $this->assertStringContainsString('GOOGLE_SERVICE_ACCOUNT_B64', $row->last_summary);
        Http::assertNothingSent();
        Mail::assertSent(HousekeeperResultsMail::class, fn ($m) => $m->hasTo('geeks@example.org') && $m->task === 'google-search-console');
    }

    public function test_ads_without_credentials_names_every_missing_setting_but_no_values(): void
    {
        Http::fake();
        config(['freegle.google.ads.client_id' => 'cid']);

        $this->artisan('google:ads-check')->assertSuccessful();

        $summary = $this->row('google-ads')->last_summary;
        foreach (['GOOGLE_ADS_CUSTOMER_ID', 'GOOGLE_ADS_CLIENT_SECRET', 'GOOGLE_ADS_REFRESH_TOKEN'] as $name) {
            $this->assertStringContainsString($name, $summary);
        }
        $this->assertStringNotContainsString('GOOGLE_ADS_CLIENT_ID', $summary);
        // Optional: a Cloud project with Ads API access needs no developer token.
        $this->assertStringNotContainsString('GOOGLE_ADS_DEVELOPER_TOKEN', $summary);
        Http::assertNothingSent();
    }

    public function test_search_console_clean_run_passes_and_inspects_samples(): void
    {
        $this->serviceAccount();
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok']),
            'www.googleapis.com/webmasters/v3/sites' => Http::response(['siteEntry' => [
                ['siteUrl' => 'sc-domain:ilovefreegle.org', 'permissionLevel' => 'siteOwner'],
                ['siteUrl' => 'sc-domain:other.org', 'permissionLevel' => 'siteUnverifiedUser'],
            ]]),
            'www.googleapis.com/webmasters/v3/sites/sc-domain%3Ailovefreegle.org/sitemaps' => Http::response(['sitemap' => [[
                'path' => 'https://www.ilovefreegle.org/sitemap.xml',
                'errors' => '0', 'warnings' => '0', 'isPending' => false,
                'lastDownloaded' => now()->subDay()->toIso8601String(),
            ]]]),
            'www.ilovefreegle.org/sitemap.xml' => Http::response('<sitemapindex><sitemap><loc>https://www.ilovefreegle.org/sitemap-pages.xml</loc></sitemap><sitemap><loc>https://www.ilovefreegle.org/sitemap-posts/0</loc></sitemap></sitemapindex>'),
            'www.ilovefreegle.org/sitemap-posts/0' => Http::response('<urlset><url><loc>https://www.ilovefreegle.org/message/1</loc></url><url><loc>https://www.ilovefreegle.org/message/2</loc></url></urlset>'),
            'searchconsole.googleapis.com/*' => Http::response(['inspectionResult' => ['indexStatusResult' => [
                'verdict' => 'PASS', 'coverageState' => 'Submitted and indexed', 'pageFetchState' => 'SUCCESSFUL',
            ]]]),
        ]);

        $this->artisan('google:search-console-check')->assertSuccessful();

        $row = $this->row('google-search-console');
        $this->assertSame('success', $row->last_status);
        $this->assertStringContainsString('1 sitemap(s) read, no errors', $row->last_summary);
        Http::assertSentCount(8);
        Mail::assertNothingSent();
    }

    public function test_search_console_reports_sitemap_errors_stale_reads_and_unfetchable_pages(): void
    {
        $this->serviceAccount();
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok']),
            'www.googleapis.com/webmasters/v3/sites' => Http::response(['siteEntry' => [
                ['siteUrl' => 'https://www.ilovefreegle.org/', 'permissionLevel' => 'siteFullUser'],
            ]]),
            'www.googleapis.com/webmasters/v3/sites/*/sitemaps' => Http::response(['sitemap' => [[
                'path' => 'https://www.ilovefreegle.org/sitemap.xml',
                'errors' => '2', 'warnings' => '1', 'isPending' => false,
                'lastDownloaded' => now()->subDays(30)->toIso8601String(),
            ]]]),
            'www.ilovefreegle.org/sitemap.xml' => Http::response('', 500),
            'searchconsole.googleapis.com/*' => Http::response(['inspectionResult' => ['indexStatusResult' => [
                'verdict' => 'FAIL', 'pageFetchState' => 'SERVER_ERROR',
            ]]]),
        ]);

        $this->artisan('google:search-console-check')->assertSuccessful();

        $row = $this->row('google-search-console');
        $this->assertSame('failure', $row->last_status);
        $this->assertStringContainsString('has 2 error(s)', $row->last_summary);
        $this->assertStringContainsString('has 1 warning(s)', $row->last_summary);
        $this->assertStringContainsString('last read 30 days ago', $row->last_summary);
        $this->assertStringContainsString('fetch state SERVER_ERROR', $row->last_summary);
        Mail::assertSent(HousekeeperResultsMail::class);
    }

    public function test_search_console_flags_a_missing_sitemap_and_can_submit_it(): void
    {
        $this->serviceAccount();
        config(['freegle.google.search_console.inspect_posts' => 0]);
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok']),
            'www.googleapis.com/webmasters/v3/sites' => Http::response(['siteEntry' => [
                ['siteUrl' => 'sc-domain:ilovefreegle.org', 'permissionLevel' => 'siteOwner'],
            ]]),
            'www.googleapis.com/webmasters/v3/sites/*/sitemaps' => Http::response([]),
            'www.googleapis.com/webmasters/v3/sites/*/sitemaps/*' => Http::response('', 204),
        ]);

        $this->artisan('google:search-console-check')->assertSuccessful();
        $this->assertStringContainsString('is not submitted', $this->row('google-search-console')->last_summary);
        Http::assertNotSent(fn ($r) => $r->method() === 'PUT');

        $this->artisan('google:search-console-check --submit-missing')->assertSuccessful();
        $this->assertSame('success', $this->row('google-search-console')->last_status);
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && str_contains($r->url(), 'sitemaps/https%3A%2F%2Fwww.ilovefreegle.org%2Fsitemap.xml'));
    }

    public function test_search_console_api_refusal_is_a_readable_failure(): void
    {
        $this->serviceAccount();
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok']),
            'www.googleapis.com/webmasters/v3/sites' => Http::response(['error' => ['message' => 'API has not been used in project']], 403),
        ]);

        $this->artisan('google:search-console-check')->assertSuccessful();

        $this->assertStringContainsString('API has not been used in project', $this->row('google-search-console')->last_summary);
    }

    public function test_a_bad_service_account_key_is_reported(): void
    {
        config(['freegle.google.service_account_b64' => base64_encode('not json')]);

        $this->artisan('google:search-console-check')->assertSuccessful();

        $this->assertStringContainsString('not valid JSON', $this->row('google-search-console')->last_summary);
    }

    private function adsFake(array $overrides = []): void
    {
        Http::fake(array_merge([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok']),
            'googleads.googleapis.com/*' => Http::sequence()
                ->push(['results' => [['customer' => ['status' => 'ENABLED']]]])
                ->push(['results' => []])
                ->push(['results' => []])
                ->push(['results' => []])
                ->push(['results' => []])
                ->push(['results' => [['metrics' => ['impressions' => '1000', 'clicks' => '80', 'ctr' => 0.08]]]])
                ->push(['results' => []]),
        ], $overrides));
    }

    public function test_ads_clean_run_passes_and_sends_the_right_headers(): void
    {
        $this->ads();
        config(['freegle.google.ads.login_customer_id' => '999-888-7777']);
        $this->adsFake();

        $this->artisan('google:ads-check')->assertSuccessful();

        $this->assertSame('success', $this->row('google-ads')->last_status);
        $this->assertStringContainsString('CTR 8.0% last month', $this->row('google-ads')->last_summary);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/v26/customers/1234567890/googleAds:search')
            && $r->hasHeader('developer-token', 'dev')
            && $r->hasHeader('login-customer-id', '9998887777'));
        Mail::assertNothingSent();
    }

    public function test_ads_runs_without_a_developer_token_and_sends_none(): void
    {
        $this->ads();
        config(['freegle.google.ads.developer_token' => '']);
        $this->adsFake();

        $this->artisan('google:ads-check')->assertSuccessful();

        $this->assertSame('success', $this->row('google-ads')->last_status);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'googleads.googleapis.com/v26/'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'googleads.googleapis.com')
            && $r->hasHeader('developer-token'));
        Http::assertNotSent(fn ($r) => $r->hasHeader('login-customer-id'));
    }

    public function test_the_default_api_version_is_one_google_still_serves(): void
    {
        // v21 was already gone (404) and v22 refused as deprecated on 2026-10-24.
        $this->assertSame('v26', config('freegle.google.ads.api_version'));
    }

    public function test_ads_reports_disapproval_suspension_low_ctr_and_low_quality_keywords(): void
    {
        $this->ads();
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok']),
            'googleads.googleapis.com/*' => Http::sequence()
                ->push(['results' => [['customer' => ['status' => 'SUSPENDED']]]])
                ->push(['results' => [[
                    'campaign' => ['name' => 'Brand'],
                    'adGroupAd' => [
                        'ad' => ['id' => '5'],
                        'policySummary' => [
                            'approvalStatus' => 'DISAPPROVED',
                            'policyTopicEntries' => [['topic' => 'DATING_AND_COMPANIONSHIP', 'type' => 'LIMITED']],
                        ],
                    ],
                ]]])
                ->push(['results' => []])
                ->push(['results' => []])
                ->push(['results' => []])
                ->push(['results' => [['metrics' => ['impressions' => '1000', 'clicks' => '10', 'ctr' => 0.01]]]])
                ->push(['results' => [['adGroupCriterion' => ['keyword' => ['text' => 'free stuff'], 'qualityInfo' => ['qualityScore' => 2]]]]]),
        ]);

        $this->artisan('google:ads-check')->assertSuccessful();

        $summary = $this->row('google-ads')->last_summary;
        $this->assertSame('failure', $this->row('google-ads')->last_status);
        $this->assertStringContainsString('status is SUSPENDED', $summary);
        $this->assertStringContainsString('DISAPPROVED by DATING_AND_COMPANIONSHIP (LIMITED) in campaign Brand', $summary);
        $this->assertStringContainsString('under the Ad Grants floor', $summary);
        $this->assertStringContainsString('1 keyword(s) have Quality Score 1 or 2', $summary);
        Mail::assertSent(HousekeeperResultsMail::class, fn ($m) => $m->task === 'google-ads');
    }

    public function test_ads_reports_a_disapproved_asset_even_when_every_ad_is_approved(): void
    {
        // The shape Google returned on 2026-10-24: the logo approved as the business logo but
        // disapproved as an ad image, in two campaigns, while every ad read APPROVED.
        $logo = fn (string $campaign) => [
            'campaign' => ['name' => $campaign, 'status' => 'ENABLED'],
            'campaignAsset' => ['fieldType' => 'AD_IMAGE', 'primaryStatusReasons' => ['ASSET_DISAPPROVED']],
            'asset' => [
                'id' => '89216239329',
                'name' => 'FreegleLogo.png',
                'fieldTypePolicySummaries' => [
                    ['assetFieldType' => 'BUSINESS_LOGO', 'policySummaryInfo' => ['approvalStatus' => 'APPROVED']],
                    ['assetFieldType' => 'AD_IMAGE', 'policySummaryInfo' => [
                        'approvalStatus' => 'DISAPPROVED',
                        'policyTopicEntries' => [['topic' => 'IMAGE_EXTENSIONS_TEXT_OR_GRAPHIC_OVERLAY', 'type' => 'PROHIBITED']],
                    ]],
                ],
            ],
        ];
        // Not eligible only because it awaits review: not a policy problem.
        $pending = [
            'customerAsset' => ['fieldType' => 'SITELINK'],
            'asset' => ['id' => '7', 'name' => 'New sitelink', 'fieldTypePolicySummaries' => [
                ['assetFieldType' => 'SITELINK', 'policySummaryInfo' => ['approvalStatus' => 'UNKNOWN']],
            ]],
        ];
        $this->ads();
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok']),
            'googleads.googleapis.com/*' => Http::sequence()
                ->push(['results' => [['customer' => ['status' => 'ENABLED']]]])
                ->push(['results' => []])
                ->push(['results' => [$logo('Generic'), $logo('Brand')]])
                ->push(['results' => []])
                ->push(['results' => [$pending]])
                ->push(['results' => [['metrics' => ['impressions' => '1000', 'clicks' => '80', 'ctr' => 0.08]]]])
                ->push(['results' => []]),
        ]);

        $this->artisan('google:ads-check')->assertSuccessful();

        $summary = $this->row('google-ads')->last_summary;
        $this->assertSame('failure', $this->row('google-ads')->last_status);
        $this->assertStringContainsString(
            'FreegleLogo.png as AD_IMAGE, disapproved by IMAGE_EXTENSIONS_TEXT_OR_GRAPHIC_OVERLAY (PROHIBITED), in Generic, Brand',
            $summary
        );
        $this->assertStringNotContainsString('New sitelink', $summary);
        $this->assertStringNotContainsString('BUSINESS_LOGO', $summary);
        Http::assertSent(fn ($r) => str_contains((string) $r->body(), 'FROM campaign_asset'));
        Http::assertSent(fn ($r) => str_contains((string) $r->body(), 'FROM ad_group_asset'));
        Http::assertSent(fn ($r) => str_contains((string) $r->body(), 'FROM customer_asset'));
    }

    public function test_ads_reports_api_errors(): void
    {
        $this->ads();
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok']),
            'googleads.googleapis.com/*' => Http::response(['error' => ['message' => 'The developer token is not approved']], 403),
        ]);

        $this->artisan('google:ads-check')->assertSuccessful();

        $this->assertStringContainsString('The developer token is not approved', $this->row('google-ads')->last_summary);
    }

    public function test_ads_token_refusal_is_reported(): void
    {
        $this->ads();
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'], 400)]);

        $this->artisan('google:ads-check')->assertSuccessful();

        $this->assertStringContainsString('Token has been expired or revoked.', $this->row('google-ads')->last_summary);
    }

    public function test_both_checks_are_scheduled_weekly(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->filter(fn ($e) => str_contains($e->command, 'google:'));

        $this->assertCount(2, $events);
        $this->assertEqualsCanonicalizing(['40 10 * * 1', '50 10 * * 1'], $events->map(fn ($e) => $e->expression)->values()->all());
    }
}
