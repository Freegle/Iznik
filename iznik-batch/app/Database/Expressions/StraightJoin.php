<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\PostgresDialect;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;

/**
 * The MySQL SELECT modifier STRAIGHT_JOIN, which makes the optimizer join in the order written. PostgreSQL has
 * no such modifier and plans the join itself, so it renders as nothing there.
 */
final class StraightJoin implements ExpressionContract
{
    use PostgresDialect;

    public function getValue(Grammar $grammar): string
    {
        return $this->isPostgres($grammar) ? '' : 'STRAIGHT_JOIN';
    }
}
