<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\PostgresDialect;
use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;

/**
 * GROUP_CONCAT(operand ORDER BY order SEPARATOR sep): the group's values joined into one string. PostgreSQL
 * spells it STRING_AGG(operand, sep ORDER BY order). The separator is a plain PHP string, escaped as a literal.
 * Both skip NULL values. MySQL cuts the result at group_concat_max_len (1024 bytes by default); PostgreSQL does not.
 */
final class GroupConcat implements ExpressionContract
{
    use RendersOperands;
    use PostgresDialect;

    public function __construct(
        private readonly mixed $operand,
        private readonly string $separator = ',',
        private readonly mixed $orderBy = null,
    ) {
    }

    public function getValue(Grammar $grammar): string
    {
        $operand = $this->renderOperand($this->operand, $grammar);
        $order = $this->orderBy === null ? '' : ' ORDER BY '.$this->renderOperand($this->orderBy, $grammar);
        $separator = $grammar->escape($this->separator);

        if ($this->isPostgres($grammar)) {
            return 'STRING_AGG(CAST('.$operand.' AS text), '.$separator.$order.')';
        }

        return 'GROUP_CONCAT('.$operand.$order.' SEPARATOR '.$separator.')';
    }
}
