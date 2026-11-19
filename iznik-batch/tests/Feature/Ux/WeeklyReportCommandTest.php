<?php

namespace Tests\Feature\Ux;

use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\FakesUxLoki;
use Tests\TestCase;

/**
 * ux:weekly-report compares the last seven days with the seven before, using the rollup tables. It
 * reports rates and relative changes over people, and never a count.
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

    /** False for a test about weeks rolled up before people were counted: the people columns are left NULL. */
    private bool $people = true;

    /** A step's row. $people is [arrived, finished] when it differs from the event counts. */
    private function step(string $date, string $step, int $entered, int $completed, string $flow = 'give', int $reentered = 0, ?string $device = null, ?array $people = null): void
    {
        [$pe, $pc] = $people ?? [$entered, $completed];
        DB::table('ux_flow_daily')->insert([
            'date' => $date, 'flow' => $flow, 'step' => $step, 'device_class' => $device ?? '',
            'entered' => $entered, 'reentered' => $reentered, 'completed' => $completed,
            'people_entered' => $this->people ? $pe : null, 'people_completed' => $this->people ? $pc : null,
        ]);
    }

    /** A component's row. Without $people, everyone clicked once: people = clicks, people in trouble = trouble clicks. */
    private function clickRow(string $date, string $component, int $clicks, int $dead = 0, int $rage = 0, int $repeat = 0, ?string $device = null, ?array $people = null): void
    {
        [$p, $pt] = $people ?? [$clicks, $dead + $rage + $repeat];
        DB::table('ux_component_daily')->insert([
            'date' => $date, 'page' => 'browse-term', 'component' => $component, 'device_class' => $device ?? '',
            'clicks' => $clicks, 'dead_clicks' => $dead, 'rage_clicks' => $rage, 'repeated_intent' => $repeat,
            'people' => $this->people ? $p : null, 'people_trouble' => $this->people ? $pt : null,
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

    /** The mail with its dates and links taken out, so what is left can be searched for counts. */
    private function prose(): string
    {
        return preg_replace(['/https?:\/\/\S+/', '/\d{4}-\d{2}-\d{2}/'], '', $this->text());
    }

    private function assertNoCounts(): void
    {
        // Every number left is a rank ("1."), a rate ("40.0%"), a relative change ("down 50%", "11 times")
        // or a number of days. A count would be a bare number, or one followed by a noun.
        $this->assertDoesNotMatchRegularExpression('/\b\d[\d,]*(?:\.\d+)? (?:clicks|visits|people|completions|events|posts)\b/', $this->prose());
        $this->assertDoesNotMatchRegularExpression('/\b\d{1,3}(?:,\d{3})+\b/', $this->prose());
    }

    public function test_sends_nothing_when_there_are_no_rows(): void
    {
        $this->artisan('ux:weekly-report')->assertExitCode(0);

        $this->assertCount(0, $this->mails());
    }

    public function test_mails_the_five_clearest_changes_in_plain_names_and_ignores_thin_steps(): void
    {
        $this->fullWeeks();
        // Conversion: photos fell from 80% to 40%; details 60% -> 50%.
        $this->step('2026-02-24', 'give-photos', 1000, 800);
        $this->step('2026-03-03', 'give-photos', 1000, 400);
        $this->step('2026-02-24', 'give-details', 1000, 600);
        $this->step('2026-03-03', 'give-details', 1000, 500);
        // Too thin in the previous week: ignored however big the swing.
        $this->step('2026-02-24', 'give-tiny', 10, 10);
        $this->step('2026-03-03', 'give-tiny', 100, 0);
        // Friction: trouble on the card went from 1% to 11% of the people who clicked it.
        $this->clickRow('2026-02-24', 'PostCard', 1000, 0, 10, 0);
        $this->clickRow('2026-03-03', 'PostCard', 1000, 50, 60, 0);
        $this->clickRow('2026-03-03', 'Thin', 10, 10, 0, 0);
        $this->clickRow('2026-02-24', 'Thin', 10, 0, 0, 0);
        // Regret: withdrawn_24h 2% -> 12%.
        DB::table('ux_regret_daily')->insert(['date' => '2026-02-24', 'signal' => 'withdrawn_24h', 'count' => 20, 'base' => 1000]);
        DB::table('ux_regret_daily')->insert(['date' => '2026-03-03', 'signal' => 'withdrawn_24h', 'count' => 120, 'base' => 1000]);
        // Three more changes so there are more than five candidates.
        foreach (['a', 'b', 'c'] as $i => $s) {
            $this->step('2026-02-24', 'give-'.$s, 1000, 500);
            $this->step('2026-03-03', 'give-'.$s, 1000, 440 - 10 * $i);
        }

        $this->artisan('ux:weekly-report')->assertExitCode(0);

        $mails = $this->mails();
        $this->assertCount(1, $mails);
        $text = $mails[0]->getOriginalMessage()->getTextBody();
        $this->assertSame('ux-test@example.org', $mails[0]->getEnvelope()->getRecipients()[0]->getAddress());
        // The link opens the UX tab. A bare /sysadmin opens Housekeeping.
        $this->assertStringContainsString('https://mod.example.org/sysadmin?tab=ux', $text);
        $this->assertStringContainsString('Offering something, step "give-photos": 40.0% of people who reached it finished it, was 80.0%.', $text);
        $this->assertStringContainsString('People finishing: down 50%. People arriving: no change.', $text);
        $this->assertStringContainsString('Browse, PostCard: 11.0% of people who clicked it had trouble', $text);
        $this->assertStringContainsString('People having trouble: 11 times as many. People clicking: no change.', $text);
        $this->assertStringContainsString('Posts withdrawn within a day: 12.0% of posts, was 2.0%.', $text);
        $this->assertStringNotContainsString('withdrawn_24h', $text);
        $this->assertStringNotContainsString('browse-term', $this->prose());
        $this->assertStringNotContainsString('give-tiny', $text);
        $this->assertStringNotContainsString('Thin', $text);
        // Five and only five: the smallest drifts (give-a, give-b) are left out.
        $this->assertSame(5, preg_match_all('/^\s*\d\. /m', $text));
        $this->assertStringNotContainsString('give-a', $text);
        $this->assertStringNotContainsString('COUNTED IN EVENTS', $text);
        $this->assertNoCounts();
    }

    public function test_a_rate_is_over_people_so_one_person_sending_many_events_does_not_move_it(): void
    {
        $this->fullWeeks();
        // The same 100 people arrive and 80 finish in both weeks. This week one of them is stuck in a
        // loop and enters the step 5,000 more times: in events that is 80% falling to under 2%.
        $this->step('2026-02-24', 'give-photos', 100, 80, 'give', 0, null, [100, 80]);
        $this->step('2026-03-03', 'give-photos', 100, 80, 'give', 5000, null, [100, 80]);
        // A real change, so there is something to list.
        $this->step('2026-02-24', 'give-details', 100, 60);
        $this->step('2026-03-03', 'give-details', 100, 30);

        $this->artisan('ux:weekly-report')->assertExitCode(0);

        $text = $this->text();
        $this->assertStringContainsString('step "give-details": 30.0% of people who reached it finished it, was 60.0%.', $text);
        // Nothing changed for the people on the photos step, so it is not listed at all.
        $this->assertStringNotContainsString('give-photos', $text);
        $this->assertStringNotContainsString('1.6%', $text);
    }

    public function test_arrivals_that_moved_while_the_people_finishing_did_not_are_traffic_and_are_left_out(): void
    {
        $this->fullWeeks();
        // A scraper opens the join form on desktop: fourteen times the arrivals, the same people joining.
        // Pooled with phones that would read as 45% falling to 5%.
        $this->step('2026-02-24', 'signup', 3500, 1400, 'login', 0, 'desktop');
        $this->step('2026-03-03', 'signup', 49000, 1380, 'login', 0, 'desktop');
        // On phones the form did get worse.
        $this->step('2026-02-24', 'signup', 1000, 500, 'login', 0, 'mobile');
        $this->step('2026-03-03', 'signup', 1000, 400, 'login', 0, 'mobile');

        $this->artisan('ux:weekly-report')->assertExitCode(0);

        $text = $this->text();
        $this->assertStringContainsString('PROBABLY TRAFFIC, NOT THE PAGE', $text);
        $this->assertStringContainsString('Signing in or joining, the join form, desktop: people arriving 14 times as many; people finishing no change.', $text);
        // Only phones are compared.
        $this->assertStringContainsString('1. Signing in or joining, the join form: 40.0% of people who reached it finished it, was 50.0%.', $text);
        $this->assertStringContainsString('People finishing: down 20%. People arriving: no change.', $text);
        $this->assertStringContainsString('     mobile: 40.0%, was 50.0%', $text);
        $this->assertStringNotContainsString('     desktop: ', $text);
        $this->assertLessThan(strpos($text, 'FIVE CLEAREST'), strpos($text, 'PROBABLY TRAFFIC, NOT THE PAGE'));
        $this->assertNoCounts();
    }

    public function test_a_step_where_only_the_arrivals_moved_is_not_one_of_the_changes(): void
    {
        $this->fullWeeks();
        $this->step('2026-02-24', 'signup', 3500, 1400, 'login', 0, 'desktop');
        $this->step('2026-03-03', 'signup', 49000, 1380, 'login', 0, 'desktop');

        $this->artisan('ux:weekly-report')->assertExitCode(0);

        $text = $this->text();
        $this->assertStringContainsString('Signing in or joining, the join form, desktop: people arriving 14 times as many', $text);
        $this->assertStringContainsString('(nothing moved by more than chance)', $text);
    }

    public function test_the_same_trouble_among_many_more_people_clicking_is_a_change_not_traffic(): void
    {
        $this->fullWeeks();
        $this->clickRow('2026-02-24', 'Card', 1000, 200);
        $this->clickRow('2026-03-03', 'Card', 3000, 200);

        $this->artisan('ux:weekly-report')->assertExitCode(0);

        $text = $this->text();
        $this->assertStringNotContainsString('PROBABLY TRAFFIC, NOT THE PAGE', $text);
        $this->assertStringContainsString('Browse, Card: 6.7% of people who clicked it had trouble', $text);
        $this->assertStringContainsString('People having trouble: no change. People clicking: 3 times as many.', $text);
    }

    public function test_only_the_five_biggest_moves_in_traffic_are_listed(): void
    {
        $this->fullWeeks();
        foreach ([2, 3, 4, 5, 6, 7, 8] as $times) {
            $this->step('2026-02-24', 'step'.$times, 100, 50, 'give', 0, 'desktop');
            $this->step('2026-03-03', 'step'.$times, 100 * $times, 50, 'give', 0, 'desktop');
        }

        $this->artisan('ux:weekly-report')->assertExitCode(0);

        $text = $this->text();
        $this->assertSame(5, preg_match_all('/step "step\d", desktop: people arriving/', $text));
        $this->assertStringContainsString('step "step8", desktop: people arriving 8 times as many; people finishing no change.', $text);
        $this->assertStringNotContainsString('step "step3"', $text);
        $this->assertStringContainsString('And others where the arrivals moved less.', $text);
        $this->assertLessThan(strpos($text, 'step "step4"'), strpos($text, 'step "step8"'));
    }

    public function test_a_fifth_fewer_people_finishing_is_not_filed_as_traffic(): void
    {
        $this->fullWeeks();
        // Half as many again arrive and a fifth fewer finish: the rate nearly halves. That is compared.
        $this->step('2026-02-24', 'signup', 1000, 500, 'login', 0, 'desktop');
        $this->step('2026-03-03', 'signup', 1500, 400, 'login', 0, 'desktop');

        $this->artisan('ux:weekly-report')->assertExitCode(0);

        $text = $this->text();
        $this->assertStringNotContainsString('PROBABLY TRAFFIC', $text);
        $this->assertStringContainsString('the join form: 26.7% of people who reached it finished it, was 50.0%.', $text);
        $this->assertStringContainsString('People finishing: down 20%. People arriving: up 50%.', $text);
    }

    public function test_more_members_finishing_than_addresses_arriving_is_capped_and_still_compared(): void
    {
        $this->fullWeeks();
        // Members behind shared addresses: 120 finish from 100 addresses. This week 60 do.
        $this->step('2026-02-24', 'signup', 100, 120, 'login', 0, 'mobile');
        $this->step('2026-03-03', 'signup', 100, 60, 'login', 0, 'mobile');

        $this->artisan('ux:weekly-report')->assertExitCode(0);

        $text = $this->text();
        $this->assertStringContainsString('the join form: 60.0% of people who reached it finished it, was 100.0%.', $text);
        $this->assertStringNotContainsString('120.0%', $text);
    }

    public function test_fewer_people_finishing_is_a_change_however_much_the_arrivals_moved(): void
    {
        $this->fullWeeks();
        // Twice the arrivals and half the people finishing: that is the form, not only the traffic.
        $this->step('2026-02-24', 'signup', 1000, 400, 'login', 0, 'desktop');
        $this->step('2026-03-03', 'signup', 2000, 200, 'login', 0, 'desktop');

        $this->artisan('ux:weekly-report')->assertExitCode(0);

        $text = $this->text();
        $this->assertStringNotContainsString('PROBABLY TRAFFIC, NOT THE PAGE', $text);
        $this->assertStringContainsString('the join form: 10.0% of people who reached it finished it, was 40.0%.', $text);
        $this->assertStringContainsString('People finishing: down 50%. People arriving: 2 times as many.', $text);
    }

    public function test_weeks_rolled_up_before_people_were_counted_are_compared_in_events_and_the_mail_says_so(): void
    {
        $this->people = false;
        $this->fullWeeks();
        // 100 first visits and 60 more: 150 completions is 93.8% of visits, not 150% of entries.
        $this->step('2026-02-24', 'give-photos', 100, 80, 'give', 0);
        $this->step('2026-03-03', 'give-photos', 100, 150, 'give', 60);

        $this->artisan('ux:weekly-report')->assertExitCode(0);

        $text = $this->text();
        $this->assertStringContainsString('93.8% of visits to it finished it (events, not people), was 80.0%.', $text);
        $this->assertStringContainsString('Completions: up 88%. Visits: up 60%.', $text);
        $this->assertStringContainsString('COUNTED IN EVENTS, NOT PEOPLE this week (a week was rolled up before people were counted): Finishing a step; Trouble clicking.', $text);
        // Said before the changes it qualifies, not after them.
        $this->assertLessThan(strpos($text, 'FIVE CLEAREST'), strpos($text, 'COUNTED IN EVENTS'));
        $this->assertNoCounts();
    }

    public function test_one_day_without_people_counts_puts_the_whole_table_back_in_events(): void
    {
        $this->fullWeeks();
        $this->step('2026-02-24', 'give-photos', 100, 80);
        $this->step('2026-03-03', 'give-photos', 100, 40);
        DB::table('ux_flow_daily')->where('date', '2026-02-23')->update(['people_entered' => null, 'people_completed' => null]);

        $this->artisan('ux:weekly-report')->assertExitCode(0);

        $text = $this->text();
        $this->assertStringContainsString('(events, not people)', $text);
        $this->assertStringContainsString('COUNTED IN EVENTS, NOT PEOPLE this week (a week was rolled up before people were counted): Finishing a step.', $text);
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

        $text = $this->text();
        $this->assertStringContainsString('2026-03-04, "Offering something" started, against visits to its first page: 0.4 times the usual amount', $text);
        $this->assertStringNotContainsString('form step events', $text);
        $this->assertStringNotContainsString('page views', $text);
        $this->assertLessThan(strpos($text, 'give-photos'), strpos($text, 'WHAT WE MEASURE CHANGED'));
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

        $text = $this->text();
        $this->assertStringNotContainsString('give-photos', $text);
        $this->assertStringContainsString('NOT COMPARED', $text);
        $this->assertStringContainsString('Finishing a step: 7 day(s) this week, 4 the week before', $text);
        $this->assertStringContainsString('Trouble clicking: 7 day(s) this week, 4 the week before', $text);
        // Regret has both weeks complete, so it is not listed.
        $this->assertStringNotContainsString('Second thoughts: ', $text);
    }

    public function test_an_alarm_without_a_like_for_like_baseline_is_not_reported(): void
    {
        $this->fullWeeks();
        config()->set('freegle.ux.health_change_points', ['event:flow' => '2026-03-01']);
        // 4 March is only 3 days after the change, so its baseline was thin; 8 March has 7.
        DB::table('ux_telemetry_health_daily')->insert(['date' => '2026-03-04', 'metric' => 'event:flow', 'expected' => 5, 'observed' => 100, 'ratio' => 20]);
        DB::table('ux_telemetry_health_daily')->insert(['date' => '2026-03-08', 'metric' => 'event:flow', 'expected' => 100, 'observed' => 10, 'ratio' => 0.1]);

        $this->artisan('ux:weekly-report')->assertExitCode(0);

        $text = $this->text();
        $this->assertStringNotContainsString('2026-03-04, form step events', $text);
        $this->assertStringContainsString('2026-03-08, form step events: 0.1 times the usual amount', $text);
    }

    public function test_only_health_rows_still_sends_so_a_broken_pipeline_is_not_silent(): void
    {
        DB::table('ux_telemetry_health_daily')->insert(['date' => '2026-03-04', 'metric' => 'event:flow', 'expected' => 100, 'observed' => 0, 'ratio' => 0]);

        $this->artisan('ux:weekly-report')->assertExitCode(0);

        $this->assertCount(1, $this->mails());
    }

    public function test_a_change_is_ranked_by_how_sure_it_is_not_by_how_many_points(): void
    {
        $this->fullWeeks();
        // 40 points among 60 people is less sure than 10 points among 2,000.
        $this->clickRow('2026-02-24', 'Small', 60, 30);
        $this->clickRow('2026-03-03', 'Small', 60, 6);
        $this->clickRow('2026-02-24', 'Big', 2000, 600);
        $this->clickRow('2026-03-03', 'Big', 2000, 400);

        $this->artisan('ux:weekly-report')->assertExitCode(0);

        $text = $this->text();
        $this->assertLessThan(strpos($text, 'Small'), strpos($text, 'Big'));
        $this->assertStringContainsString('Browse, Big: 20.0% of people who clicked it had trouble', $text);
        $this->assertStringContainsString(', was 30.0%.', $text);
        $this->assertNoCounts();
    }

    public function test_friction_is_compared_device_by_device_so_a_new_source_does_not_read_as_a_change(): void
    {
        $this->fullWeeks();
        // Phones go from 60% to 40%. An app that only began sending this week adds 1,000 people at 10%;
        // pooled, that would read as 60% to 12.7%.
        $this->clickRow('2026-02-24', 'Chat', 100, 60, 0, 0, 'mobile');
        $this->clickRow('2026-03-03', 'Chat', 100, 40, 0, 0, 'mobile');
        $this->clickRow('2026-03-03', 'Chat', 1000, 100, 0, 0, 'app-ios');

        $this->artisan('ux:weekly-report')->assertExitCode(0);

        $text = $this->text();
        $this->assertStringContainsString('Browse, Chat: 40.0% of people who clicked it had trouble', $text);
        $this->assertStringContainsString('People having trouble: down 33%. People clicking: no change.', $text);
        $this->assertStringContainsString('app-ios: nothing the week before, so not compared', $text);
        $this->assertStringNotContainsString('12.7%', $text);
        $this->assertNoCounts();
    }

    public function test_a_device_that_starts_or_stops_sending_is_named_before_the_changes(): void
    {
        $this->fullWeeks();
        $this->clickRow('2026-02-24', 'Card', 5000, 0, 0, 0, 'mobile');
        $this->clickRow('2026-03-03', 'Card', 5000, 0, 0, 0, 'mobile');
        $this->clickRow('2026-03-03', 'Card', 5000, 0, 0, 0, 'app-ios');
        $this->clickRow('2026-02-24', 'Card', 2000, 0, 0, 0, 'tablet');
        $this->clickRow('2026-03-03', 'Card', 5000, 0, 0, 0, 'tablet');

        $this->artisan('ux:weekly-report')->assertExitCode(0);

        $text = $this->text();
        $this->assertStringContainsString('WHO IS COUNTED CHANGED', $text);
        $this->assertStringContainsString('  app-ios: new this week (a release that started sending telemetry?)', $text);
        $this->assertStringContainsString('  tablet: 2.5 times as many', $text);
        $this->assertStringNotContainsString("\n  mobile: ", $text);
        // Nobody had trouble in either week, so there is no change to list.
        $this->assertStringContainsString('(nothing moved by more than chance)', $text);
        $this->assertLessThan(strpos($text, 'FIVE CLEAREST'), strpos($text, 'WHO IS COUNTED CHANGED'));
        $this->assertNoCounts();
    }

    public function test_a_telemetry_alarm_is_a_multiple_of_usual_in_plain_names(): void
    {
        $this->fullWeeks();
        DB::table('ux_telemetry_health_daily')->insert(['date' => '2026-03-08', 'metric' => 'event:impressions', 'expected' => 8183, 'observed' => 10888, 'ratio' => 1.331]);
        DB::table('ux_telemetry_health_daily')->insert(['date' => '2026-03-07', 'metric' => 'event:flow', 'expected' => 100, 'observed' => 40, 'ratio' => 0.4]);
        DB::table('ux_telemetry_health_daily')->insert(['date' => '2026-03-06', 'metric' => 'event:new_thing', 'expected' => 100, 'observed' => 500, 'ratio' => 5]);

        $this->artisan('ux:weekly-report')->assertExitCode(0);

        $text = $this->text();
        $this->assertStringContainsString('2026-03-08, things shown on screen: 1.33 times the usual amount', $text);
        $this->assertStringContainsString('2026-03-07, form step events: 0.4 times the usual amount', $text);
        // A metric with no plain name prints as its key.
        $this->assertStringContainsString('2026-03-06, event:new_thing: 5 times the usual amount', $text);
        $this->assertStringNotContainsString('10,888', $text);
        $this->assertNoCounts();
    }

    private function frictionLoki(int $people, array $top, int $total, int $trouble, array $elements): void
    {
        $this->lokiMatrix('/^count\(sum by \(session_id\)/', [[[], $people]]);
        $this->lokiMatrix('/^topk\(2, sum by \(session_id\)/', array_map(fn ($n, $i) => [['session_id' => 's'.$i], $n], $top, array_keys($top)));
        $this->lokiMatrix('/^sum\(count_over_time/', [[[], $total]]);
        $this->lokiMatrix('/^sum\(count_over_time.*repeat_no_api="true" \[7d\]\)\)$/', [[[], $trouble]]);
        $this->lokiMatrix('/^topk\(3, sum by \(tag, label\)/', $elements);
    }

    public function test_a_friction_change_says_what_share_of_the_trouble_each_element_had(): void
    {
        $this->fullWeeks();
        $this->clickRow('2026-02-24', 'VolunteerOpportunity', 100, 20, 0, 0, 'mobile');
        $this->clickRow('2026-03-03', 'VolunteerOpportunity', 347, 12, 0, 196, 'mobile');
        $this->frictionLoki(21, [40, 38], 347, 200, [
            [['tag' => 'a', 'label' => 'Volunteer Secretary'], 82],
            [['tag' => 'img', 'label' => ''], 2],
            [['tag' => 'a', 'label' => str_repeat('x', 60)], 15],
        ]);

        $this->artisan('ux:weekly-report')->assertExitCode(0);

        $text = $this->text();
        $this->assertStringNotContainsString('read this change as theirs', $text);
        $this->assertStringContainsString('Trouble clicks were on: a "Volunteer Secretary" (41% of the trouble); a "'.str_repeat('x', 40).'..." (8% of the trouble); img with no label (1% of the trouble).', $text);
        $this->assertTrue($this->lokiSent('page_route="browse-term"', 'component="VolunteerOpportunity"', '|= "VolunteerOpportunity"'));
        $this->assertStringContainsString('See it: https://mod.example.org/sysadmin?tab=ux&ux_section=pages&ux_open=friction&ux_period=custom&ux_from=2026-03-02&ux_to=2026-03-08', $text);
        $this->assertNoCounts();
    }

    public function test_a_change_made_mostly_by_two_people_says_so(): void
    {
        $this->fullWeeks();
        $this->clickRow('2026-02-24', 'ChatMessage', 159, 111, 0, 0, 'desktop');
        $this->clickRow('2026-03-03', 'ChatMessage', 1675, 225, 0, 0, 'desktop', [140, 60]);
        $this->frictionLoki(133, [867, 551], 1675, 225, []);

        $this->artisan('ux:weekly-report')->assertExitCode(0);

        $this->assertStringContainsString('Just two people made 85% of the clicks, so read this change as theirs.', $this->text());
        $this->assertNoCounts();
    }

    public function test_when_loki_does_not_answer_the_mail_says_so(): void
    {
        $this->fullWeeks();
        $this->clickRow('2026-02-24', 'PostCard', 100, 0, 1, 0);
        $this->clickRow('2026-03-03', 'PostCard', 100, 5, 6, 0);
        $this->lokiDown = true;

        $this->artisan('ux:weekly-report')->assertExitCode(0);

        $this->assertStringContainsString('What the trouble clicks were on: the log store did not answer, so not known.', $this->text());
    }

    public function test_a_busy_component_over_lokis_series_cap_still_says_what_was_clicked(): void
    {
        $this->fullWeeks();
        $this->clickRow('2026-02-24', 'NavbarMobile', 1000, 100, 0, 0, 'mobile');
        $this->clickRow('2026-03-03', 'NavbarMobile', 1000, 50, 0, 0, 'mobile');
        $this->lokiMatrix('/^sum\(count_over_time/', [[[], 1000]]);
        $this->lokiMatrix('/^sum\(count_over_time.*repeat_no_api="true" \[7d\]\)\)$/', [[[], 50]]);
        $this->lokiMatrix('/^topk\(3, sum by \(tag, label\)/', [[['tag' => 'a', 'label' => 'Chats'], 30]]);
        $this->lokiError('/sum by \(session_id\)/', 400, 'maximum of series (500) reached for a single query');

        $this->artisan('ux:weekly-report')->assertExitCode(0);

        $text = $this->text();
        $this->assertStringContainsString('Trouble clicks were on: a "Chats" (60% of the trouble).', $text);
        $this->assertStringNotContainsString('did not answer', $text);
        $this->assertStringNotContainsString('read this change as theirs', $text);
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
