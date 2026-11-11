<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;
use InvalidArgumentException;

/**
 * `<function> OVER ([ORDER BY <column> <direction>])` - a window-function
 * call. $function is typically a zero-arg window base like RowNumber, or an
 * ordinary aggregate reused as a window aggregate (e.g. `new Over(new
 * Count())` renders `COUNT(*) OVER ()`, the running/whole-partition count
 * alongside detail rows - something Query\Builder's own ->count(), which
 * executes immediately and collapses to a single scalar, cannot project
 * alongside other columns).
 *
 * $orderBy is a plain column name (not a general Expression - MySQL's window
 * ORDER BY accepts arbitrary expressions too, but every current call site
 * only ever orders by a column, so this class is deliberately narrow; widen
 * it if a real use needs more).
 *
 * NOT BOUND - $function is rendered via RendersOperands; $orderBy is always
 * a column identifier, rendered via Grammar::wrap().
 */
final class Over implements ExpressionContract
{
    use RendersOperands;

    public const array DIRECTIONS = ['ASC', 'DESC'];

    private readonly ?string $direction;

    public function __construct(
        private readonly mixed $function,
        private readonly ?string $orderBy = null,
        ?string $direction = null,
    ) {
        if ($orderBy !== null) {
            $normalized = strtoupper(trim($direction ?? 'ASC'));

            if (! in_array($normalized, self::DIRECTIONS, true)) {
                throw new InvalidArgumentException(
                    "Unsupported ORDER BY direction [{$direction}]. Allowed: ".implode(', ', self::DIRECTIONS)
                );
            }

            $this->direction = $normalized;
        } else {
            $this->direction = null;
        }
    }

    public function getValue(Grammar $grammar): string
    {
        $sql = $this->renderOperand($this->function, $grammar).' OVER (';

        if ($this->orderBy !== null) {
            $sql .= 'ORDER BY '.$grammar->wrap($this->orderBy).' '.$this->direction;
        }

        return $sql.')';
    }
}
