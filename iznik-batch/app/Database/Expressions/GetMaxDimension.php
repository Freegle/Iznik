<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;

/**
 * GetMaxDimension(geom) - this project's own stored SQL function (see
 * database/migrations/2026_02_20_000002_create_stored_functions.php), NOT a
 * MySQL built-in. Returns 0 for a geometry with dimension <= 1 (points,
 * lines), otherwise the diagonal of a circle whose area equals the
 * geometry's ST_AREA() - used as a cheap "how big is this polygon" scalar,
 * e.g. `locations.maxdimension` populated at insert time via
 * `GetMaxDimension(ST_GeomFromText(?, ?))`.
 *
 * NULL input is NOT propagated to NULL, unlike every ST_* function in this
 * namespace - verified empirically: GetMaxDimension(NULL) returns 0, not
 * NULL, because the function's `IF ST_Dimension(g) > 1` test is false (NULL
 * is neither true nor > 1) and falls through to the ELSE 0 branch.
 *
 * Operand rendering (see RendersOperands): a bare string is a column name,
 * Value::of()/nested Expressions (e.g. StGeomFromText) embed as-is.
 *
 * NOT BOUND - a literal operand is rendered via Grammar::escape() (see
 * Value's docblock). Column identifiers go through Grammar::wrap().
 */
final class GetMaxDimension implements ExpressionContract
{
    use RendersOperands;

    public function __construct(private readonly mixed $geom)
    {
    }

    public function getValue(Grammar $grammar): string
    {
        return 'GetMaxDimension('.$this->renderOperand($this->geom, $grammar).')';
    }
}
