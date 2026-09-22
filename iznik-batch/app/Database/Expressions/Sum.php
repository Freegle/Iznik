<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;

/**
 * SUM(operand) - unlike Query\Builder::sum(), which executes immediately and
 * returns a scalar, this renders the aggregate as one SELECT-list expression
 * so it can sit alongside other columns/aggregates under a single GROUP BY
 * (e.g. `SELECT date, SUM(count) AS count ... GROUP BY date`) or be combined
 * with another aggregate via Arithmetic (e.g. a popularity-weighted mean:
 * `SUM(popularity * weight) / SUM(popularity)`).
 *
 * Operand rendering (see RendersOperands): a bare string is a column name,
 * Value::of()/nested Expressions (e.g. Coalesce, Arithmetic) embed as-is -
 * this is how `SUM(COALESCE(weight, ?))` and `SUM(popularity * weight)`
 * compose.
 *
 * NOT BOUND - a literal operand is rendered via Grammar::escape() (see
 * Value's docblock). Column identifiers go through Grammar::wrap().
 */
final class Sum implements ExpressionContract
{
    use RendersOperands;

    public function __construct(private readonly mixed $operand)
    {
    }

    public function getValue(Grammar $grammar): string
    {
        return 'SUM('.$this->renderOperand($this->operand, $grammar).')';
    }
}
