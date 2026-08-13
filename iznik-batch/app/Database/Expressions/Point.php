<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;

/**
 * POINT(x, y) - MySQL's constructor for a Point geometry from two
 * coordinate values, SRID 0. Distinct from StGeomFromText: this builds a
 * point directly from numeric x/y operands (typically per-row columns, e.g.
 * from a JSON_TABLE()), where composing a WKT string at PHP-compile time
 * isn't possible. Combine with StSrid's 2-arg setter form (`new
 * StSrid(new Point($lng, $lat), $srid)`) to label the result under the
 * codebase's canonical SRID, matching `ST_SRID(POINT(lng, lat), ?)`.
 *
 * Operand rendering (see RendersOperands): bare strings are column names
 * (e.g. `jt.lng`), Value::of()/nested Expressions embed as-is, other scalars
 * are literals.
 *
 * NOT BOUND - literal operands are rendered via Grammar::escape() (see
 * Value's docblock). Column identifiers go through Grammar::wrap().
 */
final class Point implements ExpressionContract
{
    use RendersOperands;

    public function __construct(
        private readonly mixed $x,
        private readonly mixed $y,
    ) {
    }

    public function getValue(Grammar $grammar): string
    {
        return 'POINT('
            .$this->renderOperand($this->x, $grammar).', '
            .$this->renderOperand($this->y, $grammar)
            .')';
    }
}
