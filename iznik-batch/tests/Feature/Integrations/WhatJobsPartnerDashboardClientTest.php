<?php

namespace Tests\Feature\Integrations;

use App\Services\WhatJobs\PartnerDashboardClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class WhatJobsPartnerDashboardClientTest extends TestCase
{
    /** A month page shaped like the dashboard's: a header row, a row a day, a total row. */
    public static function page(array $days): string
    {
        $rows = '';
        foreach ($days as $date => $c) {
            $rows .= "<tr class=\"text-center\"><td>{$date}</td><td>{$c[0]}</td><td>{$c[1]}</td><td>{$c[2]}</td><td>{$c[3]}</td><td>{$c[4]}</td><td>{$c[5]}</td></tr>\n";
        }

        return '<html><body><div class="panel-body"><table class="table table-striped" id="resultTable"><thead><tr class="text-center">'
            .'<th>Date</th><th>Billable Clicks</th><th>Duplicate Clicks</th><th>Invalid Clicks</th><th>Foreign Clicks</th><th>Latent Clicks</th><th>Applications</th>'
            ."</tr></thead>\n{$rows}<tr><td>Total</td><td>9,999</td><td>0</td><td>0</td><td>0</td><td>0</td><td>0</td></tr></table></div></body></html>";
    }

    private function client(): PartnerDashboardClient
    {
        return new PartnerDashboardClient('https://partner.example', 'Freegle', 'secret');
    }

    private function fakeLogin(array $loginJson = ['done' => true, 'success' => true], ?callable $month = null): void
    {
        Http::fake(function (Request $request) use ($loginJson, $month) {
            if (str_contains($request->url(), '/partner/login/do.ajax')) {
                return Http::response($loginJson);
            }
            if (str_contains($request->url(), '/outbound/partner/usage/')) {
                return $month ? $month($request) : Http::response(self::page(['2026-11-01' => [197, 4, 102, 3, 3, 0]]));
            }

            return Http::response('<html>login</html>', 200, ['Set-Cookie' => 'sid=abc; path=/']);
        });
    }

    public function test_reads_each_day_of_a_month_and_skips_the_total(): void
    {
        $this->fakeLogin(month: fn () => Http::response(self::page([
            '2026-11-01' => [197, 4, 102, 3, 3, 0],
            '2026-11-02' => ['1,163', 11, 94, 5, 1, 1],
        ])));

        $days = $this->client()->month('2026-11');

        $this->assertSame(['2026-11-01', '2026-11-02'], array_keys($days));
        $this->assertSame([
            'billable_clicks' => 197, 'duplicate_clicks' => 4, 'invalid_clicks' => 102,
            'foreign_clicks' => 3, 'latent_clicks' => 3, 'applications' => 0,
        ], $days['2026-11-01']);
        $this->assertSame(1163, $days['2026-11-02']['billable_clicks']);
    }

    public function test_logs_in_with_a_browser_shaped_agent_and_asks_for_active_feeds(): void
    {
        $this->fakeLogin();

        $this->client()->month('2026-11');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/partner/login/do.ajax')
            && $r['username'] === 'Freegle' && $r['password'] === 'secret'
            && str_starts_with($r->header('User-Agent')[0] ?? '', 'Mozilla/5.0 (compatible; FreegleBatch'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/outbound/partner/usage/2026-11-new') && str_contains($r->url(), 'status=1'));
    }

    public function test_a_refused_login_says_why(): void
    {
        $this->fakeLogin(['done' => false, 'errorMessage' => 'Incorrect credentials', 'success' => true]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Incorrect credentials');
        $this->client()->month('2026-11');
    }

    public function test_a_page_sent_back_to_the_login_fails(): void
    {
        $this->fakeLogin(month: fn () => Http::response('', 302, ['Location' => 'https://partner.example/partner/index']));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('back to the login');
        $this->client()->month('2026-11');
    }

    public function test_a_page_without_the_table_fails_rather_than_reading_as_no_clicks(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the page has changed');
        PartnerDashboardClient::parseMonth('<html><body><table id="other"><tr><th>Date</th></tr></table></body></html>');
    }

    public function test_from_config_needs_both_username_and_password(): void
    {
        config(['freegle.whatjobs.partner_username' => 'Freegle', 'freegle.whatjobs.partner_password' => '']);
        $this->assertNull(PartnerDashboardClient::fromConfig());

        config(['freegle.whatjobs.partner_password' => 'secret']);
        $this->assertInstanceOf(PartnerDashboardClient::class, PartnerDashboardClient::fromConfig());
    }
}
