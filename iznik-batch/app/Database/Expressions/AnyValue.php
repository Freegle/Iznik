<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;

/**
 * ANY_VALUE(operand) - MySQL's escape hatch for ONLY_FULL_GROUP_BY: picks an
 * arbitrary value from the group for a column that is not functionally
 * dependent on the GROUP BY key but is known (by the query's own WHERE/JOIN
 * shape) to be constant within each group - e.g.
 * `SELECT msgid, ANY_VALUE(ST_Y(point)) AS lat ... GROUP BY msgid` where a
 * given msgid has exactly one point across the joined rows. Not a real
 * aggregate (no defined tie-break) - only correct when the caller already
 * knows the value is uniform per group.
 *
 * Operand rendering (see RendersOperands): a bare string is a column name,
 * Value::of()/nested Expressions (e.g. StY, StX) embed as-is - this is how
 * `ANY_VALUE(ST_Y(ms.point))` composes.
 *
 * NOT BOUND - a literal operand is rendered via Grammar::escape() (see
 * Value's docblock). Column identifiers go through Grammar::wrap().
 */
final class AnyValue implements ExpressionContract
{
    use RendersOperands;

    public function __construct(private readonly mixed $operand)
    {
    }

    public function getValue(Grammar $grammar): string
    {
        return 'ANY_VALUE('.$this->renderOperand($this->operand, $grammar).')';
    }
}
