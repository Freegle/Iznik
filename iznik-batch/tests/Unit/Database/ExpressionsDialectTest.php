<?php

namespace Tests\Unit\Database;

use App\Database\Expressions\CurrentSchema;
use App\Database\Expressions\FromUnixTime;
use App\Database\Expressions\JsonSet;
use App\Database\Expressions\Regexp;
use App\Database\Expressions\StartOfHour;
use App\Database\Expressions\StraightJoin;
use App\Database\Expressions\Value;
use Illuminate\Database\Grammar;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Each expression here renders MySQL SQL on the MySQL grammar and its PostgreSQL equivalent on the
 * PostgreSQL grammar. The PostgreSQL text is run when the suite runs on PostgreSQL.
 */
class ExpressionsDialectTest extends TestCase
{
    private Grammar $pg;

    private Grammar $mysql;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pg = new PostgresGrammar(DB::connection());
        $this->mysql = new MySqlGrammar(DB::connection());
    }

    private function evaluate(string $sql): mixed
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The suite is not running on PostgreSQL.');
        }

        return DB::selectOne("SELECT {$sql} AS result")->result;
    }

    public function test_start_of_hour(): void
    {
        $e = new StartOfHour(Value::of('2026-10-21 04:35:12'));

        $this->assertSame("DATE_FORMAT('2026-10-21 04:35:12', '%Y-%m-%d %H:00:00')", $e->getValue($this->mysql));
        $this->assertSame("date_trunc('hour', '2026-10-21 04:35:12')", $e->getValue($this->pg));
        $this->assertStringStartsWith('2026-10-21 04:00:00', (string) $this->evaluate("date_trunc('hour', CAST('2026-10-21 04:35:12' AS timestamp))"));
    }

    public function test_from_unix_time(): void
    {
        $e = new FromUnixTime(Value::of('1000000000'));

        $this->assertSame("FROM_UNIXTIME('1000000000')", $e->getValue($this->mysql));
        $this->assertStringContainsString('to_timestamp', $e->getValue($this->pg));
        $this->assertStringStartsWith('2001-09-09 01:46:40', (string) $this->evaluate((new FromUnixTime(Value::of('1000000000')))->getValue($this->pg)));
    }

    public function test_regexp_is_case_insensitive_on_both(): void
    {
        $e = new Regexp('message', Value::of('^ab+c$'));

        $this->assertSame("`message` REGEXP '^ab+c\$'", $e->getValue($this->mysql));
        $this->assertSame("\"message\" ~* '^ab+c\$'", $e->getValue($this->pg));
        $this->assertTrue($this->evaluate((new Regexp(Value::of('xABBC'), Value::of('abb+c')))->getValue($this->pg)));
        $this->assertFalse($this->evaluate((new Regexp(Value::of('xyz'), Value::of('abb+c')))->getValue($this->pg)));
    }

    public function test_json_set_sets_several_paths(): void
    {
        $e = new JsonSet('settings', ['$.a' => false, '$.b' => null, '$.c.d' => 'x', '$.e' => 3]);

        $this->assertSame("JSON_SET(`settings`, '\$.a', false, '\$.b', NULL, '\$.c.d', 'x', '\$.e', 3)", $e->getValue($this->mysql));
        $this->assertStringStartsWith('jsonb_set(jsonb_set(jsonb_set(jsonb_set(CAST("settings" AS jsonb), \'{a}\'::text[], \'false\'::jsonb)', $e->getValue($this->pg));

        $doc = (new JsonSet(Value::of('{"a": true, "b": 1}'), ['$.a' => false, '$.b' => null, '$.c' => 'x', '$.e' => 3]))->getValue($this->pg);
        $this->assertSame('false', $this->evaluate("({$doc}) ->> 'a'"));
        $this->assertSame('x', $this->evaluate("({$doc}) ->> 'c'"));
        $this->assertSame('3', $this->evaluate("({$doc}) ->> 'e'"));
        $this->assertSame('null', $this->evaluate("(({$doc}) -> 'b')::text"));
    }

    public function test_straight_join_is_a_mysql_hint_only(): void
    {
        $this->assertSame('STRAIGHT_JOIN', (new StraightJoin)->getValue($this->mysql));
        $this->assertSame('', (new StraightJoin)->getValue($this->pg));
    }

    public function test_current_schema(): void
    {
        $this->assertSame('schema()', (new CurrentSchema)->getValue($this->mysql));
        $this->assertSame('current_schema()', (new CurrentSchema)->getValue($this->pg));
        $this->assertNotNull($this->evaluate('current_schema()'));
    }
}
