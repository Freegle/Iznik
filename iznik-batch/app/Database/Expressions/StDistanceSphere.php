<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\PostgresDialect;
use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;

/**
 * ST_Distance_Sphere(g1, g2) - great-circle distance between two Point
 * geometries in METRES (MySQL assumes a spherical Earth), or NULL if either
 * operand is NULL or not a Point. Used e.g. to find the nearest Freegle
 * group to a postcode: `ST_Distance_Sphere(POINT(lng, lat), POINT(?, ?))`.
 *
 * Operand rendering (see RendersOperands): a bare string is a column name,
 * Value::of()/nested Expressions embed as-is.
 *
 * On PostgreSQL it renders ST_DistanceSphere(g1, g2) (PostGIS, geometry points in lon/lat, metres).
 *
 * NOT BOUND - a literal operand is rendered via Grammar::escape() (see
 * Value's docblock). Column identifiers go through Grammar::wrap().
 */
final class StDistanceSphere implements ExpressionContract
{
    use PostgresDialect;
    use RendersOperands;

    public function __construct(
        private readonly mixed $g1,
        private readonly mixed $g2,
    ) {
    }

    public function getValue(Grammar $grammar): string
    {
        $name = $this->isPostgres($grammar) ? 'ST_DistanceSphere(' : 'ST_Distance_Sphere(';

        return $name
            .$this->renderOperand($this->g1, $grammar).', '
            .$this->renderOperand($this->g2, $grammar)
            .')';
    }
}
