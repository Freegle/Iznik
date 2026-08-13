<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;

/**
 * COUNT(DISTINCT operand) - the aggregate function, usable anywhere the
 * query builder's own ->distinct()->count('column') is too narrow because
 * the count needs to sit alongside other non-aggregated columns in a GROUP
 * BY select list (which ->count()'s own aggregate() short-circuit cannot do
 * - see Illuminate\Database\Query\Builder::aggregate()), e.g.
 * `SELECT groupid, COUNT(DISTINCT msgid) AS cnt FROM ... GROUP BY groupid`.
 *
 * A separate class from Count (rather than a $distinct flag there) because
 * COUNT(DISTINCT *) is not valid SQL - DISTINCT always requires a real
 * operand, so this class has no bare-wildcard default and always renders an
 * operand, unlike Count's deliberate no-arg COUNT(*) form.
 *
 * Operand rendering (see RendersOperands): a bare string is a column name,
 * Value::of()/nested Expressions embed as-is.
 *
 * NOT BOUND - a literal operand is rendered via Grammar::escape() (see
 * Value's docblock). Column identifiers go through Grammar::wrap().
 */
final class CountDistinct implements ExpressionContract
{
    use RendersOperands;

    public function __construct(private readonly mixed $operand)
    {
    }

    public function getValue(Grammar $grammar): string
    {
        return 'COUNT(DISTINCT '.$this->renderOperand($this->operand, $grammar).')';
    }
}
