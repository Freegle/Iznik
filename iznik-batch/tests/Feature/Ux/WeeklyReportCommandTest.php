<?php

namespace Tests\Feature\Ux;

use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * ux:weekly-report compares the last seven days with the seven before, using the rollup tables.
 */
class WeeklyReportCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // A Monday. Last week is 2026-03-02..08, the week before 2026-02-23..03-01.
        Carbon::setTestNow('2026-03-09 07:40:00');
        config()->set('freegle.ux.report_to', 'ux-test@example.org');
        config()->set('freegle.sites.mod', 'https://mod.example.org');
        config()->set('mail.default', 'array');
        Mail::purge('array');
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

    private function clickRow(string $date, string $component, int $clicks, int $dead = 0, int $rage = 0, int $repeat = 0): void
    {
        DB::table('ux_component_daily')->insert([
            'date' => $date, 'page' => 'browse-term', 'component' => $component,
            'clicks' => $clicks, 'dead_clicks' => $dead, 'rage_clicks' => $rage, 'repeated_intent' => $repeat,
        ]);
    }

    public function test_sends_nothing_when_there_are_no_rows(): void
    {
        $this->artisan('ux:weekly-report')->assertExitCode(0);

        $this->assertCount(0, $this->mails());
    }

    public function test_mails_the_five_largest_changes_and_ignores_thin_steps(): void
    {
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

    public function test_only_health_rows_still_sends_so_a_broken_pipeline_is_not_silent(): void
    {
        DB::table('ux_telemetry_health_daily')->insert(['date' => '2026-03-04', 'metric' => 'event:flow', 'expected' => 100, 'observed' => 0, 'ratio' => 0]);

        $this->artisan('ux:weekly-report')->assertExitCode(0);

        $this->assertCount(1, $this->mails());
    }

    public function test_it_is_scheduled_for_monday_0740(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'ux:weekly-report'));
        $this->assertNotNull($event);
        $this->assertSame('40 7 * * 1', $event->expression);
    }
}
