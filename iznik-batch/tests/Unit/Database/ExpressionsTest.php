<?php

namespace Tests\Unit\Database;

use App\Database\Expressions\CaseWhen;
use App\Database\Expressions\CastAs;
use App\Database\Expressions\Coalesce;
use App\Database\Expressions\Comparison;
use App\Database\Expressions\Concat;
use App\Database\Expressions\ConcatWs;
use App\Database\Expressions\GetMaxDimension;
use App\Database\Expressions\Greatest;
use App\Database\Expressions\Haversine;
use App\Database\Expressions\Least;
use App\Database\Expressions\Length;
use App\Database\Expressions\NullIf;
use App\Database\Expressions\Round;
use App\Database\Expressions\StAsText;
use App\Database\Expressions\StBuffer;
use App\Database\Expressions\StCollect;
use App\Database\Expressions\StContains;
use App\Database\Expressions\StDistanceSphere;
use App\Database\Expressions\StEnvelope;
use App\Database\Expressions\StGeometryType;
use App\Database\Expressions\StGeomFromText;
use App\Database\Expressions\StIntersects;
use App\Database\Expressions\StSimplify;
use App\Database\Expressions\StSrid;
use App\Database\Expressions\StUnion;
use App\Database\Expressions\StWithin;
use App\Database\Expressions\StX;
use App\Database\Expressions\StY;
use App\Database\Expressions\Value;
use Illuminate\Database\Grammar;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use stdClass;
use Tests\TestCase;

/**
 * Every class in App\Database\Expressions is deliberately NOT bound as PDO
 * parameters - the Illuminate\Contracts\Database\Query\Expression contract
 * has no channel for an embedded expression to add values to the enclosing
 * Query\Builder's bindings array (confirmed below: every "via real builder"
 * test asserts an EMPTY bindings array even though literal values appear in
 * the compiled SQL). Literal values are instead rendered through
 * Grammar::escape()/Connection::escape(), which for strings delegates to the
 * PDO driver's quote() method - still injection-safe, just resolved at
 * SQL-compile time. See App\Database\Expressions\Value's docblock.
 *
 * Each expression class is exercised three ways:
 *  - direct getValue($grammar) rendering, asserting the exact SQL string;
 *  - through the REAL Query\Builder call path (->select()/->where()/
 *    ->orderBy()->toSql()/->getBindings()), because Grammar-level rendering
 *    alone does not prove Builder actually accepts and compiles the
 *    expression the way calling code will use it;
 *  - at least one MySQL-executed semantics check, especially NULL handling,
 *    since that is exactly where a hand-rolled SQL fragment most often goes
 *    wrong (e.g. CONCAT vs CONCAT_WS's differing NULL propagation).
 */
class ExpressionsTest extends TestCase
{
    private Grammar $grammar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->grammar = DB::connection()->getQueryGrammar();
    }

    /**
     * Evaluate a raw SQL scalar expression against the real database and
     * return the PHP value. Used for the semantics checks below - no table
     * fixtures are needed since every expression here operates on literal
     * Value::of() operands.
     */
    private function evaluate(string $sql): mixed
    {
        return DB::selectOne("SELECT {$sql} AS result")->result;
    }

    // ------------------------------------------------------------------
    // Value
    // ------------------------------------------------------------------

    public function test_value_escapes_string_literal(): void
    {
        $this->assertSame("'x'", (new Value('x'))->getValue($this->grammar));
    }

    public function test_value_escapes_null(): void
    {
        $this->assertSame('null', (new Value(null))->getValue($this->grammar));
    }

    public function test_value_escapes_int_and_float(): void
    {
        $this->assertSame('5', (new Value(5))->getValue($this->grammar));
        $this->assertSame('5.5', (new Value(5.5))->getValue($this->grammar));
    }

    public function test_value_of_factory(): void
    {
        $this->assertSame("'x'", Value::of('x')->getValue($this->grammar));
    }

    public function test_value_escapes_quotes_safely_and_is_not_bound(): void
    {
        $evil = "'; DROP TABLE users; --";
        $sql = (new Value($evil))->getValue($this->grammar);

        // The literal is embedded directly in the SQL text (this is the
        // documented "escaped, not bound" behaviour) - but it must be
        // properly quote-escaped so it cannot break out of the string.
        $this->assertStringNotContainsString("'; DROP TABLE", $sql);
        $this->assertSame($evil, $this->evaluate($sql));
    }

    // ------------------------------------------------------------------
    // Coalesce
    // ------------------------------------------------------------------

    public function test_coalesce_renders_columns_and_literals(): void
    {
        $sql = (new Coalesce('a', Value::of('x'), null))->getValue($this->grammar);
        $this->assertSame("COALESCE(`a`, 'x', null)", $sql);
    }

    public function test_coalesce_requires_at_least_two_operands(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Coalesce('a');
    }

    public function test_coalesce_via_real_query_builder(): void
    {
        $query = DB::table('users')->select(new Coalesce('firstname', Value::of('n/a')));

        $this->assertSame(
            "select COALESCE(`firstname`, 'n/a') from `users`",
            $query->toSql()
        );
        $this->assertSame([], $query->getBindings());
    }

    public function test_coalesce_semantics_skips_nulls(): void
    {
        $sql = (new Coalesce(Value::of(null), Value::of(null), Value::of(3)))->getValue($this->grammar);
        $this->assertSame(3, $this->evaluate($sql));
    }

    public function test_coalesce_semantics_all_null_is_null(): void
    {
        $sql = (new Coalesce(Value::of(null), Value::of(null)))->getValue($this->grammar);
        $this->assertNull($this->evaluate($sql));
    }

    // ------------------------------------------------------------------
    // NullIf
    // ------------------------------------------------------------------

    public function test_nullif_renders_both_operands(): void
    {
        $sql = (new NullIf('a', Value::of('')))->getValue($this->grammar);
        $this->assertSame("NULLIF(`a`, '')", $sql);
    }

    public function test_nullif_via_real_query_builder(): void
    {
        $query = DB::table('users')->select(new NullIf('firstname', Value::of('')));

        $this->assertSame("select NULLIF(`firstname`, '') from `users`", $query->toSql());
        $this->assertSame([], $query->getBindings());
    }

    public function test_nullif_semantics_equal_is_null(): void
    {
        $sql = (new NullIf(Value::of(5), Value::of(5)))->getValue($this->grammar);
        $this->assertNull($this->evaluate($sql));
    }

    public function test_nullif_semantics_not_equal_returns_left(): void
    {
        $sql = (new NullIf(Value::of(5), Value::of(6)))->getValue($this->grammar);
        $this->assertSame(5, $this->evaluate($sql));
    }

    // ------------------------------------------------------------------
    // Concat
    // ------------------------------------------------------------------

    public function test_concat_renders_all_operands(): void
    {
        $sql = (new Concat('a', Value::of('-'), 'b'))->getValue($this->grammar);
        $this->assertSame("CONCAT(`a`, '-', `b`)", $sql);
    }

    public function test_concat_requires_at_least_two_operands(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Concat('a');
    }

    public function test_concat_via_real_query_builder(): void
    {
        $query = DB::table('users')->select(new Concat('firstname', Value::of(' '), 'lastname'));

        $this->assertSame(
            "select CONCAT(`firstname`, ' ', `lastname`) from `users`",
            $query->toSql()
        );
        $this->assertSame([], $query->getBindings());
    }

    public function test_concat_semantics_any_null_makes_whole_result_null(): void
    {
        // MySQL: CONCAT() returns NULL if ANY argument is NULL - unlike
        // CONCAT_WS(), which merely skips NULL arguments (see below).
        $sql = (new Concat(Value::of('a'), Value::of(null), Value::of('b')))->getValue($this->grammar);
        $this->assertNull($this->evaluate($sql));
    }

    public function test_concat_semantics_no_nulls(): void
    {
        $sql = (new Concat(Value::of('a'), Value::of('b')))->getValue($this->grammar);
        $this->assertSame('ab', $this->evaluate($sql));
    }

    // ------------------------------------------------------------------
    // ConcatWs
    // ------------------------------------------------------------------

    public function test_concatws_renders_separator_first(): void
    {
        $sql = (new ConcatWs(Value::of(', '), 'a', 'b', null))->getValue($this->grammar);
        $this->assertSame("CONCAT_WS(', ', `a`, `b`, null)", $sql);
    }

    public function test_concatws_requires_at_least_one_operand(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ConcatWs(Value::of(','));
    }

    public function test_concatws_via_real_query_builder(): void
    {
        $query = DB::table('users')->select(new ConcatWs(Value::of(' '), 'firstname', 'lastname'));

        $this->assertSame(
            "select CONCAT_WS(' ', `firstname`, `lastname`) from `users`",
            $query->toSql()
        );
        $this->assertSame([], $query->getBindings());
    }

    public function test_concatws_semantics_skips_null_operands(): void
    {
        // Unlike CONCAT(), a NULL operand (not the separator) is silently
        // skipped rather than making the whole result NULL.
        $sql = (new ConcatWs(Value::of(','), Value::of('a'), Value::of(null), Value::of('b')))
            ->getValue($this->grammar);
        $this->assertSame('a,b', $this->evaluate($sql));
    }

    public function test_concatws_semantics_null_separator_is_null(): void
    {
        $sql = (new ConcatWs(Value::of(null), Value::of('a'), Value::of('b')))->getValue($this->grammar);
        $this->assertNull($this->evaluate($sql));
    }

    // ------------------------------------------------------------------
    // Greatest / Least
    // ------------------------------------------------------------------

    public function test_greatest_renders_all_operands(): void
    {
        $sql = (new Greatest('a', 'b', Value::of(5)))->getValue($this->grammar);
        $this->assertSame('GREATEST(`a`, `b`, 5)', $sql);
    }

    public function test_greatest_requires_at_least_two_operands(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Greatest('a');
    }

    public function test_greatest_via_real_query_builder(): void
    {
        $query = DB::table('users')->orderBy(new Greatest(Value::of(1), Value::of(2)));

        $this->assertSame(
            'select * from `users` order by GREATEST(1, 2) asc',
            $query->toSql()
        );
        $this->assertSame([], $query->getBindings());
    }

    public function test_greatest_semantics_any_null_makes_result_null(): void
    {
        // Opposite of Coalesce: GREATEST()/LEAST() return NULL if ANY
        // argument is NULL, rather than skipping it.
        $sql = (new Greatest(Value::of(1), Value::of(null), Value::of(3)))->getValue($this->grammar);
        $this->assertNull($this->evaluate($sql));
    }

    public function test_greatest_semantics_picks_max(): void
    {
        $sql = (new Greatest(Value::of(1), Value::of(5), Value::of(3)))->getValue($this->grammar);
        $this->assertSame(5, $this->evaluate($sql));
    }

    public function test_least_renders_all_operands(): void
    {
        $sql = (new Least('a', 'b', Value::of(5)))->getValue($this->grammar);
        $this->assertSame('LEAST(`a`, `b`, 5)', $sql);
    }

    public function test_least_requires_at_least_two_operands(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Least('a');
    }

    public function test_least_semantics_any_null_makes_result_null(): void
    {
        $sql = (new Least(Value::of(1), Value::of(null), Value::of(3)))->getValue($this->grammar);
        $this->assertNull($this->evaluate($sql));
    }

    public function test_least_semantics_picks_min(): void
    {
        $sql = (new Least(Value::of(1), Value::of(5), Value::of(3)))->getValue($this->grammar);
        $this->assertSame(1, $this->evaluate($sql));
    }

    // ------------------------------------------------------------------
    // Round
    // ------------------------------------------------------------------

    public function test_round_without_decimals(): void
    {
        $sql = (new Round('amount'))->getValue($this->grammar);
        $this->assertSame('ROUND(`amount`)', $sql);
    }

    public function test_round_with_decimals(): void
    {
        $sql = (new Round('amount', 2))->getValue($this->grammar);
        $this->assertSame('ROUND(`amount`, 2)', $sql);
    }

    public function test_round_via_real_query_builder(): void
    {
        $query = DB::table('users')->select(new Round(Value::of(3.14159), 2));

        $this->assertSame('select ROUND(3.14159, 2) from `users`', $query->toSql());
        $this->assertSame([], $query->getBindings());
    }

    public function test_round_semantics(): void
    {
        $sql = (new Round(Value::of(3.14159), 2))->getValue($this->grammar);
        $this->assertSame('3.14', $this->evaluate($sql));
    }

    public function test_round_semantics_null_operand_is_null(): void
    {
        $sql = (new Round(Value::of(null)))->getValue($this->grammar);
        $this->assertNull($this->evaluate($sql));
    }

    // ------------------------------------------------------------------
    // Length
    // ------------------------------------------------------------------

    public function test_length_renders_operand(): void
    {
        $sql = (new Length('name'))->getValue($this->grammar);
        $this->assertSame('LENGTH(`name`)', $sql);
    }

    public function test_length_via_real_query_builder(): void
    {
        $query = DB::table('users')->orderBy(new Length('firstname'), 'desc');

        $this->assertSame(
            'select * from `users` order by LENGTH(`firstname`) desc',
            $query->toSql()
        );
        $this->assertSame([], $query->getBindings());
    }

    public function test_length_semantics(): void
    {
        $sql = (new Length(Value::of('hello')))->getValue($this->grammar);
        $this->assertSame(5, $this->evaluate($sql));
    }

    public function test_length_semantics_null_is_null(): void
    {
        $sql = (new Length(Value::of(null)))->getValue($this->grammar);
        $this->assertNull($this->evaluate($sql));
    }

    // ------------------------------------------------------------------
    // CastAs
    // ------------------------------------------------------------------

    public function test_castas_renders_type_without_params(): void
    {
        $sql = (new CastAs('flag', 'SIGNED'))->getValue($this->grammar);
        $this->assertSame('CAST(`flag` AS SIGNED)', $sql);
    }

    public function test_castas_renders_type_lowercased_input_normalized(): void
    {
        $sql = (new CastAs('flag', 'signed'))->getValue($this->grammar);
        $this->assertSame('CAST(`flag` AS SIGNED)', $sql);
    }

    public function test_castas_renders_single_param_type(): void
    {
        $sql = (new CastAs('id', 'CHAR', 10))->getValue($this->grammar);
        $this->assertSame('CAST(`id` AS CHAR(10))', $sql);
    }

    public function test_castas_renders_precision_and_scale(): void
    {
        $sql = (new CastAs('amount', 'DECIMAL', 10, 2))->getValue($this->grammar);
        $this->assertSame('CAST(`amount` AS DECIMAL(10, 2))', $sql);
    }

    public function test_castas_rejects_unknown_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CastAs('a', 'NOT_A_TYPE');
    }

    public function test_castas_rejects_params_on_a_type_that_does_not_accept_them(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CastAs('a', 'SIGNED', 10);
    }

    public function test_castas_rejects_too_many_params_for_single_param_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CastAs('a', 'CHAR', 10, 2);
    }

    public function test_castas_rejects_too_many_params_for_precision_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CastAs('a', 'DECIMAL', 10, 2, 1);
    }

    public function test_castas_rejects_negative_params(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CastAs('a', 'CHAR', -1);
    }

    public function test_castas_via_real_query_builder(): void
    {
        $query = DB::table('users')->select(new CastAs('id', 'CHAR'));

        $this->assertSame('select CAST(`id` AS CHAR) from `users`', $query->toSql());
        $this->assertSame([], $query->getBindings());
    }

    public function test_castas_semantics_signed_string_to_int(): void
    {
        $sql = (new CastAs(Value::of('42'), 'SIGNED'))->getValue($this->grammar);
        $this->assertSame(42, $this->evaluate($sql));
    }

    public function test_castas_semantics_char_truncates_to_length(): void
    {
        $sql = (new CastAs(Value::of(12345), 'CHAR', 3))->getValue($this->grammar);
        $this->assertSame('123', $this->evaluate($sql));
    }

    public function test_castas_semantics_decimal_precision(): void
    {
        $sql = (new CastAs(Value::of('3.14159'), 'DECIMAL', 10, 2))->getValue($this->grammar);
        $this->assertSame('3.14', $this->evaluate($sql));
    }

    public function test_castas_semantics_null_operand_is_null(): void
    {
        $sql = (new CastAs(Value::of(null), 'SIGNED'))->getValue($this->grammar);
        $this->assertNull($this->evaluate($sql));
    }

    // ------------------------------------------------------------------
    // Comparison (ConditionExpression)
    // ------------------------------------------------------------------

    public function test_comparison_renders_operator_and_operands(): void
    {
        $sql = (new Comparison('status', '=', Value::of('open')))->getValue($this->grammar);
        $this->assertSame("`status` = 'open'", $sql);
    }

    public function test_comparison_normalizes_operator_case_and_whitespace(): void
    {
        $sql = (new Comparison('a', ' like ', Value::of('%x%')))->getValue($this->grammar);
        $this->assertSame("`a` LIKE '%x%'", $sql);
    }

    public function test_comparison_rejects_unknown_operator(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Comparison('a', 'DROP TABLE users; --', 'b');
    }

    public function test_comparison_via_real_where_clause(): void
    {
        // Query\Builder::where() special-cases ConditionExpression as the
        // sole ($column) argument - this is the real call path a caller
        // will use, distinct from Grammar-level rendering alone.
        $query = DB::table('users')->where(new Comparison('id', '>', Value::of(5)));

        $this->assertSame('select * from `users` where `id` > 5', $query->toSql());
        $this->assertSame([], $query->getBindings());
    }

    public function test_comparison_composes_with_native_where_calls(): void
    {
        $query = DB::table('users')
            ->where(new Comparison('id', '>', Value::of(5)))
            ->where('systemrole', '=', 'Admin');

        $this->assertSame(
            'select * from `users` where `id` > 5 and `systemrole` = ?',
            $query->toSql()
        );
        // The native ->where('systemrole', '=', 'Admin') call IS bound as
        // usual - only the ConditionExpression side is unbound/escaped.
        $this->assertSame(['Admin'], $query->getBindings());
    }

    public function test_comparison_semantics_true(): void
    {
        $sql = 'CASE WHEN '.(new Comparison(Value::of(5), '=', Value::of(5)))->getValue($this->grammar).' THEN 1 ELSE 0 END';
        $this->assertSame(1, $this->evaluate($sql));
    }

    public function test_comparison_semantics_false(): void
    {
        $sql = 'CASE WHEN '.(new Comparison(Value::of(5), '=', Value::of(6)))->getValue($this->grammar).' THEN 1 ELSE 0 END';
        $this->assertSame(0, $this->evaluate($sql));
    }

    // ------------------------------------------------------------------
    // CaseWhen
    // ------------------------------------------------------------------

    public function test_casewhen_renders_multiple_whens_and_else(): void
    {
        $case = (new CaseWhen())
            ->when(new Comparison('status', '=', Value::of('open')))->then(Value::of('Open'))
            ->when(new Comparison('status', '=', Value::of('closed')))->then(Value::of('Closed'))
            ->otherwise(Value::of('Unknown'));

        $this->assertSame(
            "CASE WHEN `status` = 'open' THEN 'Open' WHEN `status` = 'closed' THEN 'Closed' ELSE 'Unknown' END",
            $case->getValue($this->grammar)
        );
    }

    public function test_casewhen_without_else(): void
    {
        $case = (new CaseWhen())->when(new Comparison('a', '=', Value::of(1)))->then(Value::of('one'));

        $this->assertSame(
            "CASE WHEN `a` = 1 THEN 'one' END",
            $case->getValue($this->grammar)
        );
    }

    public function test_casewhen_requires_at_least_one_when_then(): void
    {
        $this->expectException(LogicException::class);
        (new CaseWhen())->getValue($this->grammar);
    }

    public function test_casewhen_then_without_when_throws(): void
    {
        $this->expectException(LogicException::class);
        (new CaseWhen())->then(Value::of('x'));
    }

    public function test_casewhen_when_without_then_throws_on_render(): void
    {
        $this->expectException(LogicException::class);
        (new CaseWhen())->when(new Comparison('a', '=', Value::of(1)))->getValue($this->grammar);
    }

    public function test_casewhen_consecutive_when_without_then_throws(): void
    {
        $this->expectException(LogicException::class);
        (new CaseWhen())
            ->when(new Comparison('a', '=', Value::of(1)))
            ->when(new Comparison('b', '=', Value::of(2)));
    }

    public function test_casewhen_via_real_query_builder(): void
    {
        $case = (new CaseWhen())
            ->when(new Comparison('systemrole', '=', Value::of('Admin')))->then(Value::of('admin'))
            ->otherwise(Value::of('member'));

        $query = DB::table('users')->select($case)->where(new Comparison('id', '>', Value::of(1)));

        $this->assertSame(
            "select CASE WHEN `systemrole` = 'Admin' THEN 'admin' ELSE 'member' END from `users` where `id` > 1",
            $query->toSql()
        );
        $this->assertSame([], $query->getBindings());
    }

    public function test_casewhen_semantics_picks_matching_branch(): void
    {
        $case = (new CaseWhen())
            ->when(new Comparison(Value::of(5), '=', Value::of(1)))->then(Value::of('one'))
            ->when(new Comparison(Value::of(5), '=', Value::of(5)))->then(Value::of('five'))
            ->otherwise(Value::of('other'));

        $this->assertSame('five', $this->evaluate($case->getValue($this->grammar)));
    }

    public function test_casewhen_semantics_falls_through_to_else(): void
    {
        $case = (new CaseWhen())
            ->when(new Comparison(Value::of(5), '=', Value::of(1)))->then(Value::of('one'))
            ->otherwise(Value::of('other'));

        $this->assertSame('other', $this->evaluate($case->getValue($this->grammar)));
    }

    public function test_casewhen_semantics_no_matching_branch_no_else_is_null(): void
    {
        $case = (new CaseWhen())->when(new Comparison(Value::of(5), '=', Value::of(1)))->then(Value::of('one'));

        $this->assertNull($this->evaluate($case->getValue($this->grammar)));
    }

    // ------------------------------------------------------------------
    // StContains / StWithin / StIntersects (spatial ConditionExpression)
    // ------------------------------------------------------------------

    public function test_stcontains_renders_both_operands(): void
    {
        $sql = (new StContains('a', 'b'))->getValue($this->grammar);
        $this->assertSame('ST_Contains(`a`, `b`)', $sql);
    }

    public function test_stcontains_via_real_where_clause(): void
    {
        // Query\Builder::where() special-cases ConditionExpression as the
        // sole ($column) argument - the real call path a caller will use.
        $query = DB::table('groups')->select('id')->where(new StContains('polyindex', 'polyindex'));

        $this->assertSame(
            'select `id` from `groups` where ST_Contains(`polyindex`, `polyindex`)',
            $query->toSql()
        );
        $this->assertSame([], $query->getBindings());
    }

    public function test_stcontains_composes_with_native_where_calls(): void
    {
        $query = DB::table('groups')
            ->where(new StContains('polyindex', 'polyindex'))
            ->where('type', '=', 'Freegle');

        $this->assertSame(
            'select * from `groups` where ST_Contains(`polyindex`, `polyindex`) and `type` = ?',
            $query->toSql()
        );
        $this->assertSame(['Freegle'], $query->getBindings());
    }

    public function test_stcontains_semantics_point_inside_polygon_is_true(): void
    {
        $poly = new StGeomFromText(Value::of('POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))'), 3857);
        $point = new StGeomFromText(Value::of('POINT(5 5)'), 3857);
        $sql = (new StContains($poly, $point))->getValue($this->grammar);
        $this->assertSame(1, $this->evaluate($sql));
    }

    public function test_stcontains_semantics_point_outside_polygon_is_false(): void
    {
        $poly = new StGeomFromText(Value::of('POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))'), 3857);
        $point = new StGeomFromText(Value::of('POINT(50 50)'), 3857);
        $sql = (new StContains($poly, $point))->getValue($this->grammar);
        $this->assertSame(0, $this->evaluate($sql));
    }

    public function test_stcontains_semantics_null_operand_is_null(): void
    {
        $point = new StGeomFromText(Value::of('POINT(5 5)'), 3857);
        $sql = (new StContains(Value::of(null), $point))->getValue($this->grammar);
        $this->assertNull($this->evaluate($sql));
    }

    public function test_stcontains_usable_as_an_ordinary_value_not_just_a_predicate(): void
    {
        // ConditionExpression extends Expression, so StContains also
        // composes as a plain value operand - e.g. wrapped in COALESCE(),
        // exactly how ReengageContentService::resolveVolunteer() embeds the
        // same expression in a SELECT list and an ORDER BY CASE WHEN.
        $poly = new StGeomFromText(Value::of('POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))'), 3857);
        $point = new StGeomFromText(Value::of('POINT(5 5)'), 3857);
        $sql = (new Coalesce(new StContains($poly, $point), Value::of(0)))->getValue($this->grammar);
        $this->assertSame(1, $this->evaluate($sql));
    }

    public function test_stwithin_renders_both_operands(): void
    {
        $sql = (new StWithin('a', 'b'))->getValue($this->grammar);
        $this->assertSame('ST_Within(`a`, `b`)', $sql);
    }

    public function test_stwithin_via_real_where_clause(): void
    {
        $query = DB::table('groups')->select('id')->where(new StWithin('polyindex', 'polyindex'));

        $this->assertSame(
            'select `id` from `groups` where ST_Within(`polyindex`, `polyindex`)',
            $query->toSql()
        );
        $this->assertSame([], $query->getBindings());
    }

    public function test_stwithin_semantics_is_the_mirror_of_stcontains(): void
    {
        $poly = new StGeomFromText(Value::of('POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))'), 3857);
        $point = new StGeomFromText(Value::of('POINT(5 5)'), 3857);
        $sql = (new StWithin($point, $poly))->getValue($this->grammar);
        $this->assertSame(1, $this->evaluate($sql));
    }

    public function test_stwithin_semantics_outside_is_false(): void
    {
        $poly = new StGeomFromText(Value::of('POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))'), 3857);
        $point = new StGeomFromText(Value::of('POINT(50 50)'), 3857);
        $sql = (new StWithin($point, $poly))->getValue($this->grammar);
        $this->assertSame(0, $this->evaluate($sql));
    }

    public function test_stwithin_semantics_shrunk_buffer_is_within_original(): void
    {
        // ExpandService::innerExpr() relies on exactly this: a negatively
        // buffered (shrunk) polygon must remain within its own source.
        $poly = new StGeomFromText(Value::of('POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))'), 3857);
        $shrunk = new StBuffer($poly, -1);
        $sql = (new StWithin($shrunk, $poly))->getValue($this->grammar);
        $this->assertSame(1, $this->evaluate($sql));
    }

    public function test_stintersects_renders_both_operands(): void
    {
        $sql = (new StIntersects('a', 'b'))->getValue($this->grammar);
        $this->assertSame('ST_Intersects(`a`, `b`)', $sql);
    }

    public function test_stintersects_via_real_where_clause(): void
    {
        $query = DB::table('groups')->select('id')->where(new StIntersects('polyindex', 'polyindex'));

        $this->assertSame(
            'select `id` from `groups` where ST_Intersects(`polyindex`, `polyindex`)',
            $query->toSql()
        );
        $this->assertSame([], $query->getBindings());
    }

    public function test_stintersects_semantics_overlapping_geometries_is_true(): void
    {
        $poly = new StGeomFromText(Value::of('POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))'), 3857);
        $point = new StGeomFromText(Value::of('POINT(5 5)'), 3857);
        $sql = (new StIntersects($poly, $point))->getValue($this->grammar);
        $this->assertSame(1, $this->evaluate($sql));
    }

    public function test_stintersects_semantics_disjoint_geometries_is_false(): void
    {
        $poly = new StGeomFromText(Value::of('POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))'), 3857);
        $point = new StGeomFromText(Value::of('POINT(50 50)'), 3857);
        $sql = (new StIntersects($poly, $point))->getValue($this->grammar);
        $this->assertSame(0, $this->evaluate($sql));
    }

    public function test_stintersects_semantics_null_operand_is_null(): void
    {
        $poly = new StGeomFromText(Value::of('POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))'), 3857);
        $sql = (new StIntersects($poly, Value::of(null)))->getValue($this->grammar);
        $this->assertNull($this->evaluate($sql));
    }

    // ------------------------------------------------------------------
    // StGeomFromText
    // ------------------------------------------------------------------

    public function test_stgeomfromtext_renders_wkt_and_srid(): void
    {
        $sql = (new StGeomFromText(Value::of('POINT(1 2)'), 3857))->getValue($this->grammar);
        $this->assertSame("ST_GeomFromText('POINT(1 2)', 3857)", $sql);
    }

    public function test_stgeomfromtext_bare_string_wkt_is_a_column_not_literal_wkt(): void
    {
        // Per RendersOperands' universal rule, a bare string operand is a
        // COLUMN reference - a literal WKT string (the overwhelmingly common
        // case for this class) MUST be wrapped in Value::of(); see the class
        // docblock.
        $sql = (new StGeomFromText('wkt_column', 3857))->getValue($this->grammar);
        $this->assertSame('ST_GeomFromText(`wkt_column`, 3857)', $sql);
    }

    public function test_stgeomfromtext_via_real_query_builder(): void
    {
        $query = DB::query()->select(new StGeomFromText(Value::of('POINT(1 2)'), 3857));

        $this->assertSame("select ST_GeomFromText('POINT(1 2)', 3857)", $query->toSql());
        $this->assertSame([], $query->getBindings());
    }

    public function test_stgeomfromtext_semantics_roundtrips_via_stastext(): void
    {
        $sql = (new StAsText(new StGeomFromText(Value::of('POINT(1 2)'), 3857)))->getValue($this->grammar);
        $this->assertSame('POINT(1 2)', $this->evaluate($sql));
    }

    // ------------------------------------------------------------------
    // StAsText
    // ------------------------------------------------------------------

    public function test_stastext_renders_operand(): void
    {
        $sql = (new StAsText('geom'))->getValue($this->grammar);
        $this->assertSame('ST_AsText(`geom`)', $sql);
    }

    public function test_stastext_via_real_query_builder(): void
    {
        $query = DB::table('groups')->select(new StAsText('polyindex'))->limit(1);

        $this->assertSame('select ST_AsText(`polyindex`) from `groups` limit 1', $query->toSql());
        $this->assertSame([], $query->getBindings());
    }

    public function test_stastext_semantics_renders_polygon_wkt(): void
    {
        $geom = new StGeomFromText(Value::of('POLYGON((0 0,0 10,10 10,10 0,0 0))'), 3857);
        $sql = (new StAsText($geom))->getValue($this->grammar);
        $this->assertSame('POLYGON((0 0,0 10,10 10,10 0,0 0))', $this->evaluate($sql));
    }

    public function test_stastext_semantics_null_operand_is_null(): void
    {
        $sql = (new StAsText(Value::of(null)))->getValue($this->grammar);
        $this->assertNull($this->evaluate($sql));
    }

    // ------------------------------------------------------------------
    // StSrid
    // ------------------------------------------------------------------

    public function test_stsrid_renders_1arg_getter_form(): void
    {
        $sql = (new StSrid('geom'))->getValue($this->grammar);
        $this->assertSame('ST_SRID(`geom`)', $sql);
    }

    public function test_stsrid_renders_2arg_setter_form(): void
    {
        $sql = (new StSrid('geom', 3857))->getValue($this->grammar);
        $this->assertSame('ST_SRID(`geom`, 3857)', $sql);
    }

    public function test_stsrid_via_real_query_builder(): void
    {
        $query = DB::table('locations')->select(new StSrid('geometry'))->limit(1);

        $this->assertSame('select ST_SRID(`geometry`) from `locations` limit 1', $query->toSql());
        $this->assertSame([], $query->getBindings());
    }

    public function test_stsrid_semantics_getter_reads_the_labelled_srid(): void
    {
        $geom = new StGeomFromText(Value::of('POINT(5 5)'), 0);
        $sql = (new StSrid($geom))->getValue($this->grammar);
        $this->assertSame(0, $this->evaluate($sql));
    }

    public function test_stsrid_semantics_setter_relabels_without_moving_coordinates(): void
    {
        // DoogalService::fixSpatialIndex() relies on exactly this: ST_SRID's
        // 2-arg setter form must NOT move the point, only relabel it.
        $geom = new StGeomFromText(Value::of('POINT(5 5)'), 0);
        $sql = (new StAsText(new StSrid($geom, 3857)))->getValue($this->grammar);
        $this->assertSame('POINT(5 5)', $this->evaluate($sql));
    }

    public function test_stsrid_semantics_null_operand_is_null(): void
    {
        $sql = (new StSrid(Value::of(null)))->getValue($this->grammar);
        $this->assertNull($this->evaluate($sql));
    }

    // ------------------------------------------------------------------
    // StGeometryType
    // ------------------------------------------------------------------

    public function test_stgeometrytype_renders_operand(): void
    {
        $sql = (new StGeometryType('geom'))->getValue($this->grammar);
        $this->assertSame('ST_GeometryType(`geom`)', $sql);
    }

    public function test_stgeometrytype_via_real_where_clause_composed_with_comparison(): void
    {
        // ST_GeometryType is not itself a ConditionExpression - it composes
        // into one via Comparison, exactly as ReengageContentService and
        // several Ripple services use it: `ST_GeometryType(...) <> 'POINT'`.
        $query = DB::table('groups')->select('id')
            ->where(new Comparison(new StGeometryType('polyindex'), '<>', Value::of('POINT')));

        $this->assertSame(
            "select `id` from `groups` where ST_GeometryType(`polyindex`) <> 'POINT'",
            $query->toSql()
        );
        $this->assertSame([], $query->getBindings());
    }

    public function test_stgeometrytype_semantics_polygon(): void
    {
        $geom = new StGeomFromText(Value::of('POLYGON((0 0,0 10,10 10,10 0,0 0))'), 3857);
        $sql = (new StGeometryType($geom))->getValue($this->grammar);
        $this->assertSame('POLYGON', $this->evaluate($sql));
    }

    public function test_stgeometrytype_semantics_point(): void
    {
        $geom = new StGeomFromText(Value::of('POINT(5 5)'), 3857);
        $sql = (new StGeometryType($geom))->getValue($this->grammar);
        $this->assertSame('POINT', $this->evaluate($sql));
    }

    public function test_stgeometrytype_semantics_null_operand_is_null(): void
    {
        $sql = (new StGeometryType(Value::of(null)))->getValue($this->grammar);
        $this->assertNull($this->evaluate($sql));
    }

    // ------------------------------------------------------------------
    // StX / StY
    // ------------------------------------------------------------------

    public function test_stx_renders_operand(): void
    {
        $sql = (new StX('point'))->getValue($this->grammar);
        $this->assertSame('ST_X(`point`)', $sql);
    }

    public function test_stx_via_real_query_builder(): void
    {
        $query = DB::table('messages_spatial')->select(new StX('point'))->limit(1);

        $this->assertSame('select ST_X(`point`) from `messages_spatial` limit 1', $query->toSql());
        $this->assertSame([], $query->getBindings());
    }

    public function test_stx_semantics_reads_the_x_coordinate(): void
    {
        $geom = new StGeomFromText(Value::of('POINT(5 7)'), 3857);
        $sql = (new StX($geom))->getValue($this->grammar);
        $this->assertEquals(5, $this->evaluate($sql));
    }

    public function test_stx_semantics_null_operand_is_null(): void
    {
        $sql = (new StX(Value::of(null)))->getValue($this->grammar);
        $this->assertNull($this->evaluate($sql));
    }

    public function test_stx_semantics_non_point_operand_throws(): void
    {
        // Verified empirically: MySQL raises error 3516 for a non-Point
        // operand rather than returning NULL - see the class docblock.
        $this->expectException(\Illuminate\Database\QueryException::class);
        $geom = new StGeomFromText(Value::of('POLYGON((0 0,0 1,1 1,1 0,0 0))'), 3857);
        $this->evaluate((new StX($geom))->getValue($this->grammar));
    }

    public function test_sty_renders_operand(): void
    {
        $sql = (new StY('point'))->getValue($this->grammar);
        $this->assertSame('ST_Y(`point`)', $sql);
    }

    public function test_sty_via_real_query_builder(): void
    {
        $query = DB::table('messages_spatial')->select(new StY('point'))->limit(1);

        $this->assertSame('select ST_Y(`point`) from `messages_spatial` limit 1', $query->toSql());
        $this->assertSame([], $query->getBindings());
    }

    public function test_sty_semantics_reads_the_y_coordinate(): void
    {
        $geom = new StGeomFromText(Value::of('POINT(5 7)'), 3857);
        $sql = (new StY($geom))->getValue($this->grammar);
        $this->assertEquals(7, $this->evaluate($sql));
    }

    public function test_sty_semantics_null_operand_is_null(): void
    {
        $sql = (new StY(Value::of(null)))->getValue($this->grammar);
        $this->assertNull($this->evaluate($sql));
    }

    // ------------------------------------------------------------------
    // StDistanceSphere
    // ------------------------------------------------------------------

    public function test_stdistancesphere_renders_both_operands(): void
    {
        $sql = (new StDistanceSphere('a', 'b'))->getValue($this->grammar);
        $this->assertSame('ST_Distance_Sphere(`a`, `b`)', $sql);
    }

    public function test_stdistancesphere_via_real_orderby(): void
    {
        $query = DB::table('groups')->select('id')
            ->orderBy(new StDistanceSphere('point_a', 'point_b'));

        $this->assertSame(
            'select `id` from `groups` order by ST_Distance_Sphere(`point_a`, `point_b`) asc',
            $query->toSql()
        );
        $this->assertSame([], $query->getBindings());
    }

    public function test_stdistancesphere_semantics_one_degree_latitude_is_about_111km(): void
    {
        $a = new StGeomFromText(Value::of('POINT(0 0)'), 4326);
        $b = new StGeomFromText(Value::of('POINT(0 1)'), 4326);
        $sql = (new StDistanceSphere($a, $b))->getValue($this->grammar);
        $this->assertEqualsWithDelta(111195.08, $this->evaluate($sql), 1.0);
    }

    public function test_stdistancesphere_semantics_same_point_is_zero(): void
    {
        $a = new StGeomFromText(Value::of('POINT(0 0)'), 4326);
        $sql = (new StDistanceSphere($a, $a))->getValue($this->grammar);
        $this->assertEquals(0, $this->evaluate($sql));
    }

    public function test_stdistancesphere_semantics_null_operand_is_null(): void
    {
        $a = new StGeomFromText(Value::of('POINT(0 0)'), 4326);
        $sql = (new StDistanceSphere(Value::of(null), $a))->getValue($this->grammar);
        $this->assertNull($this->evaluate($sql));
    }

    // ------------------------------------------------------------------
    // StEnvelope
    // ------------------------------------------------------------------

    public function test_stenvelope_renders_operand(): void
    {
        $sql = (new StEnvelope('geom'))->getValue($this->grammar);
        $this->assertSame('ST_Envelope(`geom`)', $sql);
    }

    public function test_stenvelope_via_real_query_builder(): void
    {
        $query = DB::table('groups')->select(new StAsText(new StEnvelope('polyindex')))->limit(1);

        $this->assertSame(
            'select ST_AsText(ST_Envelope(`polyindex`)) from `groups` limit 1',
            $query->toSql()
        );
        $this->assertSame([], $query->getBindings());
    }

    public function test_stenvelope_semantics_bounding_rectangle_of_a_diagonal_line(): void
    {
        $geom = new StGeomFromText(Value::of('LINESTRING(0 0, 10 10)'), 3857);
        $sql = (new StAsText(new StEnvelope($geom)))->getValue($this->grammar);
        $this->assertSame('POLYGON((0 0,10 0,10 10,0 10,0 0))', $this->evaluate($sql));
    }

    public function test_stenvelope_semantics_single_point_is_unchanged(): void
    {
        $geom = new StGeomFromText(Value::of('POINT(3 4)'), 3857);
        $sql = (new StAsText(new StEnvelope($geom)))->getValue($this->grammar);
        $this->assertSame('POINT(3 4)', $this->evaluate($sql));
    }

    public function test_stenvelope_semantics_null_operand_is_null(): void
    {
        $sql = (new StAsText(new StEnvelope(Value::of(null))))->getValue($this->grammar);
        $this->assertNull($this->evaluate($sql));
    }

    // ------------------------------------------------------------------
    // StBuffer
    // ------------------------------------------------------------------

    public function test_stbuffer_renders_positive_distance(): void
    {
        $sql = (new StBuffer('geom', 2))->getValue($this->grammar);
        $this->assertSame('ST_Buffer(`geom`, 2)', $sql);
    }

    public function test_stbuffer_renders_negative_distance(): void
    {
        // ReachBoundsService::innerExpr() shrinks via a negative distance.
        $sql = (new StBuffer('geom', -0.002))->getValue($this->grammar);
        $this->assertSame('ST_Buffer(`geom`, -0.002)', $sql);
    }

    public function test_stbuffer_via_real_query_builder(): void
    {
        $query = DB::table('groups')->select(new StAsText(new StBuffer('polyindex', 2)))->limit(1);

        $this->assertSame(
            'select ST_AsText(ST_Buffer(`polyindex`, 2)) from `groups` limit 1',
            $query->toSql()
        );
        $this->assertSame([], $query->getBindings());
    }

    public function test_stbuffer_semantics_grown_polygon_contains_the_original(): void
    {
        $poly = new StGeomFromText(Value::of('POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))'), 3857);
        $sql = (new StContains(new StBuffer($poly, 1), $poly))->getValue($this->grammar);
        $this->assertSame(1, $this->evaluate($sql));
    }

    public function test_stbuffer_semantics_zero_distance_repair_idiom_preserves_area(): void
    {
        // ExpandService::simplifyPolygonWkt() wraps ST_Simplify's output in
        // ST_Buffer(geom, 0) to repair possible self-intersections. On an
        // already-valid polygon this must be an area-preserving no-op.
        $poly = new StGeomFromText(Value::of('POLYGON((0 0,0 10,10 10,10 0,0 0))'), 3857);
        $before = "ST_Area({$poly->getValue($this->grammar)})";
        $after = 'ST_Area('.(new StBuffer($poly, 0))->getValue($this->grammar).')';
        $this->assertSame($this->evaluate($before), $this->evaluate($after));
    }

    public function test_stbuffer_semantics_null_operand_is_null(): void
    {
        $sql = (new StAsText(new StBuffer(Value::of(null), 2)))->getValue($this->grammar);
        $this->assertNull($this->evaluate($sql));
    }

    // ------------------------------------------------------------------
    // StSimplify
    // ------------------------------------------------------------------

    public function test_stsimplify_renders_both_operands(): void
    {
        $sql = (new StSimplify('geom', 1))->getValue($this->grammar);
        $this->assertSame('ST_Simplify(`geom`, 1)', $sql);
    }

    public function test_stsimplify_via_real_query_builder(): void
    {
        $query = DB::table('groups')->select(new StAsText(new StSimplify('polyindex', 1)))->limit(1);

        $this->assertSame(
            'select ST_AsText(ST_Simplify(`polyindex`, 1)) from `groups` limit 1',
            $query->toSql()
        );
        $this->assertSame([], $query->getBindings());
    }

    public function test_stsimplify_semantics_removes_a_redundant_collinear_vertex(): void
    {
        // (5 0) sits exactly on the line from (0 0) to (10 0) and is removed
        // by Douglas-Peucker simplification at tolerance 1.
        $line = new StGeomFromText(Value::of('LINESTRING(0 0, 5 0, 10 0, 10 10)'), 3857);
        $originalPoints = $this->evaluate("ST_NumPoints({$line->getValue($this->grammar)})");
        $this->assertSame(4, $originalPoints);

        $sql = "ST_NumPoints(".(new StSimplify($line, 1))->getValue($this->grammar).')';
        $this->assertSame(3, $this->evaluate($sql));
    }

    public function test_stsimplify_semantics_tolerance_larger_than_extent_returns_null(): void
    {
        // Verified empirically and load-bearing for
        // Ripple\ExpandService::storeWithUndoLogShrink(), whose comment
        // explains why its tolerance ladder must stay degree-scale, not
        // metre-scale, for exactly this reason.
        $small = new StGeomFromText(Value::of('POLYGON((0 0, 0 1, 1 1, 1 0, 0 0))'), 3857);
        $sql = (new StAsText(new StSimplify($small, 1000)))->getValue($this->grammar);
        $this->assertNull($this->evaluate($sql));
    }

    public function test_stsimplify_semantics_null_operand_is_null(): void
    {
        $sql = (new StAsText(new StSimplify(Value::of(null), 1)))->getValue($this->grammar);
        $this->assertNull($this->evaluate($sql));
    }

    // ------------------------------------------------------------------
    // StUnion
    // ------------------------------------------------------------------

    public function test_stunion_renders_both_operands(): void
    {
        $sql = (new StUnion('a', 'b'))->getValue($this->grammar);
        $this->assertSame('ST_Union(`a`, `b`)', $sql);
    }

    public function test_stunion_via_real_query_builder(): void
    {
        $query = DB::table('groups')->select(new StAsText(new StUnion('polyindex', 'polyindex')))->limit(1);

        $this->assertSame(
            'select ST_AsText(ST_Union(`polyindex`, `polyindex`)) from `groups` limit 1',
            $query->toSql()
        );
        $this->assertSame([], $query->getBindings());
    }

    public function test_stunion_semantics_combines_two_points(): void
    {
        $a = new StGeomFromText(Value::of('POINT(1 1)'), 3857);
        $b = new StGeomFromText(Value::of('POINT(2 2)'), 3857);
        $sql = (new StAsText(new StUnion($a, $b)))->getValue($this->grammar);
        $this->assertSame('MULTIPOINT((1 1),(2 2))', $this->evaluate($sql));
    }

    public function test_stunion_semantics_chains_for_more_than_two_geometries(): void
    {
        // MySQL's ST_Union is strictly binary (verified empirically - a
        // 2-argument non-aggregate call is its only form). Combining 3+
        // geometries means nesting, exactly as
        // Console\Commands\Ripple\ExpandCommand builds a right-nested chain
        // for --within-group.
        $a = new StGeomFromText(Value::of('POINT(1 1)'), 3857);
        $b = new StGeomFromText(Value::of('POINT(2 2)'), 3857);
        $c = new StGeomFromText(Value::of('POINT(3 3)'), 3857);
        $sql = (new StAsText(new StUnion($a, new StUnion($b, $c))))->getValue($this->grammar);
        $this->assertSame('MULTIPOINT((1 1),(2 2),(3 3))', $this->evaluate($sql));
    }

    public function test_stunion_semantics_null_operand_is_null(): void
    {
        $a = new StGeomFromText(Value::of('POINT(1 1)'), 3857);
        $sql = (new StAsText(new StUnion(Value::of(null), $a)))->getValue($this->grammar);
        $this->assertNull($this->evaluate($sql));
    }

    // ------------------------------------------------------------------
    // StCollect (aggregate)
    // ------------------------------------------------------------------

    public function test_stcollect_renders_operand(): void
    {
        $sql = (new StCollect('geom'))->getValue($this->grammar);
        $this->assertSame('ST_Collect(`geom`)', $sql);
    }

    public function test_stcollect_via_real_query_builder(): void
    {
        $query = DB::table('groups')->select(new StCollect('polyindex'));

        $this->assertSame('select ST_Collect(`polyindex`) from `groups`', $query->toSql());
        $this->assertSame([], $query->getBindings());
    }

    public function test_stcollect_semantics_aggregates_non_null_rows_across_a_group(): void
    {
        // Not expressible via the shared evaluate() helper (no FROM clause)
        // since aggregation genuinely needs rows to aggregate over - built
        // as a UNION ALL derived table instead.
        $collect = (new StCollect('g'))->getValue($this->grammar);
        $sql = "SELECT ST_AsText($collect) AS result FROM ("
            ."SELECT ST_GeomFromText('POINT(1 1)', 3857) AS g "
            ."UNION ALL SELECT ST_GeomFromText('POINT(2 2)', 3857)"
            .') t';
        $this->assertSame('MULTIPOINT((1 1),(2 2))', DB::selectOne($sql)->result);
    }

    public function test_stcollect_semantics_empty_group_is_null(): void
    {
        $collect = (new StCollect('g'))->getValue($this->grammar);
        $sql = "SELECT ST_AsText($collect) AS result FROM ("
            ."SELECT ST_GeomFromText('POINT(1 1)', 3857) AS g LIMIT 0"
            .') t';
        $this->assertNull(DB::selectOne($sql)->result);
    }

    // ------------------------------------------------------------------
    // Haversine (this project's own stored function)
    // ------------------------------------------------------------------

    public function test_haversine_renders_lat_lon_lat_lon_in_order(): void
    {
        $sql = (new Haversine('lat1', 'lon1', 'lat2', 'lon2'))->getValue($this->grammar);
        $this->assertSame('haversine(`lat1`, `lon1`, `lat2`, `lon2`)', $sql);
    }

    public function test_haversine_via_real_orderby_with_column_and_literal_operands(): void
    {
        // Mirrors ReengageContentService::resolveVolunteer()'s tiebreak:
        // ORDER BY haversine(?, ?, groups.lat, groups.lng) with a literal
        // reference point and column operands for the target.
        $query = DB::table('groups')->select('id')
            ->orderBy(new Haversine(Value::of(51.5), Value::of(-0.1), 'lat', 'lng'));

        $this->assertSame(
            'select `id` from `groups` order by haversine(51.5, -0.1, `lat`, `lng`) asc',
            $query->toSql()
        );
        $this->assertSame([], $query->getBindings());
    }

    public function test_haversine_semantics_same_point_is_zero(): void
    {
        $sql = (new Haversine(Value::of(51.5), Value::of(-0.1), Value::of(51.5), Value::of(-0.1)))
            ->getValue($this->grammar);
        $this->assertEquals(0, $this->evaluate($sql));
    }

    public function test_haversine_semantics_london_to_edinburgh_is_a_few_hundred_degrees(): void
    {
        // Returns DEGREES, not metres/miles - see the class docblock. This
        // is a sanity bound, not a precise distance assertion.
        $sql = (new Haversine(Value::of(51.5), Value::of(-0.1), Value::of(55.9), Value::of(-3.2)))
            ->getValue($this->grammar);
        $this->assertEqualsWithDelta(328.868, $this->evaluate($sql), 0.01);
    }

    public function test_haversine_semantics_null_operand_is_null(): void
    {
        $sql = (new Haversine(Value::of(null), Value::of(-0.1), Value::of(51.5), Value::of(-0.1)))
            ->getValue($this->grammar);
        $this->assertNull($this->evaluate($sql));
    }

    // ------------------------------------------------------------------
    // GetMaxDimension (this project's own stored function)
    // ------------------------------------------------------------------

    public function test_getmaxdimension_renders_operand(): void
    {
        $sql = (new GetMaxDimension('geom'))->getValue($this->grammar);
        $this->assertSame('GetMaxDimension(`geom`)', $sql);
    }

    public function test_getmaxdimension_via_real_query_builder(): void
    {
        $query = DB::table('locations')->select(new GetMaxDimension('geometry'))->limit(1);

        $this->assertSame('select GetMaxDimension(`geometry`) from `locations` limit 1', $query->toSql());
        $this->assertSame([], $query->getBindings());
    }

    public function test_getmaxdimension_semantics_point_is_zero(): void
    {
        $geom = new StGeomFromText(Value::of('POINT(5 5)'), 3857);
        $sql = (new GetMaxDimension($geom))->getValue($this->grammar);
        $this->assertEquals(0, $this->evaluate($sql));
    }

    public function test_getmaxdimension_semantics_polygon_is_positive(): void
    {
        $geom = new StGeomFromText(Value::of('POLYGON((0 0,0 10,10 10,10 0,0 0))'), 3857);
        $sql = (new GetMaxDimension($geom))->getValue($this->grammar);
        $this->assertEqualsWithDelta(7.9788, $this->evaluate($sql), 0.001);
    }

    public function test_getmaxdimension_semantics_null_operand_is_zero_not_null(): void
    {
        // Verified empirically and documented on the class - this is the
        // one function in the whole namespace that does NOT propagate NULL:
        // ST_Dimension(NULL) > 1 is neither true nor an error, so the
        // stored function's IF falls through to its ELSE 0 branch.
        $sql = (new GetMaxDimension(Value::of(null)))->getValue($this->grammar);
        $this->assertEquals(0, $this->evaluate($sql));
    }

    // ------------------------------------------------------------------
    // RendersOperands - shared behaviour exercised via any one class
    // ------------------------------------------------------------------

    public function test_unsupported_operand_type_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Coalesce('a', new stdClass()))->getValue($this->grammar);
    }

    public function test_bare_string_operand_is_always_a_column_not_a_literal(): void
    {
        // This is the key disambiguation rule the whole library relies on:
        // a bare PHP string is a column reference, never a literal - even
        // when it looks like it could be a value.
        $sql = (new Coalesce('open', 'closed'))->getValue($this->grammar);
        $this->assertSame('COALESCE(`open`, `closed`)', $sql);
    }
}
