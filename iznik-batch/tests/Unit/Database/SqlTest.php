<?php

namespace Tests\Unit\Database;

use App\Database\Sql;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SqlTest extends TestCase
{
    public function test_table_is_quoted_for_the_connections_dialect(): void
    {
        $expected = DB::connection()->getDriverName() === 'pgsql' ? '"groups"' : '`groups`';

        $this->assertSame($expected, Sql::table('groups'));
    }
}
