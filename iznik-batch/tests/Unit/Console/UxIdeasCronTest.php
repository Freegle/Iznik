<?php

namespace Tests\Unit\Console;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Facade;
use Tests\TestCase;

/**
 * The UX ideas cron entries: the daily refresh at 05:17 with an overlap lock that outlasts a slow
 * run (a lock that expired mid-run would let a second batch queue behind the first on the helper
 * and spend the subscription twice), and the hourly status sync at :29. Neither is in the
 * backup-drain always_run list. docs/developers/reference/ux-ideas.md.
 */
class UxIdeasCronTest extends TestCase
{
    private function scheduled(string $command): Event
    {
        config([
            'freegle.schedule.profile' => 'full',
            'freegle.schedule.overlay' => null,
        ]);

        $schedule = new Schedule();
        $this->app->instance(Schedule::class, $schedule);
        Facade::clearResolvedInstances();

        require base_path('routes/console.php');

        foreach ($schedule->events() as $event) {
            if (str_contains((string) $event->command, $command)) {
                return $event;
            }
        }

        $this->fail("$command is not scheduled");
    }

    public function test_the_daily_refresh_runs_at_0517_and_its_lock_outlasts_a_slow_run(): void
    {
        $event = $this->scheduled('ux:ideas-refresh');

        $this->assertSame('17 5 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping, 'a second batch must not join a run that is still going');
        $this->assertSame(180, $event->expiresAt, 'seven funnels at up to 16 minutes each take about two hours');
    }

    public function test_the_hourly_sync_runs_at_29_past_and_is_guarded(): void
    {
        $event = $this->scheduled('ux:ideas-sync');

        $this->assertSame('29 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(50, $event->expiresAt);
    }

    public function test_neither_runs_through_the_backup_drain_window(): void
    {
        $alwaysRun = (array) config('freegle.backup.drain.always_run');

        $this->assertNotContains('ux:ideas-refresh', $alwaysRun);
        $this->assertNotContains('ux:ideas-sync', $alwaysRun);
    }
}
