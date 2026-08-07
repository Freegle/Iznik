<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;

/**
 * LENGTH(operand) - MySQL's byte length (not character length; multi-byte
 * UTF-8 characters count as more than one). Use CHAR_LENGTH() via a raw
 * expression if character length is what you need - this class deliberately
 * only covers LENGTH(), the function actually requested for this library.
 *
 * Operand rendering (see RendersOperands): bare strings are column names,
 * Value::of()/nested Expressions embed as-is, other scalars are literals.
 *
 * NOT BOUND - a literal operand is rendered via Grammar::escape() (see
 * Value's docblock). Column identifiers go through Grammar::wrap().
 */
final class Length implements ExpressionContract
{
    use RendersOperands;

    public function __construct(private readonly mixed $operand)
    {
    }

    public function getValue(Grammar $grammar): string
    {
        return 'LENGTH('.$this->renderOperand($this->operand, $grammar).')';
    }
}
