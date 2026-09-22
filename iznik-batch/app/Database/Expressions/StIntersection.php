<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;

/**
 * ST_Intersection(g1, g2) - the geometry shared by both operands. Its
 * ST_GeometryType() depends on how the two inputs meet: two overlapping
 * polygons yield a POLYGON/MULTIPOLYGON, but two polygons that only touch
 * along an edge or at a point yield a LINESTRING/POINT (verified
 * empirically), and passing THAT into ST_Area() throws (see StArea) rather
 * than returning 0 - guard with StGeometryType first.
 *
 * Operand rendering (see RendersOperands): bare strings are column names,
 * Value::of()/nested Expressions (e.g. Coalesce) embed as-is.
 *
 * NOT BOUND - literal operands are rendered via Grammar::escape() (see
 * Value's docblock). Column identifiers go through Grammar::wrap().
 */
final class StIntersection implements ExpressionContract
{
    use RendersOperands;

    public function __construct(
        private readonly mixed $g1,
        private readonly mixed $g2,
    ) {
    }

    public function getValue(Grammar $grammar): string
    {
        return 'ST_Intersection('
            .$this->renderOperand($this->g1, $grammar).', '
            .$this->renderOperand($this->g2, $grammar)
            .')';
    }
}
