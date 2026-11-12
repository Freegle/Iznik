<?php

namespace App\Database;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

/**
 * Pieces of a hand-written (raw) statement, rendered for the default connection's dialect: an expression from
 * App\Database\Expressions, or a table name quoted the way the engine needs (`groups` is a reserved word on
 * MySQL and needs quoting there; PostgreSQL quotes with double quotes).
 */
final class Sql
{
    public static function expr(Expression $expression): string
    {
        return (string) $expression->getValue(DB::getQueryGrammar());
    }

    public static function table(string $table): string
    {
        return DB::getQueryGrammar()->wrapTable($table);
    }

    /**
     * An index hint for a raw FROM clause: "FORCE INDEX (name) " on MySQL, where the optimiser needs steering,
     * and nothing on PostgreSQL, which has no hints. $kind is FORCE, USE or IGNORE.
     */
    public static function indexHint(string $kind, string $index): string
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            return '';
        }

        return strtoupper($kind).' INDEX ('.$index.') ';
    }

    public static function col(string $column): string
    {
        return DB::getQueryGrammar()->wrap($column);
    }
}
