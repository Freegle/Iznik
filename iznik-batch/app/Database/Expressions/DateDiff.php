<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\PostgresDialect;
use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;

/**
 * DATEDIFF(expr1, expr2) - MySQL's whole-day date subtraction:
 * `DAYS(expr1) - DAYS(expr2)`, i.e. the number of days between the two
 * dates, ignoring time-of-day. Returns NULL if either operand is NULL.
 *
 * Operand rendering (see RendersOperands): a bare string is a column name,
 * Value::of()/nested Expressions (e.g. Now) embed as-is - so
 * `new DateDiff(new Now(), 'posted_at')` renders
 * `DATEDIFF(NOW(), posted_at)`, matching the argument order in MySQL's own
 * signature (first minus second).
 *
 * NOT BOUND - a literal operand is rendered via Grammar::escape() (see
 * Value's docblock). Column identifiers go through Grammar::wrap().
 */
final class DateDiff implements ExpressionContract
{
    use RendersOperands;
    use PostgresDialect;

    public function __construct(
        private readonly mixed $expr1,
        private readonly mixed $expr2,
    ) {
    }

    public function getValue(Grammar $grammar): string
    {
        if ($this->isPostgres($grammar)) {
            // Whole days between the dates, as MySQL's DATEDIFF(later, earlier).
            return '(CAST('.$this->renderOperand($this->expr1, $grammar).' AS date) - CAST('
                .$this->renderOperand($this->expr2, $grammar).' AS date))';
        }

        return 'DATEDIFF('
            .$this->renderOperand($this->expr1, $grammar).', '
            .$this->renderOperand($this->expr2, $grammar)
            .')';
    }
}
