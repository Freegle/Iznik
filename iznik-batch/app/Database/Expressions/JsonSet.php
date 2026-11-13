<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\PostgresDialect;
use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;
use InvalidArgumentException;

/**
 * JSON_SET(column, path, value, path, value ...): the document with each path set, where a query builder
 * update of one `column->path` key per statement cannot set two keys of the same column at once on PostgreSQL.
 *
 * $values maps a JSON path such as '$.a.b' to a PHP value: bool, null, int, float or string. On MySQL they are
 * literal arguments (true and false are JSON booleans, null is JSON null). On PostgreSQL the column, which may be
 * text or jsonb, is cast to jsonb and each path is set with jsonb_set, the paths applied in order.
 *
 * Operand rendering (see RendersOperands): a bare string column is a column name. NOT BOUND: values are
 * rendered via Grammar::escape().
 */
final class JsonSet implements ExpressionContract
{
    use RendersOperands;
    use PostgresDialect;

    /** @param  array<string, bool|int|float|string|null>  $values */
    public function __construct(
        private readonly mixed $operand,
        private readonly array $values,
    ) {
        if ($values === []) {
            throw new InvalidArgumentException('JsonSet needs at least one path.');
        }
    }

    public function getValue(Grammar $grammar): string
    {
        $column = $this->renderOperand($this->operand, $grammar);

        if ($this->isPostgres($grammar)) {
            $sql = 'CAST('.$column.' AS jsonb)';
            foreach ($this->values as $path => $value) {
                $sql = 'jsonb_set('.$sql.', '.$grammar->escape($this->jsonPathArray($path)).'::text[], '.$this->pgValue($value, $grammar).')';
            }

            return $sql;
        }

        $args = [];
        foreach ($this->values as $path => $value) {
            $args[] = $grammar->escape($path);
            $args[] = match (true) {
                is_bool($value) => $value ? 'true' : 'false',
                $value === null => 'NULL',
                default => (string) $grammar->escape($value),
            };
        }

        return 'JSON_SET('.$column.', '.implode(', ', $args).')';
    }

    private function pgValue(mixed $value, Grammar $grammar): string
    {
        return match (true) {
            is_bool($value) => $value ? "'true'::jsonb" : "'false'::jsonb",
            $value === null => "'null'::jsonb",
            is_int($value), is_float($value) => 'to_jsonb('.$grammar->escape($value).'::numeric)',
            default => 'to_jsonb('.$grammar->escape((string) $value).'::text)',
        };
    }
}
