<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;
use InvalidArgumentException;

/**
 * CAST(operand AS type) with an optional (length) or (precision[, scale])
 * parenthesised parameter, e.g. CAST(operand AS CHAR(10)) or
 * CAST(operand AS DECIMAL(10, 2)).
 *
 * The cast target is a SQL keyword, not a value - MySQL's grammar does not
 * allow `CAST(x AS ?)`, so it can be neither bound nor escaped. Instead
 * $type is validated against the TYPES whitelist (the exact set MySQL 8
 * accepts in a CAST expression) and $params are constrained to the `int`
 * type by the constructor's variadic signature, so nothing beyond a
 * known-safe keyword and small integers can ever reach the generated SQL.
 *
 * The operand itself follows the normal RendersOperands rule: a bare string
 * is a column name, Value::of()/nested Expressions embed as-is, other
 * scalars are literals (escaped, not bound - see Value's docblock).
 */
final class CastAs implements ExpressionContract
{
    use RendersOperands;

    public const array TYPES = [
        'BINARY', 'CHAR', 'DATE', 'DATETIME', 'DECIMAL', 'DOUBLE', 'FLOAT',
        'JSON', 'NCHAR', 'SIGNED', 'SIGNED INTEGER', 'TIME', 'UNSIGNED',
        'UNSIGNED INTEGER', 'YEAR',
    ];

    /** Types accepting a single (length) parameter. */
    private const array SINGLE_PARAM_TYPES = ['BINARY', 'CHAR', 'NCHAR'];

    /** Types accepting (precision[, scale]) - 1 or 2 parameters. */
    private const array PRECISION_TYPES = ['DECIMAL', 'DOUBLE', 'FLOAT'];

    private readonly string $type;

    /** @var array<int, int> */
    private readonly array $params;

    public function __construct(
        private readonly mixed $operand,
        string $type,
        int ...$params
    ) {
        $normalized = strtoupper(trim($type));

        if (! in_array($normalized, self::TYPES, true)) {
            throw new InvalidArgumentException(
                "Unsupported CAST target type [{$type}]. Allowed: ".implode(', ', self::TYPES)
            );
        }

        if ($params !== []) {
            if (in_array($normalized, self::SINGLE_PARAM_TYPES, true)) {
                if (count($params) !== 1) {
                    throw new InvalidArgumentException("CAST type [{$normalized}] accepts exactly 1 parameter.");
                }
            } elseif (in_array($normalized, self::PRECISION_TYPES, true)) {
                if (count($params) > 2) {
                    throw new InvalidArgumentException("CAST type [{$normalized}] accepts at most 2 parameters.");
                }
            } else {
                throw new InvalidArgumentException("CAST type [{$normalized}] does not accept parameters.");
            }

            foreach ($params as $param) {
                if ($param < 0) {
                    throw new InvalidArgumentException('CAST type parameters must be non-negative integers.');
                }
            }
        }

        $this->type = $normalized;
        $this->params = $params;
    }

    public function getValue(Grammar $grammar): string
    {
        $type = $this->type;

        if ($this->params !== []) {
            $type .= '('.implode(', ', $this->params).')';
        }

        return 'CAST('.$this->renderOperand($this->operand, $grammar).' AS '.$type.')';
    }
}
