<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\PostgresDialect;
use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;
use InvalidArgumentException;

/**
 * DATE_ADD(base, INTERVAL n UNIT) - a date/time plus an interval. DateSub subtracts.
 *
 * $unit is a SQL keyword, validated against UNITS. $amount follows the usual operand rules: an int/float is a
 * literal, a string is a column, Value::of() and nested expressions embed as they are. On PostgreSQL the amount
 * multiplies a one-unit interval, so a column amount works as well as a literal.
 */
class DateAdd implements ExpressionContract
{
    use RendersOperands;
    use PostgresDialect;

    public const array UNITS = ['SECOND', 'MINUTE', 'HOUR', 'DAY', 'WEEK', 'MONTH', 'YEAR'];

    protected const string SIGN = '+';

    protected const string MYSQL_FUNCTION = 'DATE_ADD';

    private readonly string $unit;

    public function __construct(
        private readonly mixed $base,
        private readonly mixed $amount,
        string $unit,
    ) {
        $normalized = strtoupper(trim($unit));

        if (! in_array($normalized, self::UNITS, true)) {
            throw new InvalidArgumentException("Unsupported interval unit [{$unit}]. Allowed: ".implode(', ', self::UNITS));
        }

        $this->unit = $normalized;
    }

    public function getValue(Grammar $grammar): string
    {
        $base = $this->renderOperand($this->base, $grammar);
        $amount = $this->renderOperand($this->amount, $grammar);

        if ($this->isPostgres($grammar)) {
            return '('.$base.' '.static::SIGN.' (CAST('.$amount.' AS numeric) * INTERVAL \'1 '.strtolower($this->unit).'\'))';
        }

        return static::MYSQL_FUNCTION.'('.$base.', INTERVAL '.$amount.' '.$this->unit.')';
    }
}
