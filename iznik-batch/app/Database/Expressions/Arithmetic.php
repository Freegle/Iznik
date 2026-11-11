<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;
use InvalidArgumentException;

/**
 * A single structured arithmetic operation (`(left OP right)`), e.g. an
 * area-ratio overlap fraction (`ST_Area(a) / ST_Area(b)`) or a
 * popularity-weighted mean (`SUM(popularity * weight) / SUM(popularity)`) -
 * expressions the query builder's aggregate helpers (->sum() etc.) cannot
 * produce because they only ever project a single named column.
 *
 * Always parenthesised so instances compose safely when nested inside
 * another Arithmetic, CaseWhen result, or SELECT list, without the caller
 * having to reason about operator precedence.
 *
 * Operand rendering (see RendersOperands): bare strings are column names,
 * Value::of()/nested Expressions (e.g. another Arithmetic, or a SUM(...) via
 * DB::raw until this library grows an aggregate wrapper) embed as-is, other
 * scalars are literals.
 *
 * The operator is a whitelisted SQL token, not a bindable value - it is
 * validated against ALLOWED_OPERATORS and rejected otherwise.
 *
 * NOT BOUND - literal operands are rendered via Grammar::escape() (see
 * Value's docblock). Column identifiers go through Grammar::wrap().
 */
final class Arithmetic implements ExpressionContract
{
    use RendersOperands;

    public const array ALLOWED_OPERATORS = ['+', '-', '*', '/', '%'];

    private readonly string $operator;

    public function __construct(
        private readonly mixed $left,
        string $operator,
        private readonly mixed $right,
    ) {
        $normalized = trim($operator);

        if (! in_array($normalized, self::ALLOWED_OPERATORS, true)) {
            throw new InvalidArgumentException(
                "Unsupported arithmetic operator [{$operator}]. Allowed: "
                .implode(', ', self::ALLOWED_OPERATORS)
            );
        }

        $this->operator = $normalized;
    }

    public function getValue(Grammar $grammar): string
    {
        return '('.$this->renderOperand($this->left, $grammar)
            .' '.$this->operator.' '
            .$this->renderOperand($this->right, $grammar).')';
    }
}
