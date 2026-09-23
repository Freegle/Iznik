<?php

namespace Tests\Unit\Console;

use App\Console\BackupDrain;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Facade;
use Tests\TestCase;

/**
 * Nothing that fires once a day may be scheduled inside the backup drain window.
 *
 * The drain gives every scheduled event a skip() filter. Laravel's scheduler has no
 * catch-up: a job that is due while the filter says no is not delayed, it is skipped, and a
 * dailyAt() inside the window therefore never runs at all. When the window was first
 * configured, four daily jobs sat inside it (04:00, 04:20 and two at 04:30) and would have
 * silently stopped the night the drain was switched on.
 *
 * Only once-a-day jobs are at risk. An every-minute or every-ten-minutes job is merely
 * delayed to the end of the window, and something like "30 *\/4 * * *" fires again four
 * hours later. So the rule is: literal minute AND literal hour in the cron expression, which
 * is what dailyAt(), weeklyOn() and monthlyOn() produce.
 *
 * The firing time is taken in the EVENT'S timezone, and on a summer date and a winter date.
 * The window is fixed in the app timezone (UTC); a job pinned to London time moves against
 * it by an hour twice a year. The WhatJobs digest-prep sync sat at 05:00 London, which is
 * 04:00 UTC in summer and inside the window, and a UTC-only check called it clear.
 */
class BackupDrainWindowTest extends TestCase
{
    /**
     * Load routes/console.php into a fresh Schedule, the way ScheduleProfileTest does.
     */
    private function loadSchedule(): Schedule
    {
        config([
            'freegle.schedule.profile' => 'full',
            'freegle.schedule.overlay' => null,
        ]);

        $schedule = new Schedule();
        $this->app->instance(Schedule::class, $schedule);
        Facade::clearResolvedInstances();
        BackupDrain::forget();

        require base_path('routes/console.php');

        return $schedule;
    }

    /**
     * The hour and minute of an event that fires at one fixed time of day, or null.
     *
     * @return array{0:int,1:int}|null
     */
    private function fixedTimeOfDay(Event $event): ?array
    {
        $parts = preg_split('/\s+/', trim($event->expression)) ?: [];
        if (count($parts) < 2 || ! ctype_digit($parts[0]) || ! ctype_digit($parts[1])) {
            return null;
        }

        return [(int) $parts[1], (int) $parts[0]];
    }

    /**
     * The instants a fixed-time event fires on the given dates, in the event's own
     * timezone (or the app's when it has none).
     *
     * @param  list<array{0:int,1:int,2:int}>  $dates  [year, month, day]
     * @return list<Carbon>
     */
    private function firings(Event $event, int $hour, int $minute, array $dates): array
    {
        $tz = $event->timezone ?: (config('app.timezone') ?: 'UTC');

        return array_map(
            fn (array $d) => Carbon::create($d[0], $d[1], $d[2], $hour, $minute, 0, $tz),
            $dates
        );
    }

    /** One date in British Summer Time and one in GMT, so a London-pinned job is seen in both. */
    private const DATES = [[2026, 7, 20], [2026, 1, 20]];

    private function describe(Event $event): string
    {
        $command = trim(str_replace("'", '', (string) $event->command));
        if (($at = strpos($command, 'artisan ')) !== false) {
            $command = substr($command, $at + strlen('artisan '));
        }

        return $command !== '' ? $command : (string) $event->description;
    }

    public function test_no_once_a_day_job_is_scheduled_inside_the_default_drain_window(): void
    {
        // The drain is off by default; switch it on with its default window so active()
        // answers for the times the jobs fire at.
        config(['freegle.backup.drain.enabled' => true]);
        $start = (string) config('freegle.backup.drain.start');
        $minutes = (int) config('freegle.backup.drain.minutes');

        $schedule = $this->loadSchedule();

        $this->assertGreaterThan(20, count($schedule->events()), 'the full Freegle schedule should be loaded');

        $inside = [];
        $fixed = 0;

        foreach ($schedule->events() as $event) {
            if (BackupDrain::exempt($event)) {
                continue;
            }

            $time = $this->fixedTimeOfDay($event);
            if ($time === null) {
                continue;
            }

            $fixed++;
            [$hour, $minute] = $time;

            foreach ($this->firings($event, $hour, $minute, self::DATES) as $fires) {
                if (BackupDrain::active($fires)) {
                    $inside[] = sprintf(
                        '%02d:%02d %s (%s, = %s UTC on %s)',
                        $hour,
                        $minute,
                        $this->describe($event),
                        $fires->tzName,
                        $fires->copy()->utc()->format('H:i'),
                        $fires->format('Y-m-d')
                    );
                    break;
                }
            }
        }

        $this->assertGreaterThan(20, $fixed, 'expected plenty of once-a-day jobs to check');
        $this->assertSame(
            [],
            $inside,
            "These fire once a day inside the backup drain window ({$start} for {$minutes} minutes) and would be skipped, not delayed. Move them, or move the window:\n  ".implode("\n  ", $inside)
        );
    }

    public function test_the_backup_itself_sits_inside_the_window(): void
    {
        // The window exists for the backup. If someone moves one without the other the
        // drain holds batch work off for nothing, and the backup competes with it anyway.
        config(['freegle.backup.drain.enabled' => true]);

        $schedule = $this->loadSchedule();

        $backup = null;
        foreach ($schedule->events() as $event) {
            if (str_contains((string) $event->command, 'backup:database')) {
                $backup = $event;
            }
        }

        $this->assertNotNull($backup, 'backup:database should be scheduled');

        [$hour, $minute] = $this->fixedTimeOfDay($backup);
        foreach ($this->firings($backup, $hour, $minute, self::DATES) as $fires) {
            $this->assertTrue(
                BackupDrain::active($fires),
                sprintf('backup:database fires at %02d:%02d on %s, outside the drain window', $hour, $minute, $fires->format('Y-m-d'))
            );
        }
    }
}
