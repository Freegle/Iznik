<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;
use InvalidArgumentException;

/**
 * TIMESTAMPDIFF(unit, start, end) - the signed difference between two
 * datetime operands in the given unit, e.g. seconds elapsed between two
 * COLUMNS. whereColumn() only supports a direct operator comparison between
 * two columns; it has no way to express an interval/offset between them, so
 * this covers the case where the interval itself needs comparing (typically
 * wrapped in App\Database\Expressions\Comparison and passed to where()/
 * having(), which both special-case ConditionExpression).
 *
 * $unit is a SQL keyword, not a value - MySQL's grammar does not allow
 * `TIMESTAMPDIFF(?, ...)`, so it is validated against the UNITS whitelist
 * (the exact set MySQL 8 accepts) rather than bound or escaped.
 *
 * Operand rendering for $start/$end (see RendersOperands): bare strings are
 * column names, Value::of()/nested Expressions embed as-is, other scalars
 * are literals (escaped, not bound - see Value's docblock).
 */
final class TimestampDiff implements ExpressionContract
{
    use RendersOperands;

    public const array UNITS = [
        'MICROSECOND', 'SECOND', 'MINUTE', 'HOUR', 'DAY', 'WEEK', 'MONTH',
        'QUARTER', 'YEAR',
    ];

    private readonly string $unit;

    public function __construct(
        string $unit,
        private readonly mixed $start,
        private readonly mixed $end,
    ) {
        $normalized = strtoupper(trim($unit));

        if (! in_array($normalized, self::UNITS, true)) {
            throw new InvalidArgumentException(
                "Unsupported TIMESTAMPDIFF unit [{$unit}]. Allowed: ".implode(', ', self::UNITS)
            );
        }

        $this->unit = $normalized;
    }

    public function getValue(Grammar $grammar): string
    {
        return 'TIMESTAMPDIFF('.$this->unit.', '
            .$this->renderOperand($this->start, $grammar).', '
            .$this->renderOperand($this->end, $grammar)
            .')';
    }
}
