<?php

namespace Tests\Unit\Database;

use App\Database\PostgresBinaryConnection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Binary values go into a bytea column and come back as strings, as they do with a blob column on MySQL.
 */
class PostgresBinaryConnectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL only: the connection class is registered for the pgsql driver.');
        }

        DB::statement('CREATE TEMPORARY TABLE binary_connection_test (id integer PRIMARY KEY, data bytea, note text)');
    }

    public function test_the_pgsql_driver_uses_this_connection(): void
    {
        $this->assertInstanceOf(PostgresBinaryConnection::class, DB::connection());
    }

    public function test_bytes_that_are_not_text_round_trip(): void
    {
        $bytes = pack('f*', 0.1, -3.5, 1.0e10, 0.0).chr(0).chr(255).chr(0xbb);

        DB::table('binary_connection_test')->insert(['id' => 1, 'data' => $bytes]);

        $this->assertSame($bytes, DB::table('binary_connection_test')->where('id', 1)->value('data'));
        $this->assertSame($bytes, DB::selectOne('SELECT data FROM binary_connection_test WHERE id = ?', [1])->data);
    }

    public function test_a_cursor_returns_strings(): void
    {
        $bytes = "\0\1\2\xff";
        DB::table('binary_connection_test')->insert(['id' => 2, 'data' => $bytes]);

        foreach (DB::table('binary_connection_test')->where('id', 2)->cursor() as $row) {
            $this->assertSame($bytes, $row->data);
        }
    }

    public function test_text_stays_text_and_null_stays_null(): void
    {
        DB::table('binary_connection_test')->insert(['id' => 3, 'data' => null, 'note' => "caf\u{00e9} \\ done"]);

        $row = DB::table('binary_connection_test')->where('id', 3)->first();

        $this->assertNull($row->data);
        $this->assertSame("caf\u{00e9} \\ done", $row->note);
    }
}
