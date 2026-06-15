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

    public function test_autoapprove_hold_until_index_exists(): void
    {
        $indexes = collect(
            DB::select("SHOW INDEX FROM messages_groups WHERE Key_name = 'messages_groups_groupid_hold_until_idx'")
        );
        $this->assertNotEmpty($indexes, 'messages_groups_groupid_hold_until_idx index must exist');
    }
}
