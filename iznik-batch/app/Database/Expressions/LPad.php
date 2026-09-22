<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;

/**
 * LPAD(operand, length, pad) - left-pads $operand (cast to string) with
 * $pad until it is $length characters long, e.g. `new LPad(new Month('date'),
 * 2, Value::of('0'))` renders a zero-padded 2-digit month.
 *
 * Operand rendering (see RendersOperands): a bare string is a column name,
 * Value::of()/nested Expressions embed as-is, other scalars are literals -
 * $length is virtually always an int literal, $pad virtually always a
 * Value::of() string literal (a bare string would otherwise be read as a
 * column, per the standard rule).
 *
 * NOT BOUND - literal operands are rendered via Grammar::escape() (see
 * Value's docblock). Column identifiers go through Grammar::wrap().
 */
final class LPad implements ExpressionContract
{
    use RendersOperands;

    public function __construct(
        private readonly mixed $operand,
        private readonly mixed $length,
        private readonly mixed $pad,
    ) {
    }

    public function getValue(Grammar $grammar): string
    {
        return 'LPAD('
            .$this->renderOperand($this->operand, $grammar).', '
            .$this->renderOperand($this->length, $grammar).', '
            .$this->renderOperand($this->pad, $grammar)
            .')';
    }
}
