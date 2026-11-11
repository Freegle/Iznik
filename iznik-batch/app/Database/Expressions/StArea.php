<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;

/**
 * ST_Area(geom) - the area of a Polygon/MultiPolygon geometry, or NULL if the
 * operand is NULL. Verified empirically: a non-NULL operand that is NOT a
 * Polygon/MultiPolygon (e.g. the LINESTRING or POINT you get back from
 * ST_Intersection() when two polygons only touch) is a MySQL ERROR (3516
 * "... unexpected type ... in st_area"), not a NULL or zero result - callers
 * must guard with ST_GeometryType() (see StGeometryType) first, typically via
 * CaseWhen, exactly as the codebase's overlap-fraction calculations do.
 *
 * Operand rendering (see RendersOperands): a bare string is a column name,
 * Value::of()/nested Expressions (e.g. StIntersection) embed as-is.
 *
 * NOT BOUND - a literal operand is rendered via Grammar::escape() (see
 * Value's docblock). Column identifiers go through Grammar::wrap().
 */
final class StArea implements ExpressionContract
{
    use RendersOperands;

    public function __construct(private readonly mixed $geom)
    {
    }

    public function getValue(Grammar $grammar): string
    {
        return 'ST_Area('.$this->renderOperand($this->geom, $grammar).')';
    }
}
