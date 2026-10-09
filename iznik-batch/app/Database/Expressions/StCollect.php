<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;

/**
 * ST_Collect(operand) - MySQL's spatial AGGREGATE function: gathers every
 * non-NULL geometry value across the rows in scope (the whole result set, or
 * each GROUP BY bucket) into a single MultiPoint/MultiLineString/
 * GeometryCollection value, mirroring SUM()/GROUP_CONCAT(). This is the ONLY
 * form MySQL provides - unlike PostGIS, there is no 2-argument non-aggregate
 * `ST_Collect(g1, g2)` (verified empirically: MySQL 8.0 raises ERROR 1064 on
 * that syntax). NULL rows are skipped, exactly like other aggregates; a
 * group with no non-NULL rows returns NULL, not an empty collection.
 *
 * Because this is an aggregate, $operand is virtually always a bare column
 * name - unlike the rest of this namespace, wrapping it in Value::of() would
 * aggregate a constant, which is a legal but unusual thing to want.
 *
 * Operand rendering (see RendersOperands): a bare string is a column name,
 * Value::of()/nested Expressions embed as-is.
 *
 * NOT BOUND - a literal operand is rendered via Grammar::escape() (see
 * Value's docblock). Column identifiers go through Grammar::wrap().
 */
final class StCollect implements ExpressionContract
{
    use RendersOperands;

    public function __construct(private readonly mixed $operand)
    {
    }

    public function getValue(Grammar $grammar): string
    {
        return 'ST_Collect('.$this->renderOperand($this->operand, $grammar).')';
    }
}
