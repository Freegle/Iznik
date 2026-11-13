<?php

namespace Tests\Unit\Database;

use App\Database\Expressions\CastAs;
use App\Database\Expressions\Collate;
use App\Database\Expressions\Concat;
use App\Database\Expressions\Sha2;
use App\Database\Expressions\SubstringIndex;
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
use App\Database\Expressions\YearMonth;
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

        // PostgreSQL gives jsonb, which PDO cannot read as it stands, so it is read as text there.
        $asText = $this->onPostgres() ? '::text' : '';
        $this->assertSame('5', $this->evaluate((new JsonExtract($doc, Value::of('$.a.b')))->getValue($this->live).$asText));
        $this->assertSame('2', $this->evaluate((new JsonExtract($doc, Value::of('$.c[1]')))->getValue($this->live).$asText));
        $this->assertNull($this->evaluate((new JsonExtract($doc, Value::of('$.missing')))->getValue($this->live)));
    }

    public function test_json_unquote_gives_text_without_quotes(): void
    {
        $doc = Value::of('{"s": "hello", "n": 7, "t": true}');
        $unquote = fn (string $path) => (new JsonUnquote(new JsonExtract($doc, Value::of($path))))->getValue($this->live);

        $this->assertSame('hello', $this->evaluate($unquote('$.s')));
        $this->assertSame('7', $this->evaluate($unquote('$.n')));
        $this->assertSame('true', $this->evaluate($unquote('$.t')));
        $this->assertNull($this->evaluate($unquote('$.missing')));
    }

    /**
     * The one place the two engines answer differently: a member that holds JSON null. MySQL's JSON_UNQUOTE
     * gives the four letters "null"; PostgreSQL gives SQL NULL, the same as for a member that is absent.
     * Code that has to tell the two apart must not read the member through JsonUnquote. This pins the
     * difference so that a change to either rendering shows up here.
     */
    public function test_json_unquote_of_a_json_null_member_differs_between_the_engines(): void
    {
        $unquoted = $this->evaluate((new JsonUnquote(new JsonExtract(Value::of('{"n": null}'), Value::of('$.n'))))->getValue($this->live));

        $this->assertSame($this->onPostgres() ? null : 'null', $unquoted);
    }

    public function test_json_valid_and_contains(): void
    {
        $this->assertSame('("x" IS JSON)', (new JsonValid('x'))->getValue($this->pg));
        $this->assertSame("JSON_VALID(`x`)", (new JsonValid('x'))->getValue($this->mysql));

        $this->assertTrue((bool) $this->evaluate((new JsonValid(Value::of('{"a":1}')))->getValue($this->live)));
        $this->assertFalse((bool) $this->evaluate((new JsonValid(Value::of('{a')))->getValue($this->live)));

        $contains = new JsonContains(Value::of('["a","b"]'), Value::of('"a"'));
        if ($this->onPostgres()) {
            // A literal is quoted by the connection, and MySQL's escapes the double quotes.
            $this->assertSame("(CAST('[\"a\",\"b\"]' AS jsonb) @> CAST('\"a\"' AS jsonb))", $contains->getValue($this->pg));
        }
        $this->assertTrue((bool) $this->evaluate($contains->getValue($this->live)));
        $this->assertFalse((bool) $this->evaluate((new JsonContains(Value::of('["a","b"]'), Value::of('"z"')))->getValue($this->live)));
    }

    // ---- NULL handling

    public function test_concat_gives_null_if_any_operand_is_null(): void
    {
        $this->assertSame('(CAST("a" AS text) || CAST(\'-\' AS text) || CAST("b" AS text))', (new Concat('a', Value::of('-'), 'b'))->getValue($this->pg));
        $this->assertNull($this->evaluate((new Concat(Value::of('a'), Value::of(null), Value::of('b')))->getValue($this->live)));
        $this->assertSame('ab', $this->evaluate((new Concat(Value::of('a'), Value::of('b')))->getValue($this->live)));
    }

    public function test_greatest_and_least_give_null_if_any_operand_is_null(): void
    {
        $this->assertNull($this->evaluate((new Greatest(Value::of(1), Value::of(null), Value::of(3)))->getValue($this->live)));
        $this->assertNull($this->evaluate((new Least(Value::of(1), Value::of(null), Value::of(3)))->getValue($this->live)));
        $this->assertSame(3, $this->evaluate((new Greatest(Value::of(1), Value::of(3)))->getValue($this->live)));
        $this->assertSame(1, $this->evaluate((new Least(Value::of(1), Value::of(3)))->getValue($this->live)));
    }

    // ---- Numbers, text, dates

    public function test_length_counts_bytes(): void
    {
        $this->assertSame('octet_length("a")', (new Length('a'))->getValue($this->pg));
        $this->assertSame(2, $this->evaluate((new Length(Value::of("\u{00e9}")))->getValue($this->live)));
    }

    public function test_month_and_year_are_integers(): void
    {
        $this->assertSame(11, $this->evaluate((new Month(new CastAs(Value::of('2026-11-12'), 'DATE')))->getValue($this->live)));
        $this->assertSame(2026, $this->evaluate((new Year(new CastAs(Value::of('2026-11-12'), 'DATE')))->getValue($this->live)));
    }

    public function test_datediff_is_whole_days_between_the_dates(): void
    {
        $this->assertSame(10, $this->evaluate((new DateDiff(Value::of('2026-11-12 23:59:59'), Value::of('2026-11-02')))->getValue($this->live)));
        $this->assertSame(-1, $this->evaluate((new DateDiff(Value::of('2026-11-01'), Value::of('2026-11-02 12:00:00')))->getValue($this->live)));
    }

    public function test_timestampdiff_truncates_toward_zero(): void
    {
        $start = Value::of('2026-11-12 10:00:00');
        $end = Value::of('2026-11-12 10:10:59');

        $this->assertSame(659, $this->evaluate((new TimestampDiff('SECOND', $start, $end))->getValue($this->live)));
        $this->assertSame(10, $this->evaluate((new TimestampDiff('MINUTE', $start, $end))->getValue($this->live)));
        $this->assertSame(-10, $this->evaluate((new TimestampDiff('MINUTE', $end, $start))->getValue($this->live)));
        $this->assertSame(0, $this->evaluate((new TimestampDiff('HOUR', $start, $end))->getValue($this->live)));
    }

    public function test_round_with_decimals_takes_numeric(): void
    {
        $this->assertSame('ROUND(CAST("a" AS numeric), 2)', (new Round('a', 2))->getValue($this->pg));
        $this->assertEquals(1.24, $this->evaluate((new Round(Value::of(1.2449), 2))->getValue($this->live)));
    }

    public function test_cast_targets_become_postgresql_types(): void
    {
        $this->assertSame('CAST("a" AS bigint)', (new CastAs('a', 'SIGNED'))->getValue($this->pg));
        $this->assertSame('CAST("a" AS numeric(10, 6))', (new CastAs('a', 'DECIMAL', 10, 6))->getValue($this->pg));
        $this->assertSame('CAST("a" AS varchar(3))', (new CastAs('a', 'CHAR', 3))->getValue($this->pg));
        $this->assertSame('CAST("a" AS date)', (new CastAs('a', 'DATE'))->getValue($this->pg));
        $this->assertSame(-5, $this->evaluate((new CastAs(Value::of('-5'), 'SIGNED'))->getValue($this->live)));
        $this->assertSame('123', $this->evaluate((new CastAs(Value::of('12345'), 'CHAR', 3))->getValue($this->live)));
    }

    public function test_collate_ci_compares_without_case(): void
    {
        $this->assertSame('CAST("a" AS citext)', (new Collate('a', 'utf8mb4_unicode_ci'))->getValue($this->pg));
        $this->assertSame('"a" COLLATE "C"', (new Collate('a', 'utf8mb4_bin'))->getValue($this->pg));
        $this->assertSame('`a` COLLATE utf8mb4_unicode_ci', (new Collate('a', 'utf8mb4_unicode_ci'))->getValue($this->mysql));
    }

    /**
     * The join in the nightly stats compares items.name (citext) with a varchar name wrapped in a _ci
     * Collate. Both a capitalised and a lower-case stored name have to match, whatever case the other side is
     * in, as they do on MySQL.
     */
    public function test_collate_ci_matches_a_citext_column_in_either_case(): void
    {
        if (! $this->postgresOnly('citext is the PostgreSQL type; StatsGenerationServiceTest checks the join each engine is sent')) {
            return;
        }

        $ci = (new Collate(Value::of('sofa'), 'utf8mb4_unicode_ci'))->getValue($this->live);
        $this->assertTrue((bool) $this->evaluate("CAST('Sofa' AS citext) = ".$ci));

        $ci = (new Collate(Value::of('SOFA'), 'utf8mb4_unicode_ci'))->getValue($this->live);
        $this->assertTrue((bool) $this->evaluate("CAST('sofa' AS citext) = ".$ci));

        $ci = (new Collate(Value::of('chair'), 'utf8mb4_unicode_ci'))->getValue($this->live);
        $this->assertFalse((bool) $this->evaluate("CAST('Sofa' AS citext) = ".$ci));
    }

    public function test_sha2_is_lowercase_hex_of_the_utf8_bytes(): void
    {
        $this->assertSame("SHA2(`a`, 256)", (new Sha2('a'))->getValue($this->mysql));
        $this->assertSame("encode(sha256(convert_to(CAST(\"a\" AS text), 'UTF8')), 'hex')", (new Sha2('a'))->getValue($this->pg));
        $this->assertSame(hash('sha256', "caf\u{00e9}"), $this->evaluate((new Sha2(Value::of("caf\u{00e9}")))->getValue($this->live)));
        $this->assertSame(hash('sha512', 'x'), $this->evaluate((new Sha2(Value::of('x'), 512))->getValue($this->live)));
    }

    public function test_sha2_refuses_an_unknown_length(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Sha2('a', 100);
    }

    public function test_substring_index_takes_the_first_or_last_part(): void
    {
        $this->assertSame("SUBSTRING_INDEX(`email`, '@', -1)", (new SubstringIndex('email', '@', -1))->getValue($this->mysql));
        $this->assertSame('example.org', $this->evaluate((new SubstringIndex(Value::of('a@b@example.org'), '@', -1))->getValue($this->live)));
        $this->assertSame('a', $this->evaluate((new SubstringIndex(Value::of('a@b@example.org'), '@', 1))->getValue($this->live)));
        $this->assertSame('none', $this->evaluate((new SubstringIndex(Value::of('none'), '@', -1))->getValue($this->live)));
    }

    public function test_substring_index_refuses_a_count_it_cannot_translate(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new SubstringIndex('a', '.', 2))->getValue($this->pg);
    }

    public function test_year_month_is_the_year_and_month_as_text(): void
    {
        $this->assertSame("DATE_FORMAT(`a`, '%Y-%m')", (new YearMonth('a'))->getValue($this->mysql));
        $this->assertSame("to_char(\"a\", 'YYYY-MM')", (new YearMonth('a'))->getValue($this->pg));
        $this->assertSame('2026-11', $this->evaluate((new YearMonth(new CastAs(Value::of('2026-11-12 10:00:00'), 'DATETIME')))->getValue($this->live)));
    }
}
