<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;
use InvalidArgumentException;

/**
 * `<operand> COLLATE <collation>` - the postfix collation operator, used to
 * force a specific comparison collation on a join/where predicate where the
 * two sides might otherwise disagree (MySQL raises "Illegal mix of
 * collations" rather than silently picking one), e.g.
 * `LEFT JOIN items i ON i.name = bi.name COLLATE utf8mb4_unicode_ci`, built
 * as `$join->on('i.name', '=', new Collate('bi.name', 'utf8mb4_unicode_ci'))`.
 *
 * $collation is a SQL keyword token, not a bindable/escapable value - MySQL
 * does not accept `COLLATE ?`. It is validated against a strict
 * `[A-Za-z0-9_]+` charset so nothing beyond a real collation name can ever
 * reach the generated SQL (the same defensive approach as CastAs's TYPES
 * whitelist, just against a pattern rather than an enumerated list, since
 * MySQL ships 300+ collations).
 *
 * Operand rendering (see RendersOperands): a bare string is a column name,
 * Value::of()/nested Expressions embed as-is.
 *
 * NOT BOUND - a literal operand is rendered via Grammar::escape() (see
 * Value's docblock). Column identifiers go through Grammar::wrap().
 */
final class Collate implements ExpressionContract
{
    use RendersOperands;

    public function __construct(
        private readonly mixed $operand,
        private readonly string $collation,
    ) {
        if (! preg_match('/^[A-Za-z0-9_]+$/', $collation)) {
            throw new InvalidArgumentException("Unsupported collation name [{$collation}].");
        }
    }

    public function getValue(Grammar $grammar): string
    {
        return $this->renderOperand($this->operand, $grammar).' COLLATE '.$this->collation;
    }
}
