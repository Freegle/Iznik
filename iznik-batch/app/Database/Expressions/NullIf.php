<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;

/**
 * NULLIF(left, right) - NULL if left = right, otherwise left.
 *
 * Operand rendering (see RendersOperands): bare strings are column names,
 * Value::of()/nested Expressions embed as-is, other scalars are literals.
 *
 * NOT BOUND - literal operands are rendered via Grammar::escape() (see
 * Value's docblock). Column identifiers go through Grammar::wrap().
 */
final class NullIf implements ExpressionContract
{
    use RendersOperands;

    public function __construct(
        private readonly mixed $left,
        private readonly mixed $right,
    ) {
    }

    public function getValue(Grammar $grammar): string
    {
        return 'NULLIF('
            .$this->renderOperand($this->left, $grammar)
            .', '
            .$this->renderOperand($this->right, $grammar)
            .')';
    }
}
