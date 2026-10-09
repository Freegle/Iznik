<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;

/**
 * SUBSTRING(operand, start, length) - MySQL's 1-indexed substring, e.g.
 * stripping a stored postcode's trailing " XX" inward code down to the
 * outward code: `SUBSTRING(name, 1, LENGTH(name) - 2)`, where `length` is
 * typically an Arithmetic/Length composition rather than a fixed literal.
 * $length is optional (MySQL's 2-arg form runs to the end of the string).
 *
 * Operand rendering (see RendersOperands): bare strings are column names,
 * Value::of()/nested Expressions (e.g. Arithmetic, Length) embed as-is,
 * other scalars are literals.
 *
 * NOT BOUND - literal operands are rendered via Grammar::escape() (see
 * Value's docblock). Column identifiers go through Grammar::wrap().
 */
final class Substring implements ExpressionContract
{
    use RendersOperands;

    public function __construct(
        private readonly mixed $operand,
        private readonly mixed $start,
        private readonly mixed $length = null,
    ) {
    }

    public function getValue(Grammar $grammar): string
    {
        $sql = 'SUBSTRING('.$this->renderOperand($this->operand, $grammar)
            .', '.$this->renderOperand($this->start, $grammar);

        if ($this->length !== null) {
            $sql .= ', '.$this->renderOperand($this->length, $grammar);
        }

        return $sql.')';
    }
}
