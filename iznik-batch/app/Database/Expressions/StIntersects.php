<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\ConditionExpression;
use Illuminate\Database\Grammar;

/**
 * ST_Intersects(g1, g2) - MySQL spatial predicate: 1 if the two geometries
 * share at least one point (touching counts), 0 if not, NULL if either
 * argument is NULL.
 *
 * Usable anywhere a ConditionExpression is accepted:
 *
 *  - directly as the sole argument to Query\Builder::where()/having()
 *    (Builder special-cases ConditionExpression), and
 *  - as an ordinary value operand elsewhere (SELECT list, ORDER BY,
 *    wrapped in COALESCE()/CASE WHEN), because ConditionExpression extends
 *    Expression - see Illuminate\Contracts\Database\Query\ConditionExpression.
 *
 * Operand rendering (see RendersOperands): bare strings are column names,
 * Value::of()/nested Expressions embed as-is.
 *
 * NOT BOUND - literal operands are rendered via Grammar::escape() (see
 * Value's docblock). Column identifiers go through Grammar::wrap().
 */
final class StIntersects implements ConditionExpression
{
    use RendersOperands;

    public function __construct(
        private readonly mixed $g1,
        private readonly mixed $g2,
    ) {
    }

    public function getValue(Grammar $grammar): string
    {
        return 'ST_Intersects('
            .$this->renderOperand($this->g1, $grammar).', '
            .$this->renderOperand($this->g2, $grammar)
            .')';
    }
}
