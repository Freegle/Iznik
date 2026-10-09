<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\ConditionExpression;
use Illuminate\Database\Grammar;

/**
 * JSON_CONTAINS(target, candidate) - MySQL predicate: 1 if the $candidate
 * JSON document/scalar is contained within $target, 0 if not, NULL if
 * either argument is NULL. Distinct from Query\Builder::whereJsonContains(),
 * which only ever compares a JSON column against a PHP value it json_encodes
 * and BINDS - this class exists for the case that can't cover: $candidate
 * itself needs to be a dynamic SQL expression (e.g. another row's column
 * cast to JSON via CastAs), not a fixed PHP literal, e.g.
 * `JSON_CONTAINS(rr.reachable_group_ids, CAST(g.id AS JSON))` - is this
 * group's id one of the ids the routing server said were reachable.
 *
 * Usable anywhere a ConditionExpression is accepted:
 *
 *  - directly as the sole argument to Query\Builder::where()/having(), and
 *  - as an ordinary value operand elsewhere, because ConditionExpression
 *    extends Expression.
 *
 * Operand rendering (see RendersOperands): bare strings are column names,
 * Value::of()/nested Expressions (e.g. CastAs) embed as-is.
 *
 * NOT BOUND - literal operands are rendered via Grammar::escape() (see
 * Value's docblock). Column identifiers go through Grammar::wrap().
 */
final class JsonContains implements ConditionExpression
{
    use RendersOperands;

    public function __construct(
        private readonly mixed $target,
        private readonly mixed $candidate,
    ) {
    }

    public function getValue(Grammar $grammar): string
    {
        return 'JSON_CONTAINS('
            .$this->renderOperand($this->target, $grammar).', '
            .$this->renderOperand($this->candidate, $grammar)
            .')';
    }
}
