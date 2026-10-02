<?php

namespace Tests\Unit\Console;

use App\Console\BackupDrain;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * The nightly backup desyncs a database node, which takes it out of flow control so it
 * deliberately falls behind while xtrabackup runs. Batch work is held off for that window
 * so the desynced node is quiet and nothing reads from it while it is behind.
 *
 * Everything here defaults to off. Merging this changes nothing until BACKUP_DRAIN_ENABLED
 * is set in the batch environment, which is the same shape as the schedule profile and the
 * read/write split.
 */
class BackupDrainTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        BackupDrain::forget();
        parent::tearDown();
    }

    private function configure(array $overrides = []): void
    {
        config()->set('freegle.backup.drain', array_merge([
            'enabled' => true,
            'start' => '03:50',
            'minutes' => 45,
            'always_run' => [],
        ], $overrides));
    }

    private function at(string $time): void
    {
        Carbon::setTestNow(Carbon::parse($time, config('app.timezone')));
    }

    // ------------------------------------------------------------------ window

    public function test_is_never_active_when_disabled(): void
    {
        $this->configure(['enabled' => false]);
        $this->at('2026-09-18 04:00:00');

        $this->assertFalse(BackupDrain::active());
    }

    public function test_is_active_inside_the_window(): void
    {
        $this->configure();
        $this->at('2026-09-18 04:00:00');

        $this->assertTrue(BackupDrain::active());
    }

    public function test_is_not_active_before_or_after(): void
    {
        $this->configure();

        $this->at('2026-09-18 03:49:59');
        $this->assertFalse(BackupDrain::active(), 'a minute before the window');

        $this->at('2026-09-18 04:35:00');
        $this->assertFalse(BackupDrain::active(), 'after the window closes');
    }

    public function test_start_is_inclusive_and_end_is_exclusive(): void
    {
        $this->configure();

        $this->at('2026-09-18 03:50:00');
        $this->assertTrue(BackupDrain::active(), 'the first minute counts');

        // 03:50 + 45 minutes = 04:35, which is the first minute back in service.
        $this->at('2026-09-18 04:34:59');
        $this->assertTrue(BackupDrain::active());

        $this->at('2026-09-18 04:35:00');
        $this->assertFalse(BackupDrain::active());
    }

    public function test_handles_a_window_that_crosses_midnight(): void
    {
        $this->configure(['start' => '23:50', 'minutes' => 30]);

        $this->at('2026-09-18 23:55:00');
        $this->assertTrue(BackupDrain::active(), 'before midnight');

        $this->at('2026-09-19 00:10:00');
        $this->assertTrue(BackupDrain::active(), 'after midnight');

        $this->at('2026-09-19 00:25:00');
        $this->assertFalse(BackupDrain::active(), 'past the end');
    }

    public function test_a_malformed_start_time_leaves_the_drain_off(): void
    {
        // A typo must never hold the whole batch schedule off indefinitely. Same reasoning
        // as the schedule profile, where an unknown value behaves as 'full'.
        $this->configure(['start' => 'not a time']);
        $this->at('2026-09-18 04:00:00');

        $this->assertFalse(BackupDrain::active());
    }

    public function test_a_non_positive_duration_leaves_the_drain_off(): void
    {
        $this->configure(['minutes' => 0]);
        $this->at('2026-09-18 04:00:00');

        $this->assertFalse(BackupDrain::active());
    }

    // ------------------------------------------------------------------ schedule

    private function scheduleWith(string ...$commands): Schedule
    {
        $schedule = new Schedule();
        foreach ($commands as $command) {
            $schedule->command($command)->everyMinute();
        }

        return $schedule;
    }

    private function passes(\Illuminate\Console\Scheduling\Event $event): bool
    {
        return $event->filtersPass($this->app);
    }

    public function test_holds_scheduled_commands_off_during_the_window(): void
    {
        $this->configure();
        $this->at('2026-09-18 04:00:00');

        $schedule = $this->scheduleWith('ripple:expand', 'messages:auto-approve');
        BackupDrain::apply($schedule);

        foreach ($schedule->events() as $event) {
            $this->assertFalse($this->passes($event), "{$event->command} should be held off");
        }
    }

    public function test_lets_everything_run_outside_the_window(): void
    {
        $this->configure();
        $this->at('2026-09-18 12:00:00');

        $schedule = $this->scheduleWith('ripple:expand');
        BackupDrain::apply($schedule);

        foreach ($schedule->events() as $event) {
            $this->assertTrue($this->passes($event));
        }
    }

    public function test_safelisted_commands_still_run_during_the_window(): void
    {
        // Some work is time-critical enough to be worth the contention. The safelist is
        // matched on the command name, so arguments do not have to be repeated.
        $this->configure(['always_run' => ['mail:chat:user2user']]);
        $this->at('2026-09-18 04:00:00');

        $schedule = $this->scheduleWith('mail:chat:user2user --max-iterations=60', 'ripple:expand');
        BackupDrain::apply($schedule);

        $events = $schedule->events();
        $this->assertTrue($this->passes($events[0]), 'safelisted command should run');
        $this->assertFalse($this->passes($events[1]), 'everything else should be held off');
    }

    public function test_the_backup_itself_is_never_held_off(): void
    {
        // The window exists for the backup. Holding the backup back inside its own window
        // would mean it never runs, so this is structural rather than a config entry.
        $this->configure(['always_run' => []]);
        $this->at('2026-09-18 04:00:00');

        $schedule = $this->scheduleWith('backup:database', 'ripple:expand');
        BackupDrain::apply($schedule);

        $events = $schedule->events();
        $this->assertTrue($this->passes($events[0]), 'backup:database must still run');
        $this->assertFalse($this->passes($events[1]));
    }

    public function test_the_sentry_monitored_events_keep_running(): void
    {
        // The scheduler heartbeat and the outcome monitor carry Sentry Crons check-ins.
        // Two consecutive misses raise an issue, and a 45-minute hold is nine misses, so
        // holding them off would page every night about a scheduler that is fine.
        $this->configure(['always_run' => []]);
        $this->at('2026-09-18 04:00:00');

        $schedule = new Schedule();
        $schedule->call(fn () => null)->everyFiveMinutes()->name('scheduler-heartbeat');
        $schedule->command('monitor:scheduled-outcomes')->everyTenMinutes();
        $schedule->call(fn () => null)->everyMinute()->name('some-other-closure');
        BackupDrain::apply($schedule);

        $events = $schedule->events();
        $this->assertTrue($this->passes($events[0]), 'the heartbeat must still check in');
        $this->assertTrue($this->passes($events[1]), 'the outcome monitor must still check in');
        $this->assertFalse($this->passes($events[2]), 'an ordinary closure is held off');
    }

    public function test_safelist_matches_a_named_closure_too(): void
    {
        $this->configure(['always_run' => ['keep-me']]);
        $this->at('2026-09-18 04:00:00');

        $schedule = new Schedule();
        $schedule->call(fn () => null)->everyMinute()->name('keep-me');
        $schedule->call(fn () => null)->everyMinute()->name('hold-me');
        BackupDrain::apply($schedule);

        $events = $schedule->events();
        $this->assertTrue($this->passes($events[0]));
        $this->assertFalse($this->passes($events[1]));
    }

    // ------------------------------------------------------------ explainsBacklog

    private function at2(string $time): Carbon
    {
        return Carbon::parse($time, config('app.timezone'));
    }

    public function test_explains_a_short_threshold_once_the_hold_has_outlasted_it(): void
    {
        // A ten-minute backlog check: nothing created inside the window can be ten minutes
        // old until ten minutes in, so a stale row before then predates the hold and is a
        // real breach. From then until the workers have caught up, it is the drain.
        $this->configure();

        $this->assertFalse(BackupDrain::explainsBacklog(10, $this->at2('2026-09-18 03:39:00')));
        $this->assertFalse(BackupDrain::explainsBacklog(10, $this->at2('2026-09-18 03:50:00')), 'window just opened: a stale row predates it');
        $this->assertFalse(BackupDrain::explainsBacklog(10, $this->at2('2026-09-18 03:59:59')));
        $this->assertTrue(BackupDrain::explainsBacklog(10, $this->at2('2026-09-18 04:01:00')));
        $this->assertTrue(BackupDrain::explainsBacklog(10, $this->at2('2026-09-18 04:34:59')));
        $this->assertTrue(BackupDrain::explainsBacklog(10, $this->at2('2026-09-18 04:44:59')), 'still catching up');
        $this->assertFalse(BackupDrain::explainsBacklog(10, $this->at2('2026-09-18 04:45:00')));
        $this->assertFalse(BackupDrain::explainsBacklog(10, $this->at2('2026-09-18 12:00:00')));
    }

    public function test_never_explains_a_threshold_longer_than_the_window(): void
    {
        // The rippling backlog check allows a day. A 45-minute hold cannot make a row a day
        // late, so that check must keep being assessed - including during the window, and
        // including the morning after. The first live morning it was skipped for 24 hours.
        $this->configure();

        foreach (['2026-09-18 03:50:00', '2026-09-18 04:10:00', '2026-09-18 04:40:00', '2026-09-18 09:00:00', '2026-09-19 03:00:00', '2026-09-19 04:10:00'] as $when) {
            $this->assertFalse(BackupDrain::explainsBacklog(1440, $this->at2($when)), $when);
        }
    }

    public function test_catch_up_grace_is_capped_below_a_long_threshold(): void
    {
        // A 30-minute check is excused after the window only while the workers catch up,
        // not for its own 30 minutes.
        $this->configure();

        $this->assertTrue(BackupDrain::explainsBacklog(30, $this->at2('2026-09-18 04:30:00')));
        $this->assertTrue(BackupDrain::explainsBacklog(30, $this->at2('2026-09-18 04:49:59')));
        $this->assertFalse(BackupDrain::explainsBacklog(30, $this->at2('2026-09-18 04:50:00')));
    }

    public function test_explains_nothing_when_the_drain_is_off(): void
    {
        $this->configure(['enabled' => false]);

        $this->assertFalse(BackupDrain::explainsBacklog(10, $this->at2('2026-09-18 04:10:00')));
    }

    public function test_applying_twice_does_not_double_up(): void
    {
        // routes/console.php is re-evaluated on every scheduler tick, so apply() must be
        // safe to call repeatedly against the same schedule instance.
        $this->configure();
        $this->at('2026-09-18 12:00:00');

        $schedule = $this->scheduleWith('ripple:expand');
        BackupDrain::apply($schedule);
        BackupDrain::apply($schedule);

        $this->assertTrue($this->passes($schedule->events()[0]));
    }

    // ------------------------------------------------------------------ queue workers

    // Holding the schedule off is only half of it: the supervisor workers consume
    // continuously and would keep hitting the database right through the window.
    // AppServiceProvider registers a Looping listener; returning false makes the worker
    // sleep rather than reserve the next job, so nothing is lost.

    public function test_queue_workers_stop_taking_jobs_during_the_window(): void
    {
        $this->configure();
        $this->at('2026-09-18 04:00:00');

        $this->assertFalse(
            \Illuminate\Support\Facades\Event::until(
                new \Illuminate\Queue\Events\Looping('database', 'default')
            )
        );
    }

    public function test_queue_workers_take_jobs_outside_the_window(): void
    {
        $this->configure();
        $this->at('2026-09-18 12:00:00');

        $this->assertTrue(
            \Illuminate\Support\Facades\Event::until(
                new \Illuminate\Queue\Events\Looping('database', 'default')
            )
        );
    }

    public function test_decides_per_tick_not_when_the_schedule_was_defined(): void
    {
        // The filter has to be evaluated at due-check time. If it captured the answer at
        // definition time, a long-lived scheduler process would hold jobs off all day.
        $this->configure();
        $this->at('2026-09-18 12:00:00');

        $schedule = $this->scheduleWith('ripple:expand');
        BackupDrain::apply($schedule);

        $this->at('2026-09-18 04:00:00');
        $this->assertFalse($this->passes($schedule->events()[0]), 'should re-evaluate the clock');
    }
}
