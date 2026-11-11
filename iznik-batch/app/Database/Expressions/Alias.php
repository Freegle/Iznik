<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;

/**
 * `<expression> AS <alias>` - gives a computed expression a result-column
 * name, for use in a SELECT list (e.g. `new Alias(new Count(), 'cnt')`
 * renders `COUNT(*) AS \`cnt\``) or a derived-table column.
 *
 * This is needed because Illuminate\Database\Grammar::wrap() special-cases
 * anything implementing the Expression contract to return its getValue()
 * verbatim (see the `isExpression($value)` branch at the very top of
 * wrap()) - it never reaches the "does this string contain ' as '" alias
 *-splitting logic below that, which is how a plain column string like
 * `'groupid as gid'` gets its alias. A structured Expression therefore has
 * no way to carry an alias unless the alias is rendered as part of its own
 * SQL text, which is exactly what this class does.
 *
 * The alias itself is not a value or a bare column reference - it is a new
 * identifier being introduced, so it is rendered via Grammar::wrap()
 * directly (consistent with how RendersOperands treats any bare string
 * elsewhere in this namespace), never escaped as a literal.
 *
 * Operand rendering for $expression (see RendersOperands): a bare string is
 * a column name, Value::of()/nested Expressions embed as-is - so
 * `new Alias('groupid', 'gid')` and `'groupid as gid'` render identically,
 * the class exists for the case a plain string alias can't cover.
 */
final class Alias implements ExpressionContract
{
    use RendersOperands;

    public function __construct(
        private readonly mixed $expression,
        private readonly string $alias,
    ) {
    }

    public function getValue(Grammar $grammar): string
    {
        return $this->renderOperand($this->expression, $grammar).' AS '.$grammar->wrap($this->alias);
    }
}
