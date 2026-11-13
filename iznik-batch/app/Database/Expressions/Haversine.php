<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;

/**
 * haversine(lat1, lon1, lat2, lon2) - this project's own stored SQL
 * function (see database/migrations/2026_02_20_000002_create_stored_functions.php),
 * NOT a MySQL built-in. Returns the distance in MILES at 69 per degree of the
 * angle between two lat/lon points (the function's own COMMENT says degrees,
 * but the value is 69 times the angle in degrees), in single precision, and
 * NULL for some identical points. It is used throughout as a cheap
 * distance-ordering tiebreak, e.g. `ORDER BY haversine(lat, lng, ?, ?) ASC`.
 * PostgreSQL has the same function with the same answers
 * (database/postgres/functions.sql); docs/developers/reference/database-engines.md
 * says how it differs from the 3959 mile distance the rest of the code uses.
 *
 * Argument order matches the stored function exactly: (lat1, lon1, lat2,
 * lon2) - note lat BEFORE lon in each pair, which is easy to get backwards
 * against this codebase's usual `POINT(lng, lat)` / `(lng, lat)` convention
 * used by the ST_* spatial functions.
 *
 * Operand rendering (see RendersOperands): a bare string is a column name
 * (e.g. `groups.lat`), Value::of()/nested Expressions embed as-is.
 *
 * NOT BOUND - literal operands are rendered via Grammar::escape() (see
 * Value's docblock). Column identifiers go through Grammar::wrap().
 */
final class Haversine implements ExpressionContract
{
    use RendersOperands;

    public function __construct(
        private readonly mixed $lat1,
        private readonly mixed $lon1,
        private readonly mixed $lat2,
        private readonly mixed $lon2,
    ) {
    }

    public function getValue(Grammar $grammar): string
    {
        return 'haversine('
            .$this->renderOperand($this->lat1, $grammar).', '
            .$this->renderOperand($this->lon1, $grammar).', '
            .$this->renderOperand($this->lat2, $grammar).', '
            .$this->renderOperand($this->lon2, $grammar)
            .')';
    }
}
