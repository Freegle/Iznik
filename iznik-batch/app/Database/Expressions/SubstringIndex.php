<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\PostgresDialect;
use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;
use InvalidArgumentException;

/**
 * SUBSTRING_INDEX(string, delimiter, count) - everything before the count-th delimiter (positive count) or after
 * the count-th delimiter from the end (negative count). The two cases the code uses are supported on
 * PostgreSQL: count 1 (the part before the first delimiter) and count -1 (the part after the last one, such as
 * the domain of an address). The delimiter must be a literal.
 */
final class SubstringIndex implements ExpressionContract
{
    use PostgresDialect;
    use RendersOperands;

    public function __construct(
        private readonly mixed $operand,
        private readonly string $delimiter,
        private readonly int $count,
    ) {
        if ($delimiter === '') {
            throw new InvalidArgumentException('SUBSTRING_INDEX needs a delimiter.');
        }
    }

    public function getValue(Grammar $grammar): string
    {
        $operand = $this->renderOperand($this->operand, $grammar);
        $delimiter = $grammar->escape($this->delimiter);

        if (! $this->isPostgres($grammar)) {
            return "SUBSTRING_INDEX({$operand}, {$delimiter}, {$this->count})";
        }

        $parts = "string_to_array(CAST({$operand} AS text), {$delimiter})";

        return match ($this->count) {
            1 => "split_part(CAST({$operand} AS text), {$delimiter}, 1)",
            -1 => "({$parts})[cardinality({$parts})]",
            default => throw new InvalidArgumentException("SUBSTRING_INDEX count {$this->count} is not supported on PostgreSQL: only 1 and -1."),
        };
    }
}
