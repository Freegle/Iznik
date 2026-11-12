<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\PostgresDialect;
use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\ConditionExpression;
use Illuminate\Database\Grammar;

/**
 * True when a JSON array column (NULL counts as an empty array) holds a scalar value, for example a group id
 * in a list of group ids. MySQL: JSON_CONTAINS(COALESCE(array, JSON_ARRAY()), CAST(value AS JSON)).
 * PostgreSQL: (COALESCE(array, '[]'::jsonb) @> to_jsonb(value)), because a number has no CAST to jsonb.
 *
 * Operand rendering (see RendersOperands): bare strings are column names, Value::of()/nested Expressions
 * embed as-is.
 */
final class JsonArrayHasValue implements ConditionExpression
{
    use PostgresDialect;
    use RendersOperands;

    public function __construct(
        private readonly mixed $array,
        private readonly mixed $value,
    ) {
    }

    public function getValue(Grammar $grammar): string
    {
        $array = $this->renderOperand($this->array, $grammar);
        $value = $this->renderOperand($this->value, $grammar);

        if ($this->isPostgres($grammar)) {
            return "(COALESCE({$array}, '[]'::jsonb) @> to_jsonb({$value}))";
        }

        return "JSON_CONTAINS(COALESCE({$array}, JSON_ARRAY()), CAST({$value} AS JSON))";
    }
}
