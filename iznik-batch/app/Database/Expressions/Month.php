<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\PostgresDialect;
use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;

/**
 * MONTH(operand) - MySQL's calendar-month extraction (1-12) from a
 * date/datetime value, or NULL if the operand is NULL.
 *
 * Operand rendering (see RendersOperands): a bare string is a column name,
 * Value::of()/nested Expressions embed as-is.
 *
 * NOT BOUND - a literal operand is rendered via Grammar::escape() (see
 * Value's docblock). Column identifiers go through Grammar::wrap().
 */
final class Month implements ExpressionContract
{
    use RendersOperands;
    use PostgresDialect;

    public function __construct(private readonly mixed $operand)
    {
    }

    public function getValue(Grammar $grammar): string
    {
        if ($this->isPostgres($grammar)) {
            return 'CAST(EXTRACT(MONTH FROM '.$this->renderOperand($this->operand, $grammar).') AS integer)';
        }

        return 'MONTH('.$this->renderOperand($this->operand, $grammar).')';
    }
}
