<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\PostgresDialect;
use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;

/**
 * The start of the hour a datetime falls in: MySQL DATE_FORMAT(operand, '%Y-%m-%d %H:00:00'), which is
 * text in the shape of a datetime, and on PostgreSQL date_trunc('hour', operand), a timestamp.
 *
 * Operand rendering (see RendersOperands): a bare string is a column name.
 */
final class StartOfHour implements ExpressionContract
{
    use RendersOperands;
    use PostgresDialect;

    public function __construct(private readonly mixed $operand)
    {
    }

    public function getValue(Grammar $grammar): string
    {
        if ($this->isPostgres($grammar)) {
            return "date_trunc('hour', ".$this->renderOperand($this->operand, $grammar).')';
        }

        return 'DATE_FORMAT('.$this->renderOperand($this->operand, $grammar).", '%Y-%m-%d %H:00:00')";
    }
}
