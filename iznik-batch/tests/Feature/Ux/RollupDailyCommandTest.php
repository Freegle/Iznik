<?php

namespace Tests\Feature\Ux;

use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakesUxLoki;
use Tests\TestCase;

class RollupDailyCommandTest extends TestCase
{
    use FakesUxLoki;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-06 02:20:00');
        $this->fakeLoki();
        foreach (['ux_flow_daily', 'ux_regret_daily', 'ux_telemetry_health_daily', 'ux_search_daily'] as $t) {
            DB::table($t)->delete();
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_default_is_yesterday_and_backfills_missing_days_only(): void
    {
        // The 3rd already has health rows; the other days do not.
        DB::table('ux_telemetry_health_daily')->insert(['date' => '2026-03-03', 'metric' => 'event:page_view', 'observed' => 5]);

        $this->artisan('ux:rollup-daily')->assertExitCode(0);

        $days = DB::table('ux_search_daily')->orderBy('date')->pluck('date')->all();
        // Yesterday is the 5th; the five days before it are 02-28..03-04, of which the 3rd has health
        // rows. 02-27 began more than seven days ago, past Loki retention, so it is not attempted.
        $this->assertSame(['2026-02-28', '2026-03-01', '2026-03-02', '2026-03-04', '2026-03-05'], $days);
        $this->assertSame(1, DB::table('ux_telemetry_health_daily')->where('date', '2026-03-03')->count());
    }

    public function test_date_option_runs_just_that_day(): void
    {
        $this->artisan('ux:rollup-daily --date=2026-03-01')->assertExitCode(0);

        $this->assertSame(['2026-03-01'], DB::table('ux_search_daily')->pluck('date')->all());
        $this->assertSame(4, DB::table('ux_regret_daily')->where('date', '2026-03-01')->count());
    }

    public function test_bad_date_is_refused(): void
    {
        $this->artisan('ux:rollup-daily --date=yesterday')->assertExitCode(1);
        $this->assertSame(0, DB::table('ux_search_daily')->count());
    }

    public function test_impossible_date_is_refused_not_rolled_into_the_next_month(): void
    {
        $this->artisan('ux:rollup-daily --date=2026-02-31')->assertExitCode(1);
        $this->assertSame(0, DB::table('ux_search_daily')->count());
        $this->assertSame(0, DB::table('ux_regret_daily')->count());
    }

    public function test_loki_down_exits_nonzero_keeps_old_rows_but_still_writes_regret(): void
    {
        DB::table('ux_flow_daily')->insert(['date' => '2026-03-05', 'flow' => 'give', 'step' => 'old', 'entered' => 5]);
        $this->lokiDown = true;

        $this->artisan('ux:rollup-daily --date=2026-03-05')
            ->expectsOutputToContain('Loki')
            ->assertExitCode(1);

        $this->assertSame(['old'], DB::table('ux_flow_daily')->where('date', '2026-03-05')->pluck('step')->all());
        $this->assertSame(0, DB::table('ux_search_daily')->count());
        $this->assertSame(4, DB::table('ux_regret_daily')->where('date', '2026-03-05')->count());
    }

    public function test_it_is_scheduled_daily_at_0220_utc(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'ux:rollup-daily'));
        $this->assertNotNull($event);
        $this->assertSame('20 2 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
