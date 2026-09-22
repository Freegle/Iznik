<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\ConditionExpression;
use Illuminate\Database\Grammar;
use InvalidArgumentException;

/**
 * A single structured comparison (`left OP right`), usable anywhere a
 * ConditionExpression is accepted:
 *
 *  - directly as the sole argument to Query\Builder::where()/having()
 *    (Builder special-cases ConditionExpression - see Builder::where()
 *    around the `$column instanceof ConditionExpression` check), and
 *  - as the condition operand of CaseWhen::when() in this namespace.
 *
 * Operand rendering (see RendersOperands): bare strings are column names,
 * Value::of()/nested Expressions embed as-is, other scalars are literals.
 * This means `new Comparison('status', '=', 'open')` compares the `status`
 * COLUMN against the `open` COLUMN - to compare against the literal string
 * 'open' use `new Comparison('status', '=', Value::of('open'))`.
 *
 * The operator is a whitelisted SQL keyword, not a bindable value - it is
 * validated against ALLOWED_OPERATORS and rejected otherwise.
 *
 * NOT BOUND - literal operands are rendered via Grammar::escape() (see
 * Value's docblock). Column identifiers go through Grammar::wrap().
 */
final class Comparison implements ConditionExpression
{
    use RendersOperands;

    public const array ALLOWED_OPERATORS = [
        '=', '!=', '<>', '<', '<=', '>', '>=', '<=>', 'LIKE', 'NOT LIKE',
    ];

    private readonly string $operator;

    public function __construct(
        private readonly mixed $left,
        string $operator,
        private readonly mixed $right,
    ) {
        $normalized = strtoupper(trim($operator));

        if (! in_array($normalized, self::ALLOWED_OPERATORS, true)) {
            throw new InvalidArgumentException(
                "Unsupported comparison operator [{$operator}]. Allowed: "
                .implode(', ', self::ALLOWED_OPERATORS)
            );
        }

        $this->operator = $normalized;
    }

    public function getValue(Grammar $grammar): string
    {
        return $this->renderOperand($this->left, $grammar)
            .' '.$this->operator.' '
            .$this->renderOperand($this->right, $grammar);
    }
}
