<?php

namespace App\Database\Expressions\Concerns;

use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;
use InvalidArgumentException;

/**
 * Shared operand-rendering rules for every expression class in this
 * namespace. Applying one rule set consistently everywhere means a caller
 * never has to remember "is the value in this position escaped or wrapped" -
 * it is always determined purely by the operand's PHP type:
 *
 *  - Expression (incl. any class in this namespace, and App\Database\
 *    Expressions\Value for literals): rendered by delegating to its own
 *    getValue($grammar). This is how nesting and literals both compose.
 *  - string: ALWAYS a column identifier, passed through Grammar::wrap().
 *    To embed a literal string, wrap it: `Value::of('literal')`.
 *  - int|float|bool|null: unambiguous literals, rendered via
 *    Grammar::escape() (see App\Database\Expressions\Value's docblock for
 *    why escaping rather than PDO binding is used).
 */
trait RendersOperands
{
    protected function renderOperand(mixed $operand, Grammar $grammar): string
    {
        if ($operand instanceof ExpressionContract) {
            return (string) $operand->getValue($grammar);
        }

        if (is_string($operand)) {
            return $grammar->wrap($operand);
        }

        if (is_int($operand) || is_float($operand) || is_bool($operand) || $operand === null) {
            return (string) $grammar->escape($operand);
        }

        throw new InvalidArgumentException(
            'Unsupported operand type ['.get_debug_type($operand).']. Pass a column name as a '
            .'plain string, a literal via App\Database\Expressions\Value::of(), or a nested Expression.'
        );
    }
}
