<?php

namespace Tests\Feature\Integrations;

use App\Services\WhatJobs\ClickMonitorService;
use App\Services\WhatJobs\ClickTrendChart;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * integrations:whatjobs-clicks and integrations:whatjobs-clicks-weekly. "Today" is Thursday
 * 26 November 2026, so the newest day WhatJobs has finalised is Tuesday 24 November.
 */
class WhatJobsClicksCommandTest extends TestCase
{
    private const TABLE = 'whatjobs_clicks_daily';

    /** @var array<string, array<int, int>> date => dashboard row */
    private array $dashboard = [];

    private bool $loginRefused = false;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-26 07:10:00');
        config([
            'freegle.whatjobs.partner_url' => 'https://partner.example',
            'freegle.whatjobs.partner_username' => 'Freegle',
            'freegle.whatjobs.partner_password' => 'secret',
            'freegle.whatjobs.clicks_report_to' => 'cto-test@example.org',
            'mail.default' => 'array',
        ]);
        Mail::purge('array');
        DB::table(self::TABLE)->delete();
        DB::table('logs_jobs')->where('timestamp', '>=', '2026-09-01')->delete();
        DB::table('users_active')->where('timestamp', '>=', '2026-09-01')->delete();

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/partner/login/do.ajax')) {
                return Http::response($this->loginRefused
                    ? ['done' => false, 'errorMessage' => 'Incorrect credentials']
                    : ['done' => true]);
            }
            if (preg_match('#/usage/(\d{4}-\d{2})-new#', $request->url(), $m)) {
                $month = array_filter($this->dashboard, fn ($d) => str_starts_with($d, $m[1]), ARRAY_FILTER_USE_KEY);

                return Http::response(WhatJobsPartnerDashboardClientTest::page($month));
            }

            return Http::response('<html></html>');
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function mails(): array
    {
        return Mail::mailer('array')->getSymfonyTransport()->messages()->all();
    }

    /** Billable clicks for each day from $from to $to on the dashboard. */
    private function billable(string $from, string $to, int $billable): void
    {
        for ($d = CarbonImmutable::parse($from); $d->lte(CarbonImmutable::parse($to)); $d = $d->addDay()) {
            $this->dashboard[$d->toDateString()] = [$billable, 5, 80, 4, 3, 0];
        }
    }

    /** $n job ad clicks in our own log on $date. */
    private function ourClicks(string $date, int $n): void
    {
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $rows[] = ['link' => 'https://jobs.example/'.$i, 'timestamp' => "{$date} 10:00:00", 'source' => 'website', 'placement' => 'jobspage'];
        }
        DB::table('logs_jobs')->insert($rows);
    }

    /** $users active members on $date. */
    private function activeMembers(string $date, array $users): void
    {
        foreach ($users as $user) {
            DB::table('users_active')->insert(['userid' => $user->id, 'timestamp' => "{$date} 09:00:00"]);
        }
    }

    /** A stored history row, as an earlier run would have left it. */
    private function storedDay(string $date, array $values): void
    {
        DB::table(self::TABLE)->insert($values + ['date' => $date, 'judged_at' => now()]);
    }

    private function row(string $date): object
    {
        return DB::table(self::TABLE)->where('date', $date)->first();
    }

    public function test_without_a_login_there_is_nothing_to_read(): void
    {
        config(['freegle.whatjobs.partner_password' => '']);

        $this->artisan('integrations:whatjobs-clicks')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, DB::table(self::TABLE)->count());
    }

    public function test_stores_each_day_and_keeps_the_billable_figure_first_seen(): void
    {
        $this->billable('2026-10-01', '2026-11-25', 160);

        $this->artisan('integrations:whatjobs-clicks')->assertSuccessful();
        $this->assertSame(160, (int) $this->row('2026-11-24')->billable_clicks);
        $this->assertSame(80, (int) $this->row('2026-11-24')->invalid_clicks);

        // WhatJobs revise the day.
        $this->dashboard['2026-11-24'][0] = 171;
        $this->artisan('integrations:whatjobs-clicks')->assertSuccessful();

        $this->assertSame(171, (int) $this->row('2026-11-24')->billable_clicks);
        $this->assertSame(160, (int) $this->row('2026-11-24')->first_billable_clicks);
        $this->assertCount(0, $this->mails());
    }

    public function test_records_our_own_clicks_and_active_members(): void
    {
        $this->billable('2026-10-01', '2026-11-25', 160);
        $this->ourClicks('2026-11-24', 3);
        $this->activeMembers('2026-11-24', [$this->createTestUser(), $this->createTestUser()]);

        $this->artisan('integrations:whatjobs-clicks')->assertSuccessful();

        $this->assertSame(3, (int) $this->row('2026-11-24')->our_clicks);
        $this->assertSame(2, (int) $this->row('2026-11-24')->active_users);
        // Today is not over, so it is not recorded.
        $this->assertNull(DB::table(self::TABLE)->where('date', '2026-11-26')->first());
    }

    public function test_a_sudden_drop_is_mailed_once(): void
    {
        $this->billable('2026-10-01', '2026-11-25', 160);
        $this->dashboard['2026-11-24'][0] = 60;

        $this->artisan('integrations:whatjobs-clicks')->assertSuccessful();

        $mails = $this->mails();
        $this->assertCount(1, $mails);
        $this->assertStringContainsString('sudden drop on Tuesday 24 November', $mails[0]->getOriginalMessage()->getSubject());
        $this->assertSame('cto-test@example.org', $mails[0]->getOriginalMessage()->getTo()[0]->getAddress());
        $this->assertStringContainsString('60 billable clicks, against 160 on a usual day lately (down 63%)', $mails[0]->getOriginalMessage()->getTextBody());
        $this->assertSame('day-drop', $this->row('2026-11-24')->alert);
        // Not yet final, so not judged.
        $this->assertNull($this->row('2026-11-25')->judged_at);

        Mail::purge('array');
        $this->artisan('integrations:whatjobs-clicks')->assertSuccessful();
        $this->assertCount(0, $this->mails());
    }

    public function test_a_sudden_rise_is_mailed(): void
    {
        $this->billable('2026-10-01', '2026-11-25', 160);
        $this->dashboard['2026-11-24'][0] = 300;

        $this->artisan('integrations:whatjobs-clicks')->assertSuccessful();

        $this->assertStringContainsString('sudden rise', $this->mails()[0]->getOriginalMessage()->getSubject());
    }

    public function test_an_ordinary_wobble_is_not_mailed(): void
    {
        $this->billable('2026-10-01', '2026-11-25', 160);
        $this->dashboard['2026-11-24'][0] = 110;
        $this->dashboard['2026-11-23'][0] = 230;

        $this->artisan('integrations:whatjobs-clicks')->assertSuccessful();

        $this->assertCount(0, $this->mails());
    }

    public function test_filling_in_history_judges_old_days_silently(): void
    {
        $this->billable('2026-09-01', '2026-11-25', 160);
        $this->dashboard['2026-10-10'][0] = 1;

        $this->artisan('integrations:whatjobs-clicks', ['--months' => 3])->assertSuccessful();

        $this->assertCount(0, $this->mails());
        $this->assertNotNull($this->row('2026-10-10')->judged_at);
        $this->assertNull($this->row('2026-10-10')->alert);
        $this->assertNotNull($this->row('2026-09-01'));
    }

    public function test_a_quieter_site_excuses_a_drop(): void
    {
        $this->billable('2026-10-01', '2026-11-25', 160);
        $this->dashboard['2026-11-24'][0] = 64;
        // Ten active members a day as usual, four on the day itself.
        for ($d = CarbonImmutable::parse('2026-10-01'); $d->lte(CarbonImmutable::parse('2026-11-18')); $d = $d->addDay()) {
            $this->storedDay($d->toDateString(), ['active_users' => 10]);
        }
        $users = [];
        for ($i = 0; $i < 10; $i++) {
            $users[] = $this->createTestUser();
        }
        for ($d = CarbonImmutable::parse('2026-11-19'); $d->lte(CarbonImmutable::parse('2026-11-25')); $d = $d->addDay()) {
            $this->activeMembers($d->toDateString(), $d->toDateString() === '2026-11-24' ? array_slice($users, 0, 4) : $users);
        }

        $this->artisan('integrations:whatjobs-clicks')->assertSuccessful();

        $this->assertCount(0, $this->mails());
        $this->assertNull($this->row('2026-11-24')->alert);
    }

    public function test_whatjobs_billing_a_smaller_share_of_our_clicks_is_put_down_to_their_side(): void
    {
        // We log 30 clicks a day and WhatJobs bill 20; on the 24th they bill 12 of the 30.
        $this->billable('2026-10-01', '2026-11-25', 20);
        $this->dashboard['2026-11-24'][0] = 12;
        for ($d = CarbonImmutable::parse('2026-10-28'); $d->lte(CarbonImmutable::parse('2026-11-18')); $d = $d->addDay()) {
            $this->storedDay($d->toDateString(), ['our_clicks' => 30, 'active_users' => 10]);
        }
        $users = [];
        for ($i = 0; $i < 10; $i++) {
            $users[] = $this->createTestUser();
        }
        for ($d = CarbonImmutable::parse('2026-11-19'); $d->lte(CarbonImmutable::parse('2026-11-25')); $d = $d->addDay()) {
            $this->ourClicks($d->toDateString(), 30);
            $this->activeMembers($d->toDateString(), $users);
        }

        $this->artisan('integrations:whatjobs-clicks')->assertSuccessful();

        $this->assertSame('share-drop', $this->row('2026-11-24')->alert);
        $text = $this->mails()[0]->getOriginalMessage()->getTextBody();
        $this->assertStringContainsString('WhatJobs billed 40% of them, usually 67%.', $text);
        $this->assertStringContainsString('That is on their side', $text);
    }

    public function test_fewer_clicks_in_our_own_log_on_a_normal_day_points_at_our_side(): void
    {
        $f = [
            'date' => '2026-11-24', 'codes' => ['day-drop'], 'billable' => 60, 'usual' => 160.0,
            'our' => 90, 'ourUsual' => 250.0, 'active' => 4000, 'activeUsual' => 4050.0,
            'share' => 0.67, 'shareUsual' => 0.64,
            'discarded' => ['invalid' => 30, 'duplicate' => 2, 'foreign' => 1, 'latent' => 0], 'week' => null,
        ];

        $text = app(ClickMonitorService::class)->describe($f);

        $this->assertStringContainsString('points at our side', $text['reading']);
        $this->assertContains('Job ad clicks in our own log: 90, usually 250 (down 64%).', $text['lines']);
    }

    public function test_a_sustained_drop_is_mailed_on_the_day_it_crosses_and_not_again(): void
    {
        // 160 a day, then 100 a day from the 18th: never half, so no single day is flagged,
        // but the seven days to the 22nd are 0.73 of a usual week.
        $this->billable('2026-10-01', '2026-11-25', 160);
        $this->billable('2026-11-18', '2026-11-25', 100);

        $this->artisan('integrations:whatjobs-clicks')->assertSuccessful();

        $this->assertSame('week-drop', $this->row('2026-11-22')->alert);
        $this->assertNull($this->row('2026-11-23')->alert);
        $this->assertNull($this->row('2026-11-24')->alert);
        $mails = $this->mails();
        $this->assertCount(1, $mails);
        $this->assertStringContainsString('a week-long drop to Sunday 22 November', $mails[0]->getOriginalMessage()->getSubject());
    }

    public function test_the_alert_carries_the_chart_in_the_message(): void
    {
        $this->billable('2026-10-01', '2026-11-25', 160);
        $this->dashboard['2026-11-24'][0] = 60;

        $this->artisan('integrations:whatjobs-clicks')->assertSuccessful();

        $message = $this->mails()[0]->getOriginalMessage();
        $this->assertStringContainsString('src="cid:', $message->getHtmlBody());
        $parts = array_filter($message->getAttachments(), fn ($a) => $a->getMediaSubtype() === 'png');
        $this->assertCount(1, $parts);
        $this->assertStringStartsWith("\x89PNG", array_values($parts)[0]->getBody());
    }

    public function test_a_refused_login_fails_but_still_records_our_side(): void
    {
        $this->loginRefused = true;
        $this->ourClicks('2026-11-25', 2);

        $this->artisan('integrations:whatjobs-clicks')
            ->expectsOutputToContain('Incorrect credentials')
            ->assertFailed();

        $this->assertSame(2, (int) $this->row('2026-11-25')->our_clicks);
    }

    public function test_figures_missing_for_a_finalised_day_fail(): void
    {
        $this->billable('2026-10-01', '2026-11-21', 160);

        $this->artisan('integrations:whatjobs-clicks')
            ->expectsOutputToContain('have not published figures for 2026-11-24')
            ->assertFailed();
    }

    public function test_the_weekly_summary_covers_the_last_finalised_monday_to_sunday(): void
    {
        Carbon::setTestNow('2026-11-24 07:40:00');
        for ($d = CarbonImmutable::parse('2026-06-01'); $d->lte(CarbonImmutable::parse('2026-11-23')); $d = $d->addDay()) {
            $old = $d->lt(CarbonImmutable::parse('2026-08-31'));
            $this->storedDay($d->toDateString(), [
                'billable_clicks' => $old ? 200 : 160, 'first_billable_clicks' => $old ? 200 : 160,
                'invalid_clicks' => 80, 'duplicate_clicks' => 5, 'foreign_clicks' => 3, 'latent_clicks' => 2,
                'our_clicks' => $d->gte(CarbonImmutable::parse('2026-10-27')) ? 250 : null,
                'active_users' => 4000,
            ]);
        }
        DB::table(self::TABLE)->where('date', '2026-11-20')->update(['billable_clicks' => 170, 'alert' => 'day-rise']);

        $this->artisan('integrations:whatjobs-clicks-weekly')->assertSuccessful();

        $mails = $this->mails();
        $this->assertCount(1, $mails);
        $message = $mails[0]->getOriginalMessage();
        $this->assertSame('cto-test@example.org', $message->getTo()[0]->getAddress());
        $this->assertStringContainsString('week to Sunday 22 November: 1,130 billable', $message->getSubject());
        $text = $message->getTextBody();
        $this->assertStringContainsString('1,130 billable clicks, 161 a day. The week before: 160 a day.', $text);
        $this->assertStringContainsString('of which WhatJobs billed 65%', $text);
        $this->assertStringContainsString('against 200 in the 12 weeks before (down 20%)', $text);
        $this->assertStringContainsString('fewer of our members click jobs', $text);
        $this->assertStringContainsString('Friday 20 November was flagged: a sudden rise that day.', $text);
        $this->assertStringContainsString('changed the billable figure for 1 of the last 28 days after first publishing it, by +10 clicks', $text);
        $this->assertStringContainsString('Mon 16 Nov | 160 | 90 | 250 | 64% | 4,000', $text);
        $this->assertStringContainsString('src="cid:', $message->getHtmlBody());
    }

    public function test_the_weekly_summary_can_go_to_another_address_and_says_when_figures_are_missing(): void
    {
        Carbon::setTestNow('2026-11-24 07:40:00');
        for ($d = CarbonImmutable::parse('2026-10-01'); $d->lte(CarbonImmutable::parse('2026-11-19')); $d = $d->addDay()) {
            $this->storedDay($d->toDateString(), ['billable_clicks' => 160, 'first_billable_clicks' => 160, 'active_users' => 4000]);
        }

        $this->artisan('integrations:whatjobs-clicks-weekly', ['--to' => 'someone@example.org'])->assertSuccessful();

        $message = $this->mails()[0]->getOriginalMessage();
        $this->assertSame('someone@example.org', $message->getTo()[0]->getAddress());
        $this->assertStringContainsString('WhatJobs have not published figures for 20 November, 21 November, 22 November.', $message->getTextBody());
    }

    public function test_the_weekly_summary_sends_nothing_without_figures(): void
    {
        $this->artisan('integrations:whatjobs-clicks-weekly')->assertSuccessful();

        $this->assertCount(0, $this->mails());
    }

    public function test_the_chart_is_a_png_with_round_axis_tops(): void
    {
        $png = ClickTrendChart::render([
            ['label' => 'Jan', 'billable' => 300.0, 'ours' => null, 'active' => null],
            ['label' => null, 'billable' => 280.0, 'ours' => 410.0, 'active' => 4100.0],
            ['label' => 'Feb', 'billable' => null, 'ours' => 400.0, 'active' => 4000.0],
        ]);

        $this->assertStringStartsWith("\x89PNG", $png);
        $this->assertSame(200.0, ClickTrendChart::niceMax(173));
        $this->assertSame(4800.0, ClickTrendChart::niceMax(4123));
        $this->assertSame(1.0, ClickTrendChart::niceMax(1));
    }

    public function test_both_commands_are_scheduled_with_bounded_overlap_guards(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events());
        foreach (['integrations:whatjobs-clicks' => '10 7 * * *', 'integrations:whatjobs-clicks-weekly' => '40 7 * * 2'] as $command => $cron) {
            $event = $events->first(fn ($e) => str_ends_with((string) $e->command, "'artisan' {$command}") || str_ends_with((string) $e->command, "artisan {$command}"));
            $this->assertNotNull($event, "{$command} is scheduled");
            $this->assertSame($cron, $event->expression);
        }
    }
}
