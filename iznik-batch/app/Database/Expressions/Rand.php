<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\PostgresDialect;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;

/**
 * RAND() - a random number from 0 up to but not including 1, new for each row. PostgreSQL spells it random().
 * Only the unseeded form exists: a seed would mean different things on the two engines.
 */
final class Rand implements ExpressionContract
{
    use PostgresDialect;

    public function getValue(Grammar $grammar): string
    {
        return $this->isPostgres($grammar) ? 'random()' : 'RAND()';
    }
}
