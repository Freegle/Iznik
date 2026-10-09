<?php

namespace App\Database\Expressions;

use Illuminate\Contracts\Database\Query\ConditionExpression;
use Illuminate\Database\Grammar;

/**
 * `NOT (<condition>)` - negates any ConditionExpression, usable anywhere one
 * is accepted (where()/having(), CaseWhen::when(), nested inside Logical).
 *
 * For a tri-valued (0/1/NULL) predicate like ST_Contains(...), `NOT x` and
 * `x = 0` are equivalent under SQL's three-valued logic (NOT 1 = 0, NOT 0 =
 * 1, NOT NULL = NULL, exactly matching `1 = 0` is false, `0 = 0` is true,
 * `NULL = 0` is NULL) - this class exists for the cases that read more
 * directly as a negation than as an explicit `= 0` comparison, e.g. negating
 * an Exists condition or a geometry predicate inside a larger AND/OR tree.
 */
final class Not implements ConditionExpression
{
    public function __construct(private readonly ConditionExpression $condition)
    {
    }

    public function getValue(Grammar $grammar): string
    {
        return 'NOT ('.$this->condition->getValue($grammar).')';
    }
}
