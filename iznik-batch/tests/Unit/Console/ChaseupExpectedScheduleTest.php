<?php

namespace Tests\Unit\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Facade;
use Tests\TestCase;

/**
 * chats:chaseup-expected is the migration of V1 cron/chat_chaseup_expected.php. It is the only
 * thing that sends the "WAITING FOR REPLY" email to a member who was expected to reply in a
 * chat and has not, so it has to be on the schedule or no such email is ever sent.
 */
class ChaseupExpectedScheduleTest extends TestCase
{
    public function test_chaseup_expected_is_scheduled(): void
    {
        config([
            'freegle.schedule.profile' => 'full',
            'freegle.schedule.overlay' => null,
        ]);

        $schedule = new Schedule();
        $this->app->instance(Schedule::class, $schedule);
        Facade::clearResolvedInstances();

        require base_path('routes/console.php');

        $found = false;
        foreach ($schedule->events() as $event) {
            if (str_contains((string) $event->command, 'chats:chaseup-expected')) {
                $found = true;
            }
        }

        $this->assertTrue($found, 'chats:chaseup-expected is not scheduled, so WAITING FOR REPLY chase-ups are never sent');
    }
}
