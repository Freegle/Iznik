<?php

namespace Tests\Unit\Database;

use App\Database\Expressions\CastAs;
use App\Database\Expressions\Collate;
use App\Database\Expressions\Concat;
use App\Database\Expressions\DateDiff;
use App\Database\Expressions\Greatest;
use App\Database\Expressions\JsonContains;
use App\Database\Expressions\JsonExtract;
use App\Database\Expressions\JsonUnquote;
use App\Database\Expressions\JsonValid;
use App\Database\Expressions\Least;
use App\Database\Expressions\Length;
use App\Database\Expressions\Month;
use App\Database\Expressions\Round;
use App\Database\Expressions\TimestampDiff;
use App\Database\Expressions\Value;
use App\Database\Expressions\Year;
use Illuminate\Database\Grammar;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The PostgreSQL branch of each expression that spells a thing differently there. ExpressionsTest pins the
 * MySQL text; this pins the PostgreSQL text and, when the suite runs on PostgreSQL, runs it and checks
 * the answer is the one MySQL gives.
 */
class ExpressionsPostgresTest extends TestCase
{
    private Grammar $pg;

    private Grammar $mysql;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pg = new PostgresGrammar(DB::connection());
        $this->mysql = new MySqlGrammar(DB::connection());
    }

    private function onPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    /** Runs a scalar expression on PostgreSQL, or skips when the suite is not running on it. */
    private function evaluate(string $sql): mixed
    {
        if (! $this->onPostgres()) {
            $this->markTestSkipped('The suite is not running on PostgreSQL.');
        }

        return DB::selectOne("SELECT {$sql} AS result")->result;
    }

    // ---- JSON

    public function test_json_extract_reads_a_path_from_text_or_jsonb(): void
    {
        $e = new JsonExtract('settings', Value::of('$.a.b'));

        $this->assertSame('(CAST("settings" AS jsonb) #> \'{a,b}\'::text[])', $e->getValue($this->pg));
        $this->assertSame("JSON_EXTRACT(`settings`, '\$.a.b')", $e->getValue($this->mysql));
    }

    public function test_json_extract_takes_index_steps(): void
    {
        $e = new JsonExtract('settings', Value::of('$.a[2].c'));

        $this->assertStringContainsString("'{a,2,c}'", $e->getValue($this->pg));
    }

    public function test_json_extract_refuses_a_path_it_cannot_translate(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new JsonExtract('settings', Value::of('$.a.*')))->getValue($this->pg);
    }

    public function test_json_extract_gives_the_member_and_null_when_it_is_absent(): void
    {
        $doc = Value::of('{"a": {"b": 5}, "c": [1, 2]}');

        $this->assertSame('5', $this->evaluate((new JsonExtract($doc, Value::of('$.a.b')))->getValue($this->pg).'::text'));
        $this->assertSame('2', $this->evaluate((new JsonExtract($doc, Value::of('$.c[1]')))->getValue($this->pg).'::text'));
        $this->assertNull($this->evaluate((new JsonExtract($doc, Value::of('$.missing')))->getValue($this->pg)));
    }

    public function test_json_unquote_gives_text_without_quotes(): void
    {
        $doc = Value::of('{"s": "hello", "n": 7, "t": true}');
        $unquote = fn (string $path) => (new JsonUnquote(new JsonExtract($doc, Value::of($path))))->getValue($this->pg);

        $this->assertSame('hello', $this->evaluate($unquote('$.s')));
        $this->assertSame('7', $this->evaluate($unquote('$.n')));
        $this->assertSame('true', $this->evaluate($unquote('$.t')));
        $this->assertNull($this->evaluate($unquote('$.missing')));
    }

    public function test_json_valid_and_contains(): void
    {
        $this->assertSame('("x" IS JSON)', (new JsonValid('x'))->getValue($this->pg));
        $this->assertSame("JSON_VALID(`x`)", (new JsonValid('x'))->getValue($this->mysql));

        $this->assertTrue($this->evaluate((new JsonValid(Value::of('{"a":1}')))->getValue($this->pg)));
        $this->assertFalse($this->evaluate((new JsonValid(Value::of('{a')))->getValue($this->pg)));

        $contains = new JsonContains(Value::of('["a","b"]'), Value::of('"a"'));
        $this->assertSame("(CAST('[\"a\",\"b\"]' AS jsonb) @> CAST('\"a\"' AS jsonb))", $contains->getValue($this->pg));
        $this->assertTrue($this->evaluate($contains->getValue($this->pg)));
        $this->assertFalse($this->evaluate((new JsonContains(Value::of('["a","b"]'), Value::of('"z"')))->getValue($this->pg)));
    }

    // ---- NULL handling

    public function test_concat_gives_null_if_any_operand_is_null(): void
    {
        $this->assertSame('(CAST("a" AS text) || CAST(\'-\' AS text) || CAST("b" AS text))', (new Concat('a', Value::of('-'), 'b'))->getValue($this->pg));
        $this->assertNull($this->evaluate((new Concat(Value::of('a'), Value::of(null), Value::of('b')))->getValue($this->pg)));
        $this->assertSame('ab', $this->evaluate((new Concat(Value::of('a'), Value::of('b')))->getValue($this->pg)));
    }

    public function test_greatest_and_least_give_null_if_any_operand_is_null(): void
    {
        $this->assertNull($this->evaluate((new Greatest(Value::of(1), Value::of(null), Value::of(3)))->getValue($this->pg)));
        $this->assertNull($this->evaluate((new Least(Value::of(1), Value::of(null), Value::of(3)))->getValue($this->pg)));
        $this->assertSame(3, $this->evaluate((new Greatest(Value::of(1), Value::of(3)))->getValue($this->pg)));
        $this->assertSame(1, $this->evaluate((new Least(Value::of(1), Value::of(3)))->getValue($this->pg)));
    }

    // ---- Numbers, text, dates

    public function test_length_counts_bytes(): void
    {
        $this->assertSame('octet_length("a")', (new Length('a'))->getValue($this->pg));
        $this->assertSame(2, $this->evaluate((new Length(Value::of("\u{00e9}")))->getValue($this->pg)));
    }

    public function test_month_and_year_are_integers(): void
    {
        $this->assertSame(11, $this->evaluate((new Month(new CastAs(Value::of('2026-11-12'), 'DATE')))->getValue($this->pg)));
        $this->assertSame(2026, $this->evaluate((new Year(new CastAs(Value::of('2026-11-12'), 'DATE')))->getValue($this->pg)));
    }

    public function test_datediff_is_whole_days_between_the_dates(): void
    {
        $this->assertSame(10, $this->evaluate((new DateDiff(Value::of('2026-11-12 23:59:59'), Value::of('2026-11-02')))->getValue($this->pg)));
        $this->assertSame(-1, $this->evaluate((new DateDiff(Value::of('2026-11-01'), Value::of('2026-11-02 12:00:00')))->getValue($this->pg)));
    }

    public function test_timestampdiff_truncates_toward_zero(): void
    {
        $start = Value::of('2026-11-12 10:00:00');
        $end = Value::of('2026-11-12 10:10:59');

        $this->assertSame(659, $this->evaluate((new TimestampDiff('SECOND', $start, $end))->getValue($this->pg)));
        $this->assertSame(10, $this->evaluate((new TimestampDiff('MINUTE', $start, $end))->getValue($this->pg)));
        $this->assertSame(-10, $this->evaluate((new TimestampDiff('MINUTE', $end, $start))->getValue($this->pg)));
        $this->assertSame(0, $this->evaluate((new TimestampDiff('HOUR', $start, $end))->getValue($this->pg)));
    }

    public function test_round_with_decimals_takes_numeric(): void
    {
        $this->assertSame('ROUND(CAST("a" AS numeric), 2)', (new Round('a', 2))->getValue($this->pg));
        $this->assertEquals(1.24, $this->evaluate((new Round(Value::of(1.2449), 2))->getValue($this->pg)));
    }

    public function test_cast_targets_become_postgresql_types(): void
    {
        $this->assertSame('CAST("a" AS bigint)', (new CastAs('a', 'SIGNED'))->getValue($this->pg));
        $this->assertSame('CAST("a" AS numeric(10, 6))', (new CastAs('a', 'DECIMAL', 10, 6))->getValue($this->pg));
        $this->assertSame('CAST("a" AS varchar(3))', (new CastAs('a', 'CHAR', 3))->getValue($this->pg));
        $this->assertSame('CAST("a" AS date)', (new CastAs('a', 'DATE'))->getValue($this->pg));
        $this->assertSame(-5, $this->evaluate((new CastAs(Value::of('-5'), 'SIGNED'))->getValue($this->pg)));
        $this->assertSame('123', $this->evaluate((new CastAs(Value::of('12345'), 'CHAR', 3))->getValue($this->pg)));
    }

    public function test_collate_ci_compares_without_case(): void
    {
        $this->assertSame('LOWER("a")', (new Collate('a', 'utf8mb4_unicode_ci'))->getValue($this->pg));
        $this->assertSame('"a" COLLATE "C"', (new Collate('a', 'utf8mb4_bin'))->getValue($this->pg));
        $this->assertSame('`a` COLLATE utf8mb4_unicode_ci', (new Collate('a', 'utf8mb4_unicode_ci'))->getValue($this->mysql));
    }
}
