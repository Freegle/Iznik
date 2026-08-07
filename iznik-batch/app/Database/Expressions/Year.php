<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;

/**
 * YEAR(operand) - MySQL's calendar-year extraction from a date/datetime
 * value, or NULL if the operand is NULL.
 *
 * Operand rendering (see RendersOperands): a bare string is a column name,
 * Value::of()/nested Expressions embed as-is.
 *
 * NOT BOUND - a literal operand is rendered via Grammar::escape() (see
 * Value's docblock). Column identifiers go through Grammar::wrap().
 */
final class Year implements ExpressionContract
{
    use RendersOperands;

    public function __construct(private readonly mixed $operand)
    {
    }

    public function getValue(Grammar $grammar): string
    {
        return 'YEAR('.$this->renderOperand($this->operand, $grammar).')';
    }
}
