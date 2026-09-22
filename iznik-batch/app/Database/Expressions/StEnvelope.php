<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;

/**
 * ST_Envelope(geom) - the minimum bounding rectangle (MBR) of a geometry, as
 * a Polygon (or the geometry itself, unmodified, if it is already a Point or
 * axis-aligned rectangle) - or NULL if the operand is NULL. Used in this
 * codebase as a degradation fallback when deriving reach bounds fails:
 * `outer_bound = ST_Envelope(polygon)`.
 *
 * Operand rendering (see RendersOperands): a bare string is a column name,
 * Value::of()/nested Expressions (e.g. StGeomFromText) embed as-is.
 *
 * NOT BOUND - a literal operand is rendered via Grammar::escape() (see
 * Value's docblock). Column identifiers go through Grammar::wrap().
 */
final class StEnvelope implements ExpressionContract
{
    use RendersOperands;

    public function __construct(private readonly mixed $geom)
    {
    }

    public function getValue(Grammar $grammar): string
    {
        return 'ST_Envelope('.$this->renderOperand($this->geom, $grammar).')';
    }
}
