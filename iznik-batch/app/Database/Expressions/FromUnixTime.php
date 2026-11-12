<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\PostgresDialect;
use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;

/**
 * FROM_UNIXTIME(operand): a Unix timestamp as a datetime. On PostgreSQL the operand may be a number or
 * a jsonb number (what JsonExtract returns), and the answer is a timestamp in UTC; MySQL gives the
 * session time zone, which is UTC wherever this runs.
 *
 * Operand rendering (see RendersOperands): a bare string is a column name.
 */
final class FromUnixTime implements ExpressionContract
{
    use RendersOperands;
    use PostgresDialect;

    public function __construct(private readonly mixed $operand)
    {
    }

    public function getValue(Grammar $grammar): string
    {
        if ($this->isPostgres($grammar)) {
            return "(to_timestamp(CAST(".$this->renderOperand($this->operand, $grammar)." AS double precision)) AT TIME ZONE 'UTC')";
        }

        return 'FROM_UNIXTIME('.$this->renderOperand($this->operand, $grammar).')';
    }
}
