<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;

/**
 * ST_AsText(geom) - renders a geometry value as its WKT string (e.g.
 * 'POINT(1 2)'), or NULL if the operand is NULL.
 *
 * Operand rendering (see RendersOperands): a bare string is a column name
 * (e.g. `polyindex`), Value::of()/nested Expressions (e.g. StUnion,
 * StGeomFromText, StBuffer) embed as-is - this is how call sites like
 * `ST_AsText(ST_Union(a, b))` or `ST_AsText(COALESCE(ourgeometry, geometry))`
 * compose.
 *
 * NOT BOUND - a literal operand is rendered via Grammar::escape() (see
 * Value's docblock). Column identifiers go through Grammar::wrap().
 */
final class StAsText implements ExpressionContract
{
    use RendersOperands;

    public function __construct(private readonly mixed $geom)
    {
    }

    public function getValue(Grammar $grammar): string
    {
        return 'ST_AsText('.$this->renderOperand($this->geom, $grammar).')';
    }
}
