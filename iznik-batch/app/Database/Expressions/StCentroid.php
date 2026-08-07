<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;

/**
 * ST_Centroid(geom) - the mathematical centroid point of a geometry, or NULL
 * if the operand is NULL. Used to cache a fast lat/lng on a row alongside its
 * full geometry, e.g. `lng = ST_X(ST_Centroid(geometry))` (see StX/StY for
 * pulling coordinates back out).
 *
 * Operand rendering (see RendersOperands): a bare string is a column name,
 * Value::of()/nested Expressions (e.g. StGeomFromText) embed as-is.
 *
 * NOT BOUND - a literal operand is rendered via Grammar::escape() (see
 * Value's docblock). Column identifiers go through Grammar::wrap().
 */
final class StCentroid implements ExpressionContract
{
    use RendersOperands;

    public function __construct(private readonly mixed $geom)
    {
    }

    public function getValue(Grammar $grammar): string
    {
        return 'ST_Centroid('.$this->renderOperand($this->geom, $grammar).')';
    }
}
