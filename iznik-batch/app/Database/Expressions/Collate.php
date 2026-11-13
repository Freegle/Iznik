<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\PostgresDialect;
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
    use PostgresDialect;

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
        if ($this->isPostgres($grammar)) {
            // MySQL's _ci collations compare without regard to case; _bin collations compare bytes.
            //
            // A _ci operand is cast to citext, the type the PostgreSQL schema gives MySQL's case-insensitive
            // text columns. Compared with a citext column (items.name), that is citext = citext: it ignores
            // case on both sides and can use the column's own index. LOWER(operand) did neither: the citext
            // column was cast to text and compared case-sensitively with the lowered value, so 'Sofa' in
            // items never matched, and items_name could not be used.
            //
            // The other side has to be citext as well (a citext column, or another _ci Collate). citext
            // compared with plain text or varchar is resolved by PostgreSQL as text = text, which is
            // case-sensitive again.
            $operand = $this->renderOperand($this->operand, $grammar);

            return str_ends_with($this->collation, '_ci') ? 'CAST('.$operand.' AS citext)' : $operand.' COLLATE "C"';
        }

        return $this->renderOperand($this->operand, $grammar).' COLLATE '.$this->collation;
    }
}
