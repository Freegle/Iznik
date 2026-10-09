<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;

/**
 * ST_SRID(geom) or ST_SRID(geom, srid) when $srid is given - MySQL's spatial
 * function has two genuinely different forms this codebase uses both of:
 *
 *  - 1-arg getter: ST_SRID(geometry) reads the SRID a geometry value is
 *    currently labelled with (e.g. `ST_SRID(geometry) <> ?` to find rows
 *    whose stored SRID has drifted from the canonical one).
 *  - 2-arg setter: ST_SRID(geometry, 3857) RELABELS the geometry under the
 *    given SRID without moving any coordinate (a Cartesian reprojection
 *    would need ST_Transform, not this) - used both to fix drifted rows
 *    (`ST_SRID(geometry, ?)`) and, combined with POINT(...), to build a
 *    correctly-labelled point value on the fly (`ST_SRID(POINT(?, ?), 3857)`).
 *
 * Operand rendering (see RendersOperands): a bare string $geom is a column
 * name, Value::of()/nested Expressions (e.g. a POINT(...) built via
 * DB::raw() until this library grows an StPoint) embed as-is. $srid, when
 * given, follows the same rule - an int literal is unambiguous.
 *
 * NOT BOUND - literal operands are rendered via Grammar::escape() (see
 * Value's docblock). Column identifiers go through Grammar::wrap().
 */
final class StSrid implements ExpressionContract
{
    use RendersOperands;

    public function __construct(
        private readonly mixed $geom,
        private readonly mixed $srid = null,
    ) {
    }

    public function getValue(Grammar $grammar): string
    {
        $sql = 'ST_SRID('.$this->renderOperand($this->geom, $grammar);

        if ($this->srid !== null) {
            $sql .= ', '.$this->renderOperand($this->srid, $grammar);
        }

        return $sql.')';
    }
}
