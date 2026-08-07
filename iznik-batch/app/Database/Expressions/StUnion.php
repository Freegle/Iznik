<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;

/**
 * ST_Union(g1, g2) - the geometric union of two geometries, or NULL if
 * either operand is NULL. MySQL's ST_Union is strictly BINARY (unlike SQL's
 * usual aggregate SUM()/GROUP_CONCAT() pattern) - there is no
 * `ST_Union(column) OVER (...)` form. Combining more than two geometries
 * therefore means chaining: `new StUnion($a, new StUnion($b, $c))` - see
 * Console/Commands/Ripple/ExpandCommand::handle(), which builds exactly this
 * right-nested chain (one StUnion per --within-group id) since there is no
 * fixed arity this could otherwise take.
 *
 * Operand rendering (see RendersOperands): a bare string is a column name,
 * Value::of()/nested Expressions (e.g. another StUnion, StGeomFromText)
 * embed as-is.
 *
 * NOT BOUND - literal operands are rendered via Grammar::escape() (see
 * Value's docblock). Column identifiers go through Grammar::wrap().
 */
final class StUnion implements ExpressionContract
{
    use RendersOperands;

    public function __construct(
        private readonly mixed $g1,
        private readonly mixed $g2,
    ) {
    }

    public function getValue(Grammar $grammar): string
    {
        return 'ST_Union('
            .$this->renderOperand($this->g1, $grammar).', '
            .$this->renderOperand($this->g2, $grammar)
            .')';
    }
}
