<?php

namespace Tests\Feature\Ux;

use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\FakesUxLoki;
use Tests\TestCase;

/**
 * ux:weekly-report compares the last seven days with the seven before, using the rollup tables.
 */
class WeeklyReportCommandTest extends TestCase
{
    use FakesUxLoki;

    protected function setUp(): void
    {
        parent::setUp();
        // A Monday. Last week is 2026-03-02..08, the week before 2026-02-23..03-01.
        Carbon::setTestNow('2026-03-09 07:40:00');
        config()->set('freegle.ux.report_to', 'ux-test@example.org');
        config()->set('freegle.ux.health_change_points', []);
        config()->set('freegle.sites.mod', 'https://mod.example.org');
        config()->set('mail.default', 'array');
        Mail::purge('array');
        $this->fakeLoki();
        foreach (['ux_flow_daily', 'ux_component_daily', 'ux_regret_daily', 'ux_telemetry_health_daily'] as $t) {
            DB::table($t)->delete();
        }
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

    private function step(string $date, string $step, int $entered, int $completed, string $flow = 'give', int $reentered = 0): void
    {
        DB::table('ux_flow_daily')->insert(['date' => $date, 'flow' => $flow, 'step' => $step, 'entered' => $entered, 'reentered' => $reentered, 'completed' => $completed]);
    }

    private function clickRow(string $date, string $component, int $clicks, int $dead = 0, int $rage = 0, int $repeat = 0, ?string $device = null): void
    {
        DB::table('ux_component_daily')->insert([
            'date' => $date, 'page' => 'browse-term', 'component' => $component, 'device_class' => $device ?? '',
            'clicks' => $clicks, 'dead_clicks' => $dead, 'rage_clicks' => $rage, 'repeated_intent' => $repeat,
        ]);
    }

    private function text(): string
    {
        return $this->mails()[0]->getOriginalMessage()->getTextBody();
    }

    /** One tiny row (too thin to compare) per table on each of the 14 days, so both weeks are complete. */
    private function fullWeeks(): void
    {
        for ($d = Carbon::parse('2026-02-23'); $d->lte(Carbon::parse('2026-03-08')); $d->addDay()) {
            $date = $d->toDateString();
            $this->step($date, 'filler', 1, 1, 'give');
            $this->clickRow($date, 'Filler', 1);
            DB::table('ux_regret_daily')->insert(['date' => $date, 'signal' => 'filler', 'count' => 0, 'base' => 1]);
        }
    }

    public function test_sends_nothing_when_there_are_no_rows(): void
    {
        $this->artisan('ux:weekly-report')->assertExitCode(0);

        $this->assertCount(0, $this->mails());
    }

    public function test_mails_the_five_largest_changes_and_ignores_thin_steps(): void
    {
        $this->fullWeeks();
        // Conversion: photos fell from 80% to 40% (-40pp); details 60% -> 50% (-10pp).
        $this->step('2026-02-24', 'give-photos', 100, 80);
        $this->step('2026-03-03', 'give-photos', 100, 40);
        $this->step('2026-02-24', 'give-details', 100, 60);
        $this->step('2026-03-03', 'give-details', 100, 50);
        // Too thin in the previous week: ignored however big the swing.
        $this->step('2026-02-24', 'give-tiny', 10, 10);
        $this->step('2026-03-03', 'give-tiny', 100, 0);
        // Friction: rage on the card went from 1% to 11% of clicks.
        $this->clickRow('2026-02-24', 'PostCard', 100, 0, 1, 0);
        $this->clickRow('2026-03-03', 'PostCard', 100, 5, 6, 0);
        $this->clickRow('2026-03-03', 'Thin', 10, 10, 0, 0);
        $this->clickRow('2026-02-24', 'Thin', 10, 0, 0, 0);
        // Regret: withdrawn_24h 2% -> 12%.
        DB::table('ux_regret_daily')->insert(['date' => '2026-02-24', 'signal' => 'withdrawn_24h', 'count' => 2, 'base' => 100]);
        DB::table('ux_regret_daily')->insert(['date' => '2026-03-03', 'signal' => 'withdrawn_24h', 'count' => 12, 'base' => 100]);
        // Three more changes so there are more than five candidates.
        foreach (['a', 'b', 'c'] as $i => $s) {
            $this->step('2026-02-24', 'give-'.$s, 100, 50);
            $this->step('2026-03-03', 'give-'.$s, 100, 49 - $i);
        }

        $this->artisan('ux:weekly-report')->assertExitCode(0);

        $mails = $this->mails();
        $this->assertCount(1, $mails);
        $text = $mails[0]->getOriginalMessage()->getTextBody();
        $this->assertSame('ux-test@example.org', $mails[0]->getEnvelope()->getRecipients()[0]->getAddress());
        // The link opens the UX tab. A bare /sysadmin opens Housekeeping.
        $this->assertStringContainsString('https://mod.example.org/sysadmin?tab=ux', $text);
        $this->assertStringContainsString('give-photos', $text);
        $this->assertStringContainsString('80.0% to 40.0%', $text);
        $this->assertStringContainsString('PostCard', $text);
        $this->assertStringContainsString('withdrawn_24h', $text);
        $this->assertStringNotContainsString('give-tiny', $text);
        $this->assertStringNotContainsString('Thin', $text);
        // Five and only five: the smallest drift (give-a) is left out.
        $this->assertSame(5, preg_match_all('/^\s*\d\. /m', $text));
        $this->assertStringNotContainsString("give-a", $text);
    }

    public function test_conversion_is_over_visits_so_a_step_entered_again_stays_under_100_percent(): void
    {
        $this->fullWeeks();
        // 100 first visits and 60 more: 150 completions is 93.8% of visits, not 150% of entries.
        $this->step('2026-02-24', 'give-photos', 100, 80, 'give', 0);
        $this->step('2026-03-03', 'give-photos', 100, 150, 'give', 60);

        $this->artisan('ux:weekly-report')->assertExitCode(0);

        $text = $this->mails()[0]->getOriginalMessage()->getTextBody();
        $this->assertStringContainsString('80.0% to 93.8%', $text);
        $this->assertStringContainsString('160 visits', $text);
    }

    public function test_unhealthy_telemetry_leads_the_mail(): void
    {
        $this->fullWeeks();
        $this->step('2026-03-03', 'give-photos', 100, 40);
        $this->step('2026-02-24', 'give-photos', 100, 80);
        DB::table('ux_telemetry_health_daily')->insert(['date' => '2026-03-04', 'metric' => 'flow_start:give', 'expected' => 100, 'observed' => 40, 'ratio' => 0.4]);
        DB::table('ux_telemetry_health_daily')->insert(['date' => '2026-03-05', 'metric' => 'event:page_view', 'expected' => 100, 'observed' => 100, 'ratio' => 1.0]);
        // Last week's fine and the week before's bad one do not count.
        DB::table('ux_telemetry_health_daily')->insert(['date' => '2026-02-25', 'metric' => 'event:flow', 'expected' => 100, 'observed' => 10, 'ratio' => 0.1]);

        $this->artisan('ux:weekly-report')->assertExitCode(0);

        $text = $this->mails()[0]->getOriginalMessage()->getTextBody();
        $this->assertStringContainsString('flow_start:give', $text);
        $this->assertStringNotContainsString('event:flow', $text);
        $this->assertLessThan(strpos($text, 'give-photos'), strpos($text, 'flow_start:give'));
    }

    public function test_a_partial_week_is_not_compared_and_the_mail_says_how_many_days_each_has(): void
    {
        $this->fullWeeks();
        // The previous week has only 4 rollup days, as when the tables began part-way through.
        DB::table('ux_flow_daily')->whereBetween('date', ['2026-02-23', '2026-02-25'])->delete();
        DB::table('ux_component_daily')->whereBetween('date', ['2026-02-23', '2026-02-25'])->delete();
        // Previous week's 4 days hold a quarter of the volume, so this week's base "doubles".
        $this->step('2026-02-26', 'give-photos', 100, 80);
        $this->step('2026-03-03', 'give-photos', 400, 160);

        $this->artisan('ux:weekly-report')->assertExitCode(0);

        $text = $this->mails()[0]->getOriginalMessage()->getTextBody();
        $this->assertStringNotContainsString('give-photos', $text);
        $this->assertStringContainsString('NOT COMPARED', $text);
        $this->assertStringContainsString('Step conversion: 7 day(s) this week, 4 the week before', $text);
        $this->assertStringContainsString('Click friction: 7 day(s) this week, 4 the week before', $text);
        // Regret has both weeks complete, so it is not listed.
        $this->assertStringNotContainsString('Regret: ', $text);
    }

    public function test_an_alarm_without_a_like_for_like_baseline_is_not_reported(): void
    {
        $this->fullWeeks();
        config()->set('freegle.ux.health_change_points', ['event:flow' => '2026-03-01']);
        // 4 March is only 3 days after the change, so its baseline was thin; 8 March has 7.
        DB::table('ux_telemetry_health_daily')->insert(['date' => '2026-03-04', 'metric' => 'event:flow', 'expected' => 5, 'observed' => 100, 'ratio' => 20]);
        DB::table('ux_telemetry_health_daily')->insert(['date' => '2026-03-08', 'metric' => 'event:flow', 'expected' => 100, 'observed' => 10, 'ratio' => 0.1]);

        $this->artisan('ux:weekly-report')->assertExitCode(0);

        $text = $this->mails()[0]->getOriginalMessage()->getTextBody();
        $this->assertStringNotContainsString('event:flow on 2026-03-04', $text);
        $this->assertStringContainsString('event:flow on 2026-03-08', $text);
    }

    public function test_only_health_rows_still_sends_so_a_broken_pipeline_is_not_silent(): void
    {
        DB::table('ux_telemetry_health_daily')->insert(['date' => '2026-03-04', 'metric' => 'event:flow', 'expected' => 100, 'observed' => 0, 'ratio' => 0]);

        $this->artisan('ux:weekly-report')->assertExitCode(0);

        $this->assertCount(1, $this->mails());
    }

    public function test_a_change_is_ranked_by_the_clicks_it_touches_not_by_points(): void
    {
        $this->fullWeeks();
        // 40 points on 100 clicks is 40 trouble clicks; 10 points on 2,000 is 200.
        $this->clickRow('2026-02-24', 'Small', 100, 90);
        $this->clickRow('2026-03-03', 'Small', 100, 50);
        $this->clickRow('2026-02-24', 'Big', 2000, 600);
        $this->clickRow('2026-03-03', 'Big', 2000, 400);

        $this->artisan('ux:weekly-report')->assertExitCode(0);

        $text = $this->text();
        $this->assertLessThan(strpos($text, 'Small'), strpos($text, 'Big'));
        $this->assertStringContainsString('browse-term / Big: 30.0% to 20.0%, about 200 fewer trouble clicks a week (2,000 clicks, was 2,000)', $text);
    }

    public function test_friction_is_compared_device_by_device_so_a_new_source_does_not_read_as_a_change(): void
    {
        $this->fullWeeks();
        // Phones stay at 60%. An app that only began sending this week adds 1,000 clicks at 10%; pooled,
        // that would read as 60% to 14.5%.
        $this->clickRow('2026-02-24', 'Chat', 100, 60, 0, 0, 'mobile');
        $this->clickRow('2026-03-03', 'Chat', 100, 60, 0, 0, 'mobile');
        $this->clickRow('2026-03-03', 'Chat', 1000, 100, 0, 0, 'app-ios');

        $this->artisan('ux:weekly-report')->assertExitCode(0);

        $text = $this->text();
        $this->assertStringContainsString('browse-term / Chat: 60.0% to 60.0%, about 0 more trouble clicks a week (100 clicks, was 100)', $text);
        $this->assertStringContainsString('app-ios: 1,000 clicks, none the week before, so not compared', $text);
        $this->assertStringNotContainsString('14.5%', $text);
    }

    public function test_a_device_that_starts_or_stops_sending_is_named_before_the_changes(): void
    {
        $this->fullWeeks();
        $this->clickRow('2026-02-24', 'Card', 5000, 0, 0, 0, 'mobile');
        $this->clickRow('2026-03-03', 'Card', 5000, 0, 0, 0, 'mobile');
        $this->clickRow('2026-03-03', 'Card', 5000, 0, 0, 0, 'app-ios');

        $this->artisan('ux:weekly-report')->assertExitCode(0);

        $text = $this->text();
        $this->assertStringContainsString('WHO IS COUNTED CHANGED', $text);
        $this->assertStringContainsString('app-ios: 5,000 clicks, was 0 (new', $text);
        $this->assertStringNotContainsString('mobile: 5,000 clicks, was', $text);
        $this->assertLessThan(strpos($text, 'FIVE LARGEST'), strpos($text, 'WHO IS COUNTED CHANGED'));
    }

    public function test_a_telemetry_alarm_says_whether_events_rose_or_fell(): void
    {
        $this->fullWeeks();
        DB::table('ux_telemetry_health_daily')->insert(['date' => '2026-03-08', 'metric' => 'event:impressions', 'expected' => 8183, 'observed' => 10888, 'ratio' => 1.331]);
        DB::table('ux_telemetry_health_daily')->insert(['date' => '2026-03-07', 'metric' => 'event:flow', 'expected' => 100, 'observed' => 40, 'ratio' => 0.4]);

        $this->artisan('ux:weekly-report')->assertExitCode(0);

        $text = $this->text();
        $this->assertStringContainsString('event:impressions on 2026-03-08: 10,888, expected 8,183 (1.331 times, more than usual)', $text);
        $this->assertStringContainsString('event:flow on 2026-03-07: 40, expected 100 (0.4 times, fewer than usual)', $text);
        $this->assertStringNotContainsString('a drop in events', $text);
    }

    private function frictionLoki(int $people, array $top, int $total, array $elements): void
    {
        $this->lokiMatrix('/^count\(sum by \(session_id\)/', [[[], $people]]);
        $this->lokiMatrix('/^topk\(2, sum by \(session_id\)/', array_map(fn ($n, $i) => [['session_id' => 's'.$i], $n], $top, array_keys($top)));
        $this->lokiMatrix('/^sum\(count_over_time/', [[[], $total]]);
        $this->lokiMatrix('/^topk\(3, sum by \(tag, label\)/', $elements);
    }

    public function test_a_friction_change_says_who_clicked_and_what_the_trouble_clicks_were_on(): void
    {
        $this->fullWeeks();
        $this->clickRow('2026-02-24', 'VolunteerOpportunity', 100, 20, 0, 0, 'mobile');
        $this->clickRow('2026-03-03', 'VolunteerOpportunity', 347, 12, 0, 196, 'mobile');
        $this->frictionLoki(21, [40, 38], 347, [
            [['tag' => 'a', 'label' => 'Volunteer Secretary'], 82],
            [['tag' => 'img', 'label' => ''], 2],
            [['tag' => 'a', 'label' => str_repeat('x', 60)], 15],
        ]);

        $this->artisan('ux:weekly-report')->assertExitCode(0);

        $text = $this->text();
        $this->assertStringContainsString('21 people clicked it this week.', $text);
        $this->assertStringNotContainsString('read this change as theirs', $text);
        $this->assertStringContainsString('Trouble clicks were on: a "Volunteer Secretary" (82); a "'.str_repeat('x', 40).'..." (15); img with no label (2).', $text);
        $this->assertTrue($this->lokiSent('page_route="browse-term"', 'component="VolunteerOpportunity"', '|= "VolunteerOpportunity"'));
        $this->assertStringContainsString('See it: https://mod.example.org/sysadmin?tab=ux&ux_section=pages&ux_open=friction&ux_period=custom&ux_from=2026-03-02&ux_to=2026-03-08', $text);
    }

    public function test_a_change_made_mostly_by_two_people_says_so(): void
    {
        $this->fullWeeks();
        $this->clickRow('2026-02-24', 'ChatMessage', 159, 111, 0, 0, 'desktop');
        $this->clickRow('2026-03-03', 'ChatMessage', 1675, 225, 0, 0, 'desktop');
        $this->frictionLoki(133, [867, 551], 1675, []);

        $this->artisan('ux:weekly-report')->assertExitCode(0);

        $this->assertStringContainsString('133 people clicked it this week. 1,418 of the 1,675 clicks came from just two of them, so read this change as theirs.', $this->text());
    }

    public function test_when_loki_does_not_answer_the_mail_says_so(): void
    {
        $this->fullWeeks();
        $this->clickRow('2026-02-24', 'PostCard', 100, 0, 1, 0);
        $this->clickRow('2026-03-03', 'PostCard', 100, 5, 6, 0);
        $this->lokiDown = true;

        $this->artisan('ux:weekly-report')->assertExitCode(0);

        $this->assertStringContainsString('Who and what: Loki did not answer, so not known.', $this->text());
    }

    public function test_a_busy_component_over_lokis_series_cap_still_says_what_was_clicked(): void
    {
        $this->fullWeeks();
        $this->clickRow('2026-02-24', 'NavbarMobile', 1000, 100, 0, 0, 'mobile');
        $this->clickRow('2026-03-03', 'NavbarMobile', 1000, 50, 0, 0, 'mobile');
        $this->lokiMatrix('/^sum\(count_over_time/', [[[], 1000]]);
        $this->lokiMatrix('/^topk\(3, sum by \(tag, label\)/', [[['tag' => 'a', 'label' => 'Chats'], 30]]);
        $this->lokiError('/sum by \(session_id\)/', 400, 'maximum of series (500) reached for a single query');

        $this->artisan('ux:weekly-report')->assertExitCode(0);

        $text = $this->text();
        $this->assertStringContainsString('More than 500 people clicked it this week.', $text);
        $this->assertStringContainsString('Trouble clicks were on: a "Chats" (30).', $text);
        $this->assertStringNotContainsString('Loki did not answer', $text);
        // The two-person check needs the per-session counts Loki refused, so it is not asked for.
        $this->assertFalse($this->lokiSent('topk(2, sum by (session_id)'));
    }

    public function test_a_step_links_to_its_funnel_and_step_for_the_same_week(): void
    {
        $this->fullWeeks();
        $this->step('2026-02-24', 'give-photos', 100, 80);
        $this->step('2026-03-03', 'give-photos', 100, 40);

        $this->artisan('ux:weekly-report')->assertExitCode(0);

        $this->assertStringContainsString('tab=ux&ux_section=funnels&ux_flow=give&ux_step=give-photos&ux_period=custom&ux_from=2026-03-02&ux_to=2026-03-08', $this->text());
    }

    public function test_it_is_scheduled_for_monday_0740(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'ux:weekly-report'));
        $this->assertNotNull($event);
        $this->assertSame('40 7 * * 1', $event->expression);
    }
}
