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

    public static function col(string $column): string
    {
        return DB::getQueryGrammar()->wrap($column);
    }
}
