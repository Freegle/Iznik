<?php

namespace Tests\Unit\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LockedByHomeColumnTest extends TestCase
{
    public function test_messages_groups_has_locked_by_home_defaulting_to_zero(): void
    {
        $this->assertTrue(Schema::hasColumn('messages_groups', 'locked_by_home'));

        $col = DB::selectOne("SELECT COLUMN_TYPE AS t, IS_NULLABLE AS n, COLUMN_DEFAULT AS d
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messages_groups' AND COLUMN_NAME = 'locked_by_home'");
        $this->assertSame('tinyint(1)', $col->t);
        $this->assertSame('NO', $col->n);
        $this->assertSame('0', (string) $col->d);
    }
}
