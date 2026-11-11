<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;

/**
 * ST_GeomFromText(wkt, srid) - parses a WKT string (e.g. 'POINT(1 2)') into
 * a geometry value under the given SRID. Every call site in this codebase
 * supplies both arguments (there is no bare-WKT, default-SRID usage), so
 * $srid is required rather than optional - unlike StSrid, which has a
 * genuine 1-arg getter form.
 *
 * Operand rendering (see RendersOperands): a bare string $wkt is treated as
 * a COLUMN, per the standard rule - this is virtually never what's wanted
 * for a WKT string, so callers embedding a literal WKT value (the common
 * case, e.g. `StGeomFromText(Value::of("POINT($lng $lat)"), 3857)`) MUST
 * wrap it in Value::of(). A nested Expression (e.g. Concat building
 * `POINT(lng lat)` from columns) also composes directly.
 *
 * NOT BOUND - literal operands are rendered via Grammar::escape() (see
 * Value's docblock). Column identifiers go through Grammar::wrap().
 */
final class StGeomFromText implements ExpressionContract
{
    use RendersOperands;

    public function __construct(
        private readonly mixed $wkt,
        private readonly mixed $srid,
    ) {
    }

    public function getValue(Grammar $grammar): string
    {
        return 'ST_GeomFromText('
            .$this->renderOperand($this->wkt, $grammar).', '
            .$this->renderOperand($this->srid, $grammar)
            .')';
    }
}
