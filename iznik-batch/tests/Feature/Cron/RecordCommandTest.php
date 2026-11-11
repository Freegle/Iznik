<?php

namespace Tests\Feature\Cron;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RecordCommandTest extends TestCase
{
    private const JOB = 'freegle-maint test';

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('cron_job_status')->where('command', self::JOB)->delete();
    }

    public function test_a_success_is_recorded_with_exit_code_zero(): void
    {
        $this->artisan('cron:record', [
            'job' => self::JOB,
            'status' => 'success',
            'summary' => 'Dry run: bulk2: nothing to patch',
            '--started' => '2026-10-14T02:15:00Z',
        ])->assertSuccessful();

        $row = DB::table('cron_job_status')->where('command', self::JOB)->first();
        $this->assertSame(0, (int) $row->last_exit_code);
        $this->assertSame('Dry run: bulk2: nothing to patch', $row->last_output);
        $this->assertSame('2026-10-14 02:15:00', Carbon::parse($row->last_run_at)->utc()->format('Y-m-d H:i:s'));
        $this->assertNotNull($row->last_finished_at);
    }

    public function test_a_second_run_updates_the_same_row(): void
    {
        $this->artisan('cron:record', ['job' => self::JOB, 'status' => 'success', 'summary' => 'first'])->assertSuccessful();
        $this->artisan('cron:record', ['job' => self::JOB, 'status' => 'failure', 'summary' => 'second'])->assertSuccessful();

        $rows = DB::table('cron_job_status')->where('command', self::JOB)->get();
        $this->assertCount(1, $rows);
        $this->assertSame(1, (int) $rows[0]->last_exit_code);
        $this->assertSame('second', $rows[0]->last_output);
    }

    public function test_an_unknown_status_is_refused_and_nothing_is_written(): void
    {
        $this->artisan('cron:record', ['job' => self::JOB, 'status' => 'skipped', 'summary' => 'x'])->assertFailed();

        $this->assertFalse(DB::table('cron_job_status')->where('command', self::JOB)->exists());
    }

    public function test_an_unparseable_start_is_refused_and_nothing_is_written(): void
    {
        $this->artisan('cron:record', ['job' => self::JOB, 'status' => 'success', 'summary' => 'x', '--started' => 'not a date'])->assertFailed();

        $this->assertFalse(DB::table('cron_job_status')->where('command', self::JOB)->exists());
    }
}
