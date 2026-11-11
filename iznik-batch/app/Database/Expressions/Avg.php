<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;

/**
 * AVG(operand) - the aggregate function, usable anywhere the query builder's
 * own ->avg('column') is too narrow because the mean needs to sit alongside
 * other aggregates in a single SELECT list (which ->avg()'s own aggregate()
 * short-circuit cannot do - it executes immediately and returns a lone
 * scalar), e.g. `SELECT AVG(lat) AS lat, MIN(lat) AS swlat, MAX(lat) AS
 * nelat FROM ... WHERE ...` in one round trip instead of three.
 *
 * Operand rendering (see RendersOperands): a bare string is a column name,
 * Value::of()/nested Expressions embed as-is.
 *
 * NOT BOUND - a literal operand is rendered via Grammar::escape() (see
 * Value's docblock). Column identifiers go through Grammar::wrap().
 */
final class Avg implements ExpressionContract
{
    use RendersOperands;

    public function __construct(private readonly mixed $operand)
    {
    }

    public function getValue(Grammar $grammar): string
    {
        return 'AVG('.$this->renderOperand($this->operand, $grammar).')';
    }
}
