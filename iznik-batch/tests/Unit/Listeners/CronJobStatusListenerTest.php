<?php

namespace Tests\Unit\Listeners;

use App\Listeners\CronJobStatusListener;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CronJobStatusListenerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Ensure the table exists (created by migration).
        DB::table('cron_job_status')->where('command', 'LIKE', 'test:%')->delete();
    }

    protected function tearDown(): void
    {
        DB::table('cron_job_status')->where('command', 'LIKE', 'test:%')->delete();
        parent::tearDown();
    }

    public function test_extract_command_parses_artisan_command(): void
    {
        $raw = "'/usr/bin/php' 'artisan' mail:chat:user2user --max-iterations=60 --spool";
        $result = CronJobStatusListener::extractCommand($raw);
        $this->assertEquals('mail:chat:user2user --max-iterations=60 --spool', $result);
    }

    public function test_extract_command_handles_simple_artisan(): void
    {
        $raw = "php artisan deploy:watch";
        $result = CronJobStatusListener::extractCommand($raw);
        $this->assertEquals('deploy:watch', $result);
    }

    public function test_extract_command_returns_null_for_non_artisan(): void
    {
        $raw = "some-other-command --flag";
        $result = CronJobStatusListener::extractCommand($raw);
        $this->assertNull($result);
    }

    public function test_handle_starting_records_last_run_at(): void
    {
        $event = $this->createMockEvent("'/usr/bin/php' 'artisan' test:starting-command");

        $listener = new CronJobStatusListener();
        $listener->handleStarting(new ScheduledTaskStarting($event));

        $row = DB::table('cron_job_status')
            ->where('command', 'test:starting-command')
            ->first();

        $this->assertNotNull($row, 'Should create cron_job_status row');
        $this->assertNotNull($row->last_run_at, 'Should set last_run_at');
    }

    public function test_handle_finished_records_exit_code(): void
    {
        $event = $this->createMockEvent("'/usr/bin/php' 'artisan' test:finished-command");
        $event->exitCode = 0;

        $listener = new CronJobStatusListener();
        $listener->handleFinished(new ScheduledTaskFinished($event, 1.5));

        $row = DB::table('cron_job_status')
            ->where('command', 'test:finished-command')
            ->first();

        $this->assertNotNull($row);
        $this->assertEquals(0, $row->last_exit_code);
        $this->assertNotNull($row->last_finished_at);
    }

    public function test_handle_finished_records_nonzero_exit_code(): void
    {
        $event = $this->createMockEvent("'/usr/bin/php' 'artisan' test:failed-command");
        $event->exitCode = 1;

        $listener = new CronJobStatusListener();
        $listener->handleFinished(new ScheduledTaskFinished($event, 0.5));

        $row = DB::table('cron_job_status')
            ->where('command', 'test:failed-command')
            ->first();

        $this->assertNotNull($row);
        $this->assertEquals(1, $row->last_exit_code);
    }

    public function test_start_then_finish_updates_same_row(): void
    {
        $event = $this->createMockEvent("'/usr/bin/php' 'artisan' test:full-cycle");
        $event->exitCode = 0;

        $listener = new CronJobStatusListener();

        // Start
        $listener->handleStarting(new ScheduledTaskStarting($event));

        $row = DB::table('cron_job_status')
            ->where('command', 'test:full-cycle')
            ->first();
        $this->assertNotNull($row->last_run_at);
        $this->assertNull($row->last_finished_at);

        // Finish
        $listener->handleFinished(new ScheduledTaskFinished($event, 2.0));

        $row = DB::table('cron_job_status')
            ->where('command', 'test:full-cycle')
            ->first();
        $this->assertNotNull($row->last_finished_at);
        $this->assertEquals(0, $row->last_exit_code);
    }

    private function createMockEvent(string $command): Event
    {
        $event = new Event($this->app->make(\Illuminate\Console\Scheduling\EventMutex::class), $command);

        return $event;
    }
}
