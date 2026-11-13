<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\PostgresDialect;
use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;

/** The year and month of a date as text such as 2026-11: DATE_FORMAT(x, '%Y-%m') on MySQL, to_char on PostgreSQL. */
final class YearMonth implements ExpressionContract
{
    use PostgresDialect;
    use RendersOperands;

    public function __construct(private readonly mixed $operand)
    {
    }

    public function getValue(Grammar $grammar): string
    {
        $operand = $this->renderOperand($this->operand, $grammar);

        return $this->isPostgres($grammar) ? "to_char({$operand}, 'YYYY-MM')" : "DATE_FORMAT({$operand}, '%Y-%m')";
    }
}
