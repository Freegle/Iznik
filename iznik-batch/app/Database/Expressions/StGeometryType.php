<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;

/**
 * ST_GeometryType(geom) - the geometry's type name as a string (e.g.
 * 'POINT', 'POLYGON', 'MULTIPOLYGON'), or NULL if the operand is NULL. Used
 * throughout this codebase to exclude degenerate `POINT` placeholders stored
 * for groups/reach rows with no real area, e.g.
 * `ST_GeometryType(groups.polyindex) <> 'POINT'`.
 *
 * Operand rendering (see RendersOperands): a bare string is a column name,
 * Value::of()/nested Expressions embed as-is.
 *
 * NOT BOUND - a literal operand is rendered via Grammar::escape() (see
 * Value's docblock). Column identifiers go through Grammar::wrap().
 */
final class StGeometryType implements ExpressionContract
{
    use RendersOperands;

    public function __construct(private readonly mixed $geom)
    {
    }

    public function getValue(Grammar $grammar): string
    {
        return 'ST_GeometryType('.$this->renderOperand($this->geom, $grammar).')';
    }
}
