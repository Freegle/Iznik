<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;

/**
 * ST_Simplify(geom, maxDistance) - the Douglas-Peucker simplification of a
 * geometry, removing vertices that deviate from the simplified line by less
 * than $maxDistance (in the geometry's SRID unit). Two edge cases verified
 * empirically, both load-bearing for this codebase's callers:
 *
 *  - a $maxDistance large relative to the geometry's own extent collapses it
 *    entirely and ST_Simplify returns NULL (not a degenerate point/empty
 *    geometry) - see Ripple\ExpandService::storeWithUndoLogShrink()'s
 *    comment on why its tolerance ladder must be degree-scale, not
 *    metre-scale, for this reason;
 *  - the simplified output can itself be self-intersecting even for valid
 *    polygonal input - ExpandService::simplifyPolygonWkt() always wraps the
 *    result in StBuffer(..., 0) to repair it before use.
 *
 *
 * Operand rendering (see RendersOperands): a bare string $geom is a column
 * name, Value::of()/nested Expressions (e.g. StGeomFromText, StBuffer)
 * embed as-is. $maxDistance follows the same rule - a float/int literal is
 * unambiguous.
 *
 * NOT BOUND - literal operands are rendered via Grammar::escape() (see
 * Value's docblock). Column identifiers go through Grammar::wrap().
 */
final class StSimplify implements ExpressionContract
{
    use RendersOperands;

    public function __construct(
        private readonly mixed $geom,
        private readonly mixed $maxDistance,
    ) {
    }

    public function getValue(Grammar $grammar): string
    {
        return 'ST_Simplify('
            .$this->renderOperand($this->geom, $grammar).', '
            .$this->renderOperand($this->maxDistance, $grammar)
            .')';
    }
}
