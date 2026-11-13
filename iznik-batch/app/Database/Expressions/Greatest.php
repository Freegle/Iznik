<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\PostgresDialect;
use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;
use InvalidArgumentException;

/**
 * GREATEST(operand, operand, ...).
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
final class Greatest implements ExpressionContract
{
    use RendersOperands;
    use PostgresDialect;

    /** @var array<int, mixed> */
    private readonly array $operands;

    public function __construct(mixed ...$operands)
    {
        if (count($operands) < 2) {
            throw new InvalidArgumentException('Greatest requires at least 2 operands.');
        }

        $this->operands = $operands;
    }

    public function getValue(Grammar $grammar): string
    {
        $rendered = array_map(
            fn (mixed $operand) => $this->renderOperand($operand, $grammar),
            $this->operands
        );

        if ($this->isPostgres($grammar)) {
            // MySQL gives NULL if any operand is NULL; PostgreSQL ignores NULLs.
            return '(CASE WHEN num_nulls('.implode(', ', $rendered).') > 0 THEN NULL ELSE GREATEST('.implode(', ', $rendered).') END)';
        }

        return 'GREATEST('.implode(', ', $rendered).')';
    }
}
