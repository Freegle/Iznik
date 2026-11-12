<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\PostgresDialect;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;

/**
 * The schema the connection works in, as information_schema names it: MySQL SCHEMA() (the database), PostgreSQL
 * current_schema() (the first schema on the search path).
 */
final class CurrentSchema implements ExpressionContract
{
    use PostgresDialect;

    public function getValue(Grammar $grammar): string
    {
        return $this->isPostgres($grammar) ? 'current_schema()' : 'schema()';
    }
}
