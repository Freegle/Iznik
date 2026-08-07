<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;

/**
 * ST_X(point) - the X-coordinate (longitude, in this codebase's convention -
 * see the `POINT(lng, lat)` construction throughout) of a Point geometry, or
 * NULL if the operand is NULL. A non-NULL operand that is NOT a Point (e.g.
 * a Polygon) is a MySQL ERROR (3516 "... unexpected type ... in st_x"), not
 * a NULL result - verified empirically. Callers must ensure the operand is
 * known to be a Point.
 *
 * Operand rendering (see RendersOperands): a bare string is a column name
 * (e.g. `messages_spatial.point`), Value::of()/nested Expressions (e.g.
 * StGeomFromText) embed as-is.
 *
 * NOT BOUND - a literal operand is rendered via Grammar::escape() (see
 * Value's docblock). Column identifiers go through Grammar::wrap().
 */
final class StX implements ExpressionContract
{
    use RendersOperands;

    public function __construct(private readonly mixed $point)
    {
    }

    public function getValue(Grammar $grammar): string
    {
        return 'ST_X('.$this->renderOperand($this->point, $grammar).')';
    }
}
