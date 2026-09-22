<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;

/**
 * ST_Difference(g1, g2) - the part of $g1 that does NOT intersect $g2 (set
 * difference, order matters - unlike StUnion/StIntersection), or NULL if
 * either operand is NULL. Used to subtract a rejected group's area out of a
 * reach polygon (`ST_Difference(mr.polygon, g.polyindex)`).
 *
 * Operand rendering (see RendersOperands): bare strings are column names,
 * Value::of()/nested Expressions embed as-is.
 *
 * NOT BOUND - literal operands are rendered via Grammar::escape() (see
 * Value's docblock). Column identifiers go through Grammar::wrap().
 */
final class StDifference implements ExpressionContract
{
    use RendersOperands;

    public function __construct(
        private readonly mixed $g1,
        private readonly mixed $g2,
    ) {
    }

    public function getValue(Grammar $grammar): string
    {
        return 'ST_Difference('
            .$this->renderOperand($this->g1, $grammar).', '
            .$this->renderOperand($this->g2, $grammar)
            .')';
    }
}
