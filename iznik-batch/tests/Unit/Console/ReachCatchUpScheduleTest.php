<?php

namespace Tests\Unit\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Facade;
use Tests\TestCase;

/**
 * The daily reach-mail reconcile re-queues about 1,650 members, and the reach shards then spend
 * 30 to 36 minutes mailing them (about 3,000 CPU-seconds on the batch host). Run at 05:23 UTC
 * that pass spilled into the first minutes of the 07:00 London daily digest in summer
 * (06:00 UTC). It has to finish before the digest begins, and it cannot sit inside the backup
 * drain window, where a once-a-day job is skipped rather than delayed.
 */
class ReachCatchUpScheduleTest extends TestCase
{
    private function reconcileTime(): array
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
            if (str_contains((string) $event->command, 'ripple:reconcile-reach-members')) {
                $parts = preg_split('/\s+/', trim($event->expression));

                return [(int) $parts[1], (int) $parts[0]];
            }
        }

        $this->fail('ripple:reconcile-reach-members is not scheduled');
    }

    public function test_reconcile_runs_after_the_backup_drain_ends(): void
    {
        [$hour, $minute] = $this->reconcileTime();

        [$drainHour, $drainMinute] = array_map('intval', explode(':', (string) config('freegle.backup.drain.start')));
        $drainEnd = $drainHour * 60 + $drainMinute + (int) config('freegle.backup.drain.minutes');

        $this->assertGreaterThanOrEqual($drainEnd, $hour * 60 + $minute, 'inside the drain window it would be skipped');
    }

    public function test_reconcile_leaves_an_hour_before_the_earliest_daily_digest(): void
    {
        [$hour, $minute] = $this->reconcileTime();

        // 07:00 London is 06:00 UTC in summer, the earliest the digest ever starts.
        $this->assertLessThanOrEqual(5 * 60, $hour * 60 + $minute, 'the catch-up pass would run into the digest');
    }
}
