<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;

/**
 * MIN(operand) - the aggregate function, usable anywhere the query builder's
 * own ->min('column') is too narrow because the minimum needs to sit
 * alongside other non-aggregated columns in a GROUP BY select list (which
 * ->min()'s own aggregate() short-circuit cannot do - see
 * Illuminate\Database\Query\Builder::aggregate()), e.g.
 * `SELECT tnpostid, MIN(id) AS canonical_id FROM ... GROUP BY tnpostid`.
 *
 * Operand rendering (see RendersOperands): a bare string is a column name,
 * Value::of()/nested Expressions embed as-is.
 *
 * NOT BOUND - a literal operand is rendered via Grammar::escape() (see
 * Value's docblock). Column identifiers go through Grammar::wrap().
 */
final class Min implements ExpressionContract
{
    use RendersOperands;

    public function __construct(private readonly mixed $operand)
    {
    }

    public function getValue(Grammar $grammar): string
    {
        return 'MIN('.$this->renderOperand($this->operand, $grammar).')';
    }
}
