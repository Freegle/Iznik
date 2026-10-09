<?php

namespace App\Database\Expressions;

use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;

/**
 * Wraps a scalar so it is embedded in SQL as an explicit literal rather than
 * being interpreted as a column identifier.
 *
 * Every class in this namespace treats a bare PHP string operand as a column
 * name (passed through Grammar::wrap()). That is unambiguous for identifiers
 * but leaves no way to say "this string is a literal value" - hence this
 * wrapper. int/float/bool/null are never ambiguous (no column name is an
 * int), so they may be passed to the other classes directly without wrapping.
 *
 * NOT BOUND. See the class docblock on each expression in this namespace for
 * why: the Illuminate\Contracts\Database\Query\Expression contract only
 * returns a string/int/float from getValue() - it has no side channel to add
 * a value to the enclosing Illuminate\Database\Query\Builder's bindings
 * array. This class instead renders the value via Grammar::escape(), which
 * delegates to Connection::escape() - for strings that is the PDO driver's
 * quote() method, so the value is still safe against SQL injection, just
 * resolved at SQL-compile time rather than at PDO execute time.
 */
final class Value implements ExpressionContract
{
    public function __construct(private readonly string|int|float|bool|null $value)
    {
    }

    public static function of(string|int|float|bool|null $value): self
    {
        return new self($value);
    }

    public function getValue(Grammar $grammar): string
    {
        return (string) $grammar->escape($this->value);
    }
}
