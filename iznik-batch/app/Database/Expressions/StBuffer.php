<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;

/**
 * ST_Buffer(geom, distance) - a geometry expanded (positive $distance) or
 * shrunk (negative $distance) by the given amount in the geometry's SRID
 * unit, or NULL if $geom is NULL. This codebase uses both signs -
 * ReachBoundsService::outerExpr()/innerExpr() buffer the same simplified
 * polygon outward and inward by the same TOLERANCE - and also uses a 0
 * distance as a geometry-repair idiom for invalid (self-intersecting)
 * polygons: `ST_Buffer(geom, 0)`.
 *
 * Operand rendering (see RendersOperands): a bare string $geom is a column
 * name, Value::of()/nested Expressions (e.g. StSimplify, StGeomFromText)
 * embed as-is. $distance follows the same rule - a float/int literal
 * (including negative) is unambiguous.
 *
 * NOT BOUND - literal operands are rendered via Grammar::escape() (see
 * Value's docblock). Column identifiers go through Grammar::wrap().
 */
final class StBuffer implements ExpressionContract
{
    use RendersOperands;

    public function __construct(
        private readonly mixed $geom,
        private readonly mixed $distance,
    ) {
    }

    public function getValue(Grammar $grammar): string
    {
        return 'ST_Buffer('
            .$this->renderOperand($this->geom, $grammar).', '
            .$this->renderOperand($this->distance, $grammar)
            .')';
    }
}
