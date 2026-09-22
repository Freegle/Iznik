<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\ConditionExpression;
use Illuminate\Database\Grammar;
use InvalidArgumentException;

/**
 * `left IN (value, value, ...)` as a structured, composable condition -
 * distinct from Query\Builder::whereIn(), which only ever compares a plain
 * column against bound values. This class exists for the cases that need an
 * arbitrary left-hand Expression (e.g. ST_GeometryType(...)) tested against a
 * fixed set of SQL keyword-like literals, usable anywhere a
 * ConditionExpression is accepted:
 *
 *  - directly as the sole argument to Query\Builder::where()/having(), and
 *  - as the condition operand of CaseWhen::when() in this namespace.
 *
 * Operand rendering (see RendersOperands): a bare string $left is a column
 * name, Value::of()/nested Expressions embed as-is. Each entry in $values
 * follows the same rule - to compare against literal strings (the common
 * case), wrap each one in Value::of().
 *
 * NOT BOUND - literal operands are rendered via Grammar::escape() (see
 * Value's docblock). Column identifiers go through Grammar::wrap().
 */
final class In implements ConditionExpression
{
    use RendersOperands;

    /** @var array<int, mixed> */
    private readonly array $values;

    public function __construct(
        private readonly mixed $left,
        array $values,
    ) {
        if ($values === []) {
            throw new InvalidArgumentException('In requires at least 1 value.');
        }

        $this->values = $values;
    }

    public function getValue(Grammar $grammar): string
    {
        $rendered = array_map(
            fn (mixed $value) => $this->renderOperand($value, $grammar),
            $this->values
        );

        return $this->renderOperand($this->left, $grammar).' IN ('.implode(', ', $rendered).')';
    }
}
