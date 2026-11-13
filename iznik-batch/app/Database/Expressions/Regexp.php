<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\PostgresDialect;
use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\ConditionExpression;
use Illuminate\Database\Grammar;

/**
 * `operand REGEXP pattern`, usable anywhere a ConditionExpression is accepted. On PostgreSQL it is the
 * case-insensitive match `operand ~* pattern`, because MySQL's REGEXP ignores case on the usual collations.
 * The pattern should stay within what both engines read (classes, groups, alternation, counted repeats);
 * PostgreSQL does not read MySQL's \d-less shorthands such as [[:<:]].
 *
 * Operand rendering (see RendersOperands): a bare string is a column name, Value::of() a literal.
 */
final class Regexp implements ConditionExpression
{
    use RendersOperands;
    use PostgresDialect;

    public function __construct(
        private readonly mixed $operand,
        private readonly mixed $pattern,
    ) {
    }

    public function getValue(Grammar $grammar): string
    {
        return $this->renderOperand($this->operand, $grammar)
            .($this->isPostgres($grammar) ? ' ~* ' : ' REGEXP ')
            .$this->renderOperand($this->pattern, $grammar);
    }
}
