<?php

namespace App\Database\Expressions;

use Illuminate\Contracts\Database\Query\ConditionExpression;
use Illuminate\Database\Grammar;
use InvalidArgumentException;

/**
 * Combines two or more ConditionExpression instances with AND/OR into one
 * parenthesised ConditionExpression: `(cond1 AND cond2 [AND cond3 ...])`.
 *
 * Query\Builder::where()/orWhere() already group conditions with AND/OR at
 * the WHERE-clause level via closures, but that machinery is tied to a
 * Builder instance. This class exists for the case a combined condition is
 * needed as a plain ConditionExpression VALUE instead - most notably as the
 * $condition argument to CaseWhen::when(), which accepts exactly one
 * ConditionExpression, e.g. testing that two JSON fields are both present:
 *
 *   (new CaseWhen())->when(Logical::and(
 *       new IsNull(new JsonExtract('u.settings', Value::of('$.mylocation.lat')), not: true),
 *       new IsNull(new JsonExtract('u.settings', Value::of('$.mylocation.lng')), not: true),
 *   ))->then(...)
 *
 * Always parenthesised so instances compose safely when nested (e.g. an OR
 * of two AND-groups), without the caller having to reason about AND/OR
 * precedence.
 */
final class Logical implements ConditionExpression
{
    public const array ALLOWED_OPERATORS = ['AND', 'OR'];

    private readonly string $operator;

    /** @var array<int, ConditionExpression> */
    private readonly array $conditions;

    public function __construct(string $operator, ConditionExpression ...$conditions)
    {
        $normalized = strtoupper(trim($operator));

        if (! in_array($normalized, self::ALLOWED_OPERATORS, true)) {
            throw new InvalidArgumentException(
                "Unsupported logical operator [{$operator}]. Allowed: ".implode(', ', self::ALLOWED_OPERATORS)
            );
        }

        if (count($conditions) < 2) {
            throw new InvalidArgumentException('Logical requires at least 2 conditions.');
        }

        $this->operator = $normalized;
        $this->conditions = $conditions;
    }

    public static function and(ConditionExpression ...$conditions): self
    {
        return new self('AND', ...$conditions);
    }

    public static function or(ConditionExpression ...$conditions): self
    {
        return new self('OR', ...$conditions);
    }

    public function getValue(Grammar $grammar): string
    {
        $rendered = array_map(
            fn (ConditionExpression $condition) => $condition->getValue($grammar),
            $this->conditions
        );

        return '('.implode(' '.$this->operator.' ', $rendered).')';
    }
}
