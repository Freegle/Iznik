<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\ConditionExpression;
use Illuminate\Database\Grammar;

/**
 * ST_Contains(container, containee) - MySQL spatial predicate: 1 if the
 * `containee` geometry is entirely inside (or on the boundary of, depending
 * on MySQL's boundary rules) the `container` geometry, 0 if not, NULL if
 * either argument is NULL or SRIDs mismatch in a way MySQL can't reconcile.
 * Usable anywhere a ConditionExpression is accepted:
 *
 *  - directly as the sole argument to Query\Builder::where()/having()
 *    (Builder special-cases ConditionExpression), and
 *  - as an ordinary value operand elsewhere (SELECT list, ORDER BY,
 *    wrapped in COALESCE()/CASE WHEN), because ConditionExpression extends
 *    Expression - see Illuminate\Contracts\Database\Query\ConditionExpression.
 *
 * Operand rendering (see RendersOperands): bare strings are column names
 * (e.g. `groups.polyindex`), Value::of()/nested Expressions (e.g.
 * StGeomFromText, StSrid) embed as-is.
 *
 * NOT BOUND - literal operands are rendered via Grammar::escape() (see
 * Value's docblock). Column identifiers go through Grammar::wrap().
 */
final class StContains implements ConditionExpression
{
    use RendersOperands;

    public function __construct(
        private readonly mixed $container,
        private readonly mixed $containee,
    ) {
    }

    public function getValue(Grammar $grammar): string
    {
        return 'ST_Contains('
            .$this->renderOperand($this->container, $grammar).', '
            .$this->renderOperand($this->containee, $grammar)
            .')';
    }
}
