<?php

namespace Tests\Unit\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AutoApproveHoldUntilMigrationTest extends TestCase
{
    public function test_autoapprove_hold_until_column_exists(): void
    {
        $this->assertTrue(
            Schema::hasColumn('messages_groups', 'autoapprove_hold_until'),
            'messages_groups.autoapprove_hold_until column must exist after migration'
        );
    }

    public function test_autoapprove_hold_until_has_no_index_of_its_own(): void
    {
        // Every query reads the hold as a trailing filter behind collection = 'Pending', so an
        // index on it would only add write cost on the largest table.
        $indexes = collect(
            DB::select("SHOW INDEX FROM messages_groups WHERE Key_name = 'messages_groups_groupid_hold_until_idx'")
        );
        $this->assertEmpty($indexes, 'messages_groups_groupid_hold_until_idx must not exist');
    }
}
