<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\ConditionExpression;
use Illuminate\Database\Grammar;

/**
 * JSON_VALID(operand) - MySQL predicate: 1 if the operand parses as valid
 * JSON, 0 if not, NULL if the operand is NULL. Used to guard a JSON column
 * that may hold a legacy/partial value before JSON_LENGTH()/JSON_CONTAINS()
 * are applied to it (both of those raise a genuine MySQL error on
 * non-JSON/malformed text, unlike most functions in this namespace that
 * degrade to NULL).
 *
 * Usable anywhere a ConditionExpression is accepted:
 *
 *  - directly as the sole argument to Query\Builder::where()/having(), and
 *  - as an ordinary value operand elsewhere, because ConditionExpression
 *    extends Expression.
 *
 * Operand rendering (see RendersOperands): a bare string is a column name,
 * Value::of()/nested Expressions embed as-is.
 *
 * NOT BOUND - a literal operand is rendered via Grammar::escape() (see
 * Value's docblock). Column identifiers go through Grammar::wrap().
 */
final class JsonValid implements ConditionExpression
{
    use RendersOperands;

    public function __construct(private readonly mixed $operand)
    {
    }

    public function getValue(Grammar $grammar): string
    {
        return 'JSON_VALID('.$this->renderOperand($this->operand, $grammar).')';
    }
}
