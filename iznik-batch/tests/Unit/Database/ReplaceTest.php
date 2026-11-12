<?php

namespace Tests\Unit\Database;

use App\Database\Replace;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReplaceTest extends TestCase
{
    public function test_a_replaced_row_takes_the_new_value_and_there_is_still_one_row(): void
    {
        $group = $this->createTestGroup();

        Replace::rows('stats', [['date' => '2026-01-02', 'groupid' => $group->id, 'type' => 'Weight', 'count' => 5]], ['date', 'groupid', 'type']);
        Replace::rows('stats', [['date' => '2026-01-02', 'groupid' => $group->id, 'type' => 'Weight', 'count' => 7]], ['date', 'groupid', 'type']);

        $rows = DB::table('stats')->where('groupid', $group->id)->where('type', 'Weight')->get();

        $this->assertCount(1, $rows);
        $this->assertSame(7, (int) $rows[0]->count);
    }

    public function test_several_rows_in_one_call_are_written_and_the_others_left_alone(): void
    {
        $group = $this->createTestGroup();
        $other = $this->createTestGroup();

        Replace::rows('stats', [['date' => '2026-01-03', 'groupid' => $other->id, 'type' => 'Weight', 'count' => 1]], ['date', 'groupid', 'type']);
        Replace::rows('stats', [
            ['date' => '2026-01-03', 'groupid' => $group->id, 'type' => 'Weight', 'count' => 2],
            ['date' => '2026-01-04', 'groupid' => $group->id, 'type' => 'Weight', 'count' => 3],
        ], ['date', 'groupid', 'type']);

        $this->assertSame(2, DB::table('stats')->where('groupid', $group->id)->count());
        $this->assertSame(1, (int) DB::table('stats')->where('groupid', $other->id)->value('count'));
    }

    public function test_no_rows_does_nothing(): void
    {
        Replace::rows('stats', [], ['date', 'groupid', 'type']);

        $this->assertTrue(true);
    }
}
