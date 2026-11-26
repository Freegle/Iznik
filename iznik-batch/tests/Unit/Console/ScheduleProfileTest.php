<?php

namespace Tests\Unit\Console;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Facade;
use Tests\TestCase;

/**
 * freegle.schedule.profile and freegle.schedule.overlay, the two switches at
 * the top of routes/console.php. The defaults must reproduce Freegle's full
 * schedule exactly; the overlay-only profile must run nothing but the overlay.
 */
class ScheduleProfileTest extends TestCase
{
    private const OVERLAY_MARKER = 'deployment overlay marker';

    /**
     * Load routes/console.php into a fresh Schedule under the given switches.
     */
    private function loadSchedule(string $profile, ?string $overlay): Schedule
    {
        config([
            'freegle.schedule.profile' => $profile,
            'freegle.schedule.overlay' => $overlay,
        ]);

        $schedule = new Schedule();
        $this->app->instance(Schedule::class, $schedule);
        Facade::clearResolvedInstances();

        require base_path('routes/console.php');

        return $schedule;
    }

    /**
     * @return string[] descriptions of the scheduled events
     */
    private function descriptions(Schedule $schedule): array
    {
        return array_map(fn (Event $e) => (string) $e->description, $schedule->events());
    }

    public function test_default_profile_runs_the_full_freegle_schedule(): void
    {
        $schedule = $this->loadSchedule('full', null);

        $this->assertGreaterThan(20, count($schedule->events()), 'the full Freegle schedule should be loaded');
        $this->assertNotContains(self::OVERLAY_MARKER, $this->descriptions($schedule));
    }

    public function test_missing_overlay_file_is_ignored(): void
    {
        $schedule = $this->loadSchedule('full', 'routes/does-not-exist.php');

        $this->assertGreaterThan(20, count($schedule->events()));
    }

    public function test_overlay_adds_to_the_full_schedule(): void
    {
        $schedule = $this->loadSchedule('full', 'tests/Fixtures/console.deployment.php');

        $this->assertGreaterThan(20, count($schedule->events()));
        $this->assertContains(self::OVERLAY_MARKER, $this->descriptions($schedule));
    }

    public function test_overlay_only_profile_runs_nothing_but_the_overlay(): void
    {
        $schedule = $this->loadSchedule('overlay-only', 'tests/Fixtures/console.deployment.php');

        $this->assertSame([self::OVERLAY_MARKER], $this->descriptions($schedule));
    }

    public function test_overlay_only_with_no_overlay_schedules_nothing(): void
    {
        $schedule = $this->loadSchedule('overlay-only', null);

        $this->assertSame([], $schedule->events());
    }

    public function test_absolute_overlay_path_is_honoured(): void
    {
        $schedule = $this->loadSchedule('overlay-only', base_path('tests/Fixtures/console.deployment.php'));

        $this->assertSame([self::OVERLAY_MARKER], $this->descriptions($schedule));
    }

    public function test_unknown_profile_value_falls_back_to_full(): void
    {
        $schedule = $this->loadSchedule('minmal', null);

        $this->assertGreaterThan(20, count($schedule->events()), 'a typo must never silently empty the schedule');
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        parent::tearDown();
    }
}
