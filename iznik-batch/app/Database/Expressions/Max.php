<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;

/**
 * MAX(operand) - the aggregate function, usable anywhere the query builder's
 * own ->max('column') is too narrow because the maximum needs to sit
 * alongside other non-aggregated aggregates in a single SELECT list (which
 * ->max()'s own aggregate() short-circuit cannot do - see
 * Illuminate\Database\Query\Builder::aggregate()), e.g. projecting a bounding
 * box (`MIN(lat) AS swlat, MAX(lat) AS nelat`, see Min) in one query.
 *
 * Operand rendering (see RendersOperands): a bare string is a column name,
 * Value::of()/nested Expressions embed as-is.
 *
 * NOT BOUND - a literal operand is rendered via Grammar::escape() (see
 * Value's docblock). Column identifiers go through Grammar::wrap().
 */
final class Max implements ExpressionContract
{
    use RendersOperands;

    public function __construct(private readonly mixed $operand)
    {
    }

    public function getValue(Grammar $grammar): string
    {
        return 'MAX('.$this->renderOperand($this->operand, $grammar).')';
    }
}
