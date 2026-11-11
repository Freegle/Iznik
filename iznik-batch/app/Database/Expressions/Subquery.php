<?php

namespace App\Database\Expressions;

use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;
use Illuminate\Database\Query\Builder;
use LogicException;

/**
 * Embeds a fully-built Query\Builder as a parenthesised scalar subquery
 * NESTED inside another Expression's own operand (e.g. as one argument to
 * Coalesce, or a CaseWhen result) - the case Query\Builder's own native
 * sub-builder support (Builder::update()'s `$value instanceof Builder`
 * branch, ::selectSub()) does not reach, because those only special-case a
 * bare Builder sitting directly in a column/value position, not one buried
 * inside another Expression's constructor arguments.
 *
 * REQUIRES the given builder to carry ZERO bindings. Every Expression in
 * this namespace renders via Grammar::escape()/wrap() at SQL-compile time
 * (see Value's docblock) - getValue(Grammar): string has no side channel to
 * splice a value into the OUTER query's bindings array, so a nested
 * subquery can only compose here if it is already fully self-contained SQL
 * text. Build the subquery's WHERE/JOIN predicates with this namespace's own
 * Comparison/Value::of()/etc. instead of the builder's normal bound
 * where($col, $val), exactly as this class's callers do - see
 * ReachBoundsService::unionOuterWithOriginGroup() for the canonical example
 * (a correlated "earliest qualifying group" subquery embedded in
 * `COALESCE(new Subquery($q), 'rr.outer_bound')`). Throws rather than
 * silently dropping bindings if the builder still has any.
 */
final class Subquery implements ExpressionContract
{
    public function __construct(private readonly Builder $query)
    {
    }

    public function getValue(Grammar $grammar): string
    {
        if ($this->query->getBindings() !== []) {
            throw new LogicException(
                'Subquery cannot embed a builder that still has bindings - build its '
                .'predicates with this namespace\'s Comparison/Value::of()/etc. so the '
                .'subquery is fully self-contained SQL text with no placeholders.'
            );
        }

        return '('.$this->query->toSql().')';
    }
}
