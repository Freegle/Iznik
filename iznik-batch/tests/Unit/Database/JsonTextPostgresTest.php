<?php

namespace Tests\Unit\Database;

use App\Database\Expressions\JsonLength;
use App\Database\Expressions\JsonNumber;
use App\Database\Expressions\Value;
use App\Database\JsonTextPostgresConnection;
use App\Database\JsonTextPostgresGrammar;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** JSON paths in the query builder, and JSON numbers in expressions, on a text column holding JSON. */
class JsonTextPostgresTest extends TestCase
{
    private function pgGrammar(): JsonTextPostgresGrammar
    {
        return new JsonTextPostgresGrammar(new JsonTextPostgresConnection(fn () => null));
    }

    public function test_json_path_casts_a_text_column_to_jsonb(): void
    {
        $sql = $this->pgGrammar()->wrap('users.settings->a->b');

        $this->assertSame('"users"."settings"::jsonb->\'a\'->>\'b\'', $sql);
    }

    public function test_json_number_renders_json_extract_on_mysql(): void
    {
        $e = new JsonNumber('settings', Value::of('$.a'));

        $this->assertSame("JSON_EXTRACT(`settings`, '\$.a')", $e->getValue(new MySqlGrammar(DB::connection())));
    }

    public function test_json_number_reads_numbers_and_booleans_on_postgresql(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The suite is not running on PostgreSQL.');
        }

        $doc = Value::of('{"n": 7, "z": 0, "t": true, "f": false, "s": "x"}');
        $read = fn (string $path) => DB::selectOne(
            'SELECT '.(new JsonNumber($doc, Value::of($path)))->getValue(DB::connection()->getQueryGrammar()).' AS result'
        )->result;

        $this->assertEquals(7, $read('$.n'));
        $this->assertEquals(0, $read('$.z'));
        $this->assertEquals(1, $read('$.t'));
        $this->assertEquals(0, $read('$.f'));
        $this->assertNull($read('$.s'));
        $this->assertNull($read('$.missing'));
    }

    public function test_query_builder_json_path_works_on_a_text_column(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The suite is not running on PostgreSQL.');
        }

        $this->assertInstanceOf(JsonTextPostgresGrammar::class, DB::connection()->getQueryGrammar());
        $this->assertTrue(
            (bool) DB::table(DB::raw("(SELECT '{\"a\": {\"b\": true}}'::text AS settings) t"))->where('settings->a->b', true)->exists()
        );
    }

    public function test_json_length_renders_json_length_on_mysql(): void
    {
        $e = new JsonLength('metadata', Value::of('$.ids'));

        $this->assertSame("JSON_LENGTH(`metadata`, '\$.ids')", $e->getValue(new MySqlGrammar(DB::connection())));
    }

    public function test_json_length_counts_array_elements_and_object_members_on_postgresql(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The suite is not running on PostgreSQL.');
        }

        $doc = Value::of('{"a": [1, 2, 3], "o": {"x": 1, "y": 2}, "s": "text", "e": []}');
        $length = fn (string $path) => DB::selectOne(
            'SELECT '.(new JsonLength($doc, Value::of($path)))->getValue(DB::connection()->getQueryGrammar()).' AS result'
        )->result;

        $this->assertEquals(3, $length('$.a'));
        $this->assertEquals(2, $length('$.o'));
        $this->assertEquals(1, $length('$.s'));
        $this->assertEquals(0, $length('$.e'));
        $this->assertNull($length('$.missing'));
    }

    public function test_index_hints_render_nothing_on_postgresql(): void
    {
        $connection = new JsonTextPostgresConnection(fn () => null);

        $this->assertSame(
            'select * from "users" where "id" = ?',
            $connection->table('users')->forceIndex('a')->ignoreIndex('b')->where('id', 1)->toSql()
        );
    }
}
