<?php

namespace App\Database\Expressions;

use Illuminate\Contracts\Database\Query\ConditionExpression;
use Illuminate\Database\Grammar;
use Illuminate\Database\Query\Builder;

/**
 * `[NOT] EXISTS (<subquery>)` as a structured, composable ConditionExpression
 * - distinct from Query\Builder::whereExists()/whereNotExists(), which only
 * ever add a top-level WHERE clause. This class exists for the case an EXISTS
 * test needs to sit as one leaf of a larger AND/OR tree built from this
 * namespace's own Logical/Comparison/etc (e.g. `(A OR (B AND NOT EXISTS
 * (...)))`), which whereNotExists()'s closure-only API cannot reach - or
 * anywhere else a plain ConditionExpression is accepted (where()/having(),
 * CaseWhen::when()).
 *
 * Delegates the subquery rendering to App\Database\Expressions\Subquery, so
 * the same "the given builder must carry ZERO bindings - build its
 * predicates with this namespace's own Comparison/Value::of()/etc so it is
 * fully self-contained SQL text" rule applies here too (see Subquery's
 * docblock for why: an Expression's getValue(Grammar): string has no side
 * channel to splice a value into the outer query's bindings array).
 */
final class Exists implements ConditionExpression
{
    private readonly Subquery $subquery;

    public function __construct(
        Builder $query,
        private readonly bool $not = false,
    ) {
        $this->subquery = new Subquery($query);
    }

    public function getValue(Grammar $grammar): string
    {
        return ($this->not ? 'NOT EXISTS ' : 'EXISTS ').$this->subquery->getValue($grammar);
    }
}
