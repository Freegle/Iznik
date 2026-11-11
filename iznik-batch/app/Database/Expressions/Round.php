<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;

/**
 * ROUND(operand) or ROUND(operand, decimals) when $decimals is given.
 *
 * Operand rendering (see RendersOperands): bare strings are column names,
 * Value::of()/nested Expressions embed as-is, other scalars are literals -
 * so `new Round('amount', 2)` rounds the `amount` column to 2 decimal
 * places, decimals being an unambiguous int literal.
 *
 * NOT BOUND - literal operands are rendered via Grammar::escape() (see
 * Value's docblock). Column identifiers go through Grammar::wrap().
 */
final class Round implements ExpressionContract
{
    use RendersOperands;

    public function __construct(
        private readonly mixed $operand,
        private readonly mixed $decimals = null,
    ) {
    }

    public function getValue(Grammar $grammar): string
    {
        $sql = 'ROUND('.$this->renderOperand($this->operand, $grammar);

        if ($this->decimals !== null) {
            $sql .= ', '.$this->renderOperand($this->decimals, $grammar);
        }

        return $sql.')';
    }
}
