<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\ConditionExpression;
use Illuminate\Database\Grammar;

/**
 * `<operand> IS NULL` or, with $not, `<operand> IS NOT NULL`.
 *
 * The query builder already has whereNull()/whereNotNull()/havingNull() etc,
 * but those only ever emit a top-level WHERE/HAVING clause on a plain
 * column. This class exists for the case those can't reach: an IS [NOT]
 * NULL boolean test used as an OPERAND elsewhere, e.g. inside an aggregate -
 * `SUM(opened_at IS NOT NULL) AS opened` becomes
 * `new Sum(new IsNull('opened_at', not: true))`. Because it implements
 * ConditionExpression it is equally usable directly in where()/having() if a
 * plain column IS NULL check is ever combined with other ConditionExpression
 * objects (e.g. inside a Comparison-and-IsNull OR group).
 *
 * Operand rendering (see RendersOperands): a bare string is a column name,
 * Value::of()/nested Expressions embed as-is.
 */
final class IsNull implements ConditionExpression
{
    use RendersOperands;

    public function __construct(
        private readonly mixed $operand,
        private readonly bool $not = false,
    ) {
    }

    public function getValue(Grammar $grammar): string
    {
        return $this->renderOperand($this->operand, $grammar).($this->not ? ' IS NOT NULL' : ' IS NULL');
    }
}
