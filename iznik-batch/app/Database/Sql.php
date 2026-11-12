<?php

namespace App\Database;

use App\Database\Expressions\DateSub;
use App\Database\Expressions\JsonArrayHasValue;
use App\Database\Expressions\JsonExtract;
use App\Database\Expressions\JsonHasPath;
use App\Database\Expressions\JsonSettingEnabled;
use App\Database\Expressions\JsonUnquote;
use App\Database\Expressions\Value;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Support\Facades\DB;

/**
 * Identifier quoting for hand-written (raw) SQL. A table whose name is a reserved word on MySQL (`groups`)
 * needs quoting there and is quoted differently on PostgreSQL: this renders the name with the connection's
 * own grammar.
 */
final class Sql
{
    /** The table name quoted for the default connection: `groups` on MySQL, "groups" on PostgreSQL. */
    public static function table(string $table): string
    {
        return DB::getQueryGrammar()->wrapTable($table);
    }

    /** Renders an expression for the default connection's grammar (same as render). */
    public static function expr(ExpressionContract $expression): string
    {
        return self::render($expression);
    }

    /** A column name quoted with the connection's grammar: `name` on MySQL, "name" on PostgreSQL. */
    public static function col(string $column): string
    {
        return DB::getQueryGrammar()->wrap($column);
    }

    /** Renders an expression for the default connection's grammar. */
    public static function render(ExpressionContract $expression): string
    {
        return (string) $expression->getValue(DB::getQueryGrammar());
    }

    /** JSON_EXTRACT(column, path) from a SQL operand and a literal path such as '$.a.b'. */
    public static function jsonExtract(string $column, string $path): string
    {
        return self::render(new JsonExtract(DB::raw($column), Value::of($path)));
    }

    /** JSON_UNQUOTE(JSON_EXTRACT(column, path)): the value at the path as text. */
    public static function jsonText(string $column, string $path): string
    {
        return self::render(new JsonUnquote(new JsonExtract(DB::raw($column), Value::of($path))));
    }

    /** True when the JSON array column (NULL counts as empty) holds the scalar the second operand names. */
    public static function jsonArrayHas(string $arrayColumn, string $valueColumn): string
    {
        return self::render(new JsonArrayHasValue(DB::raw($arrayColumn), DB::raw($valueColumn)));
    }

    /** True when the JSON setting at the path is present and not 0 or false. */
    public static function jsonSettingEnabled(string $column, string $path): string
    {
        return self::render(new JsonSettingEnabled(DB::raw($column), Value::of($path)));
    }

    /**
     * DROP the session's temporary table of this name and never a permanent table that shares it. MySQL has
     * DROP TEMPORARY TABLE; PostgreSQL scopes the drop to the temporary schema with pg_temp.
     */
    public static function dropTemporaryTable(string $table): string
    {
        $grammar = DB::getQueryGrammar();

        return $grammar instanceof PostgresGrammar
            ? 'DROP TABLE IF EXISTS pg_temp.'.$grammar->wrap($table)
            : 'DROP TEMPORARY TABLE IF EXISTS '.$table;
    }

    /**
     * A MySQL index hint for a FROM clause, with its leading space: ` FORCE INDEX (name)`. PostgreSQL has no
     * index hints and plans the query itself, so it renders nothing there.
     */
    public static function forceIndex(string $index): string
    {
        return DB::getQueryGrammar() instanceof PostgresGrammar ? '' : ' FORCE INDEX ('.$index.')';
    }

    /** True when the JSON column holds the path; the path operand is SQL text, usually a `?` placeholder. */
    public static function jsonHasPath(string $column, string $pathOperand): string
    {
        return self::render(new JsonHasPath(DB::raw($column), DB::raw($pathOperand)));
    }

    /**
     * The upsert clause of an INSERT: `ON DUPLICATE KEY UPDATE col = VALUES(col), ...` on MySQL and
     * `ON CONFLICT (keys) DO UPDATE SET col = EXCLUDED.col, ...` on PostgreSQL, which needs the conflict
     * target. $assignments are SQL appended as written (for example `updated_at = NOW()`).
     *
     * @param  array<int, string>  $conflictKeys  the unique key the insert may collide on
     * @param  array<int, string>  $columns  columns taken from the new row
     * @param  array<int, string>  $assignments
     */
    public static function onDuplicateKeyUpdate(array $conflictKeys, array $columns, array $assignments = []): string
    {
        $postgres = DB::getQueryGrammar() instanceof PostgresGrammar;
        $set = array_map(fn (string $c) => $postgres ? "{$c} = EXCLUDED.{$c}" : "{$c} = VALUES({$c})", $columns);
        $set = implode(', ', array_merge($set, $assignments));

        return $postgres
            ? 'ON CONFLICT ('.implode(', ', $conflictKeys).') DO UPDATE SET '.$set
            : 'ON DUPLICATE KEY UPDATE '.$set;
    }

    /**
     * A column name for raw SQL. MySQL folds nothing, so the name is written as is; PostgreSQL folds an unquoted
     * name to lower case, so a mixed-case column (ourPostingStatus) has to be quoted there.
     */
    public static function column(string $name): string
    {
        $grammar = DB::getQueryGrammar();

        return $grammar instanceof PostgresGrammar ? $grammar->wrap($name) : $name;
    }

    /** NOW() minus an interval: DATE_SUB(NOW(), INTERVAL amount unit) on MySQL. $amount is SQL text (a number or `?`). */
    public static function nowMinus(string $amount, string $unit): string
    {
        return self::render(new DateSub(DB::raw('NOW()'), DB::raw($amount), $unit));
    }
}
