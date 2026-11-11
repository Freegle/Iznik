<?php

namespace Tests\Unit\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Facade;
use Tests\TestCase;

/**
 * Per-command cron logs are appended to, never overwritten, so one run's failure survives the
 * next run. logs:rotate (LogRotationService::rotateLive) keeps them bounded.
 */
class CronLogAppendTest extends TestCase
{
    public function test_every_scheduled_command_with_a_log_file_appends_to_it(): void
    {
        $schedule = new Schedule();
        $this->app->instance(Schedule::class, $schedule);
        Facade::clearResolvedInstances();

        require base_path('routes/console.php');

        $logged = 0;
        $overwriting = [];

        foreach ($schedule->events() as $event) {
            if ($event->output === null || $event->output === '/dev/null') {
                continue;
            }

            $logged++;
            if (!$event->shouldAppendOutput) {
                $overwriting[] = $event->command;
            }
        }

        $this->assertGreaterThan(0, $logged);
        $this->assertSame([], $overwriting, 'These scheduled commands overwrite their log on each run');
    }
}
