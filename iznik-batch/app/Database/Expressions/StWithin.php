<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\ConditionExpression;
use Illuminate\Database\Grammar;

/**
 * ST_Within(g1, g2) - MySQL spatial predicate: 1 if `g1` is entirely inside
 * `g2`, 0 if not, NULL if either argument is NULL. The mirror image of
 * ST_Contains (`ST_Within(a, b)` == `ST_Contains(b, a)`) - kept as its own
 * class because call sites read `ST_Within(mr.polygon, g.polyindex)` etc.
 * directly and a wrapper flip would obscure that.
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
final class StWithin implements ConditionExpression
{
    use RendersOperands;

    public function __construct(
        private readonly mixed $g1,
        private readonly mixed $g2,
    ) {
    }

    public function getValue(Grammar $grammar): string
    {
        return 'ST_Within('
            .$this->renderOperand($this->g1, $grammar).', '
            .$this->renderOperand($this->g2, $grammar)
            .')';
    }
}
