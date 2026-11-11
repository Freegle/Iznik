<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;
use InvalidArgumentException;

/**
 * COALESCE(operand, operand, ...) - the value of the first non-NULL operand,
 * or NULL if every operand is NULL.
 *
 * Operand rendering (see RendersOperands): bare strings are column names,
 * Value::of()/nested Expressions embed as-is, other scalars are literals.
 *
 * NOT BOUND - literal operands are rendered via Grammar::escape() (see
 * Value's docblock). Column identifiers go through Grammar::wrap().
 */
final class Coalesce implements ExpressionContract
{
    use RendersOperands;

    /** @var array<int, mixed> */
    private readonly array $operands;

    public function __construct(mixed ...$operands)
    {
        if (count($operands) < 2) {
            throw new InvalidArgumentException('Coalesce requires at least 2 operands.');
        }

        $this->operands = $operands;
    }

    public function getValue(Grammar $grammar): string
    {
        $rendered = array_map(
            fn (mixed $operand) => $this->renderOperand($operand, $grammar),
            $this->operands
        );

        return 'COALESCE('.implode(', ', $rendered).')';
    }
}
