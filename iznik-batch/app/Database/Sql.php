<?php

namespace App\Database;

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
     * A constant interval for date arithmetic written as `x + INTERVAL 2 SECOND`: that spelling on MySQL,
     * INTERVAL '2 second' on PostgreSQL.
     */
    public static function interval(int $amount, string $unit): string
    {
        return DB::getQueryGrammar() instanceof PostgresGrammar
            ? "INTERVAL '".$amount.' '.strtolower($unit)."'"
            : 'INTERVAL '.$amount.' '.strtoupper($unit);
    }

    /** The current database (MySQL schema()) or schema (PostgreSQL current_schema()) for information_schema filters. */
    public static function currentSchema(): string
    {
        return DB::getQueryGrammar() instanceof PostgresGrammar ? 'current_schema()' : 'schema()';
    }
}
