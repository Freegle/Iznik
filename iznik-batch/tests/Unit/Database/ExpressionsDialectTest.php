<?php

namespace Tests\Unit\Database;

use App\Database\Expressions\CastAs;
use App\Database\Expressions\CurrentSchema;
use App\Database\Expressions\FromUnixTime;
use App\Database\Expressions\JsonExtract;
use App\Database\Expressions\JsonSet;
use App\Database\Expressions\JsonUnquote;
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
 * PostgreSQL grammar. Whichever engine the suite is running on runs its own text, and both must give the
 * same answer.
 */
class ExpressionsDialectTest extends TestCase
{
    private Grammar $pg;

    private Grammar $mysql;

    /** The grammar of the engine the suite is running on. What it renders is what gets run. */
    private Grammar $live;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pg = new PostgresGrammar(DB::connection());
        $this->mysql = new MySqlGrammar(DB::connection());
        $this->live = DB::connection()->getQueryGrammar();
    }

    /** Runs a scalar expression, rendered for the engine the suite is running on, and gives its value. */
    private function evaluate(string $sql): mixed
    {
        return DB::selectOne("SELECT {$sql} AS result")->result;
    }

    public function test_start_of_hour(): void
    {
        $e = new StartOfHour(Value::of('2026-10-21 04:35:12'));

        $this->assertSame("DATE_FORMAT('2026-10-21 04:35:12', '%Y-%m-%d %H:00:00')", $e->getValue($this->mysql));
        $this->assertSame("date_trunc('hour', '2026-10-21 04:35:12')", $e->getValue($this->pg));
        $this->assertStringStartsWith('2026-10-21 04:00:00', (string) $this->evaluate((new StartOfHour(new CastAs(Value::of('2026-10-21 04:35:12'), 'DATETIME')))->getValue($this->live)));
    }

    public function test_from_unix_time(): void
    {
        $e = new FromUnixTime(Value::of('1000000000'));

        $this->assertSame("FROM_UNIXTIME('1000000000')", $e->getValue($this->mysql));
        $this->assertStringContainsString('to_timestamp', $e->getValue($this->pg));
        $this->assertStringStartsWith('2001-09-09 01:46:40', (string) $this->evaluate((new FromUnixTime(Value::of('1000000000')))->getValue($this->live)));
    }

    public function test_regexp_is_case_insensitive_on_both(): void
    {
        $e = new Regexp('message', Value::of('^ab+c$'));

        $this->assertSame("`message` REGEXP '^ab+c\$'", $e->getValue($this->mysql));
        $this->assertSame("\"message\" ~* '^ab+c\$'", $e->getValue($this->pg));
        $this->assertTrue((bool) $this->evaluate((new Regexp(Value::of('xABBC'), Value::of('abb+c')))->getValue($this->live)));
        $this->assertFalse((bool) $this->evaluate((new Regexp(Value::of('xyz'), Value::of('abb+c')))->getValue($this->live)));
    }

    public function test_json_set_sets_several_paths(): void
    {
        $e = new JsonSet('settings', ['$.a' => false, '$.b' => null, '$.c.d' => 'x', '$.e' => 3]);

        $this->assertSame("JSON_SET(`settings`, '\$.a', false, '\$.b', NULL, '\$.c.d', 'x', '\$.e', 3)", $e->getValue($this->mysql));
        $this->assertStringStartsWith('jsonb_set(jsonb_set(jsonb_set(jsonb_set(CAST("settings" AS jsonb), \'{a}\'::text[], \'false\'::jsonb)', $e->getValue($this->pg));

        $doc = new JsonSet(Value::of('{"a": true, "b": 1}'), ['$.a' => false, '$.b' => null, '$.c' => 'x', '$.e' => 3]);
        $member = fn (string $path) => $this->evaluate((new JsonUnquote(new JsonExtract($doc, Value::of($path))))->getValue($this->live));
        $this->assertSame('false', $member('$.a'));
        $this->assertSame('x', $member('$.c'));
        $this->assertSame('3', $member('$.e'));
        // The member set to null is there and holds JSON null, which is not the same as the member being absent.
        // PostgreSQL gives jsonb, which PDO cannot read as it stands, so it is read as text there.
        $asText = DB::connection()->getDriverName() === 'pgsql' ? '::text' : '';
        $this->assertSame('null', $this->evaluate((new JsonExtract($doc, Value::of('$.b')))->getValue($this->live).$asText));
        $this->assertNull($this->evaluate((new JsonExtract($doc, Value::of('$.absent')))->getValue($this->live)));
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
        $this->assertNotNull($this->evaluate((new CurrentSchema)->getValue($this->live)));
    }
}
