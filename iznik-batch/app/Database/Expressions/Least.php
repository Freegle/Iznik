<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;
use InvalidArgumentException;

/**
 * LEAST(operand, operand, ...).
 *
 * MySQL semantics: returns NULL if ANY operand is NULL (opposite of
 * COALESCE - see Coalesce). Cover this in tests.
 *
 * Operand rendering (see RendersOperands): bare strings are column names,
 * Value::of()/nested Expressions embed as-is, other scalars are literals.
 *
 * NOT BOUND - literal operands are rendered via Grammar::escape() (see
 * Value's docblock). Column identifiers go through Grammar::wrap().
 */
final class Least implements ExpressionContract
{
    use RendersOperands;

    /** @var array<int, mixed> */
    private readonly array $operands;

    public function __construct(mixed ...$operands)
    {
        if (count($operands) < 2) {
            throw new InvalidArgumentException('Least requires at least 2 operands.');
        }

        $this->operands = $operands;
    }

    public function getValue(Grammar $grammar): string
    {
        $rendered = array_map(
            fn (mixed $operand) => $this->renderOperand($operand, $grammar),
            $this->operands
        );

        return 'LEAST('.implode(', ', $rendered).')';
    }
}
