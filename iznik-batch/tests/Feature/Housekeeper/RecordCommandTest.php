<?php

namespace Tests\Feature\Housekeeper;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RecordCommandTest extends TestCase
{
    private const KEY = 'freegle-maint-test';

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('housekeeper_tasks')->where('task_key', self::KEY)->delete();
    }

    public function test_a_run_is_recorded_with_its_registry_fields(): void
    {
        $this->artisan('housekeeper:record', [
            'task' => self::KEY,
            'status' => 'success',
            'summary' => 'Dry run: bulk2: nothing to patch',
            '--name' => 'OS patching: mail relay',
            '--description' => 'Wednesday 02:15 UTC',
            '--interval-hours' => 174,
        ])->assertSuccessful();

        $row = DB::table('housekeeper_tasks')->where('task_key', self::KEY)->first();
        $this->assertSame('success', $row->last_status);
        $this->assertSame('Dry run: bulk2: nothing to patch', $row->last_summary);
        $this->assertSame('OS patching: mail relay', $row->name);
        $this->assertSame('Wednesday 02:15 UTC', $row->description);
        $this->assertEquals(174, $row->interval_hours);
        $this->assertEquals(1, $row->enabled);
        $this->assertEquals(0, $row->placeholder);
        $this->assertNull($row->last_log);
        $this->assertNotNull($row->last_run_at);
    }

    public function test_a_second_run_updates_the_same_row_and_keeps_the_name(): void
    {
        $this->artisan('housekeeper:record', ['task' => self::KEY, 'status' => 'success', 'summary' => 'first', '--name' => 'Kept'])->assertSuccessful();
        $this->artisan('housekeeper:record', ['task' => self::KEY, 'status' => 'failure', 'summary' => 'second'])->assertSuccessful();

        $rows = DB::table('housekeeper_tasks')->where('task_key', self::KEY)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('failure', $rows[0]->last_status);
        $this->assertSame('second', $rows[0]->last_summary);
        $this->assertSame('Kept', $rows[0]->name);
    }

    public function test_disabled_greys_the_row_out(): void
    {
        $this->artisan('housekeeper:record', ['task' => self::KEY, 'status' => 'success', 'summary' => 'All live', '--disabled' => true])->assertSuccessful();

        $this->assertEquals(0, DB::table('housekeeper_tasks')->where('task_key', self::KEY)->value('enabled'));
    }

    public function test_an_unknown_status_is_refused_and_nothing_is_written(): void
    {
        $this->artisan('housekeeper:record', ['task' => self::KEY, 'status' => 'skipped', 'summary' => 'x'])->assertFailed();

        $this->assertFalse(DB::table('housekeeper_tasks')->where('task_key', self::KEY)->exists());
    }
}
