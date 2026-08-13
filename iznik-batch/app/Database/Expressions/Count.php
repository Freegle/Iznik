<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;

/**
 * COUNT(operand) / COUNT(DISTINCT operand) - the aggregate as a projectable
 * or having()-able expression, for the cases the query builder's own
 * aggregate helpers (count(), having('column', ...)) can't reach: a DISTINCT
 * count compared in having() (having() only compares a plain column/alias to
 * a value - it has no method for an aggregate condition), or a DISTINCT
 * count alongside other columns in a GROUP BY select list.
 *
 * Pair with App\Database\Expressions\Comparison to use as a having()
 * condition, e.g.:
 *   ->having(new Comparison(new Count('userid', distinct: true), '>', 1))
 * which Query\Builder::having() accepts directly (ConditionExpression is
 * special-cased there, same as where()).
 *
 * Operand rendering (see RendersOperands): a bare string is a column name -
 * including the default '*', which Grammar::wrap() passes through
 * unquoted. Value::of()/nested Expressions embed as-is, other scalars are
 * literals.
 *
 * NOT BOUND - literal operands are rendered via Grammar::escape() (see
 * Value's docblock). Column identifiers go through Grammar::wrap().
 */
final class Count implements ExpressionContract
{
    use RendersOperands;

    public function __construct(
        private readonly mixed $operand = '*',
        private readonly bool $distinct = false,
    ) {
    }

    public function getValue(Grammar $grammar): string
    {
        return 'COUNT('
            .($this->distinct ? 'DISTINCT ' : '')
            .$this->renderOperand($this->operand, $grammar)
            .')';
    }
}
