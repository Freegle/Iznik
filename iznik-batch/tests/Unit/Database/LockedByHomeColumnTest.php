<?php

namespace Tests\Unit\Database;

use Illuminate\Support\Facades\Schema;
use Tests\Support\DescribesColumns;
use Tests\TestCase;

class LockedByHomeColumnTest extends TestCase
{
    use DescribesColumns;

    public function test_messages_groups_has_locked_by_home_defaulting_to_zero(): void
    {
        $this->assertTrue(Schema::hasColumn('messages_groups', 'locked_by_home'));

        $this->assertSame(
            ['flag' => true, 'nullable' => false, 'default' => '0', 'values' => null],
            $this->describeColumn('messages_groups', 'locked_by_home')
        );
    }
}
