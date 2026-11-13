<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\PostgresDialect;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;

/**
 * The value a column was given by the INSERT, inside the update part of an upsert: VALUES(col) in MySQL's
 * ON DUPLICATE KEY UPDATE, excluded.col in PostgreSQL's ON CONFLICT DO UPDATE. For an increment, qualify
 * the existing column with the table name (`counts.n + InsertedValue('n')`), as PostgreSQL requires.
 */
final class InsertedValue implements ExpressionContract
{
    use PostgresDialect;

    public function __construct(private readonly string $column)
    {
    }

    public function getValue(Grammar $grammar): string
    {
        $column = $grammar->wrap($this->column);

        return $this->isPostgres($grammar) ? 'excluded.'.$column : 'VALUES('.$column.')';
    }
}
