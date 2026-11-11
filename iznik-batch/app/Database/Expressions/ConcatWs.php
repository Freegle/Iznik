<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;
use InvalidArgumentException;

/**
 * CONCAT_WS(separator, operand, operand, ...).
 *
 * MySQL semantics: NULL operands are silently skipped (unlike CONCAT, which
 * returns NULL if any operand is NULL - see Concat). The result is only NULL
 * if the separator itself is NULL. Cover this in tests.
 *
 * Operand rendering (see RendersOperands): bare strings are column names,
 * Value::of()/nested Expressions embed as-is, other scalars are literals.
 * The separator follows the same rule - a bare string separator is treated
 * as a column, so a literal separator must use Value::of(', ').
 *
 * NOT BOUND - literal operands are rendered via Grammar::escape() (see
 * Value's docblock). Column identifiers go through Grammar::wrap().
 */
final class ConcatWs implements ExpressionContract
{
    use RendersOperands;

    /** @var array<int, mixed> */
    private readonly array $operands;

    public function __construct(
        private readonly mixed $separator,
        mixed ...$operands
    ) {
        if (count($operands) < 1) {
            throw new InvalidArgumentException('ConcatWs requires at least 1 operand beyond the separator.');
        }

        $this->operands = $operands;
    }

    public function getValue(Grammar $grammar): string
    {
        $rendered = array_map(
            fn (mixed $operand) => $this->renderOperand($operand, $grammar),
            $this->operands
        );

        return 'CONCAT_WS('
            .$this->renderOperand($this->separator, $grammar).', '
            .implode(', ', $rendered)
            .')';
    }
}
