<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\PostgresDialect;
use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;

/**
 * The number at a JSON path, for comparing or doing arithmetic with.
 *
 * MySQL compares a JSON value with a number directly, so this renders as JSON_EXTRACT(operand, path).
 * PostgreSQL cannot compare jsonb with a number, so it renders the value as numeric: a JSON number as
 * itself, a JSON boolean as 1 or 0, and NULL for a missing path, a null or any other kind of value.
 */
final class JsonNumber implements ExpressionContract
{
    use RendersOperands;
    use PostgresDialect;

    public function __construct(
        private readonly mixed $operand,
        private readonly mixed $path,
    ) {
    }

    public function getValue(Grammar $grammar): string
    {
        if ($this->isPostgres($grammar)) {
            $value = '(CAST('.$this->renderOperand($this->operand, $grammar).' AS jsonb) #> '
                .$grammar->escape($this->jsonPathArray($this->path)).'::text[])';

            return "(CASE jsonb_typeof({$value}) WHEN 'number' THEN ({$value} #>> '{}')::numeric "
                ."WHEN 'boolean' THEN (CASE WHEN {$value} = 'true'::jsonb THEN 1 ELSE 0 END) END)";
        }

        return 'JSON_EXTRACT('.$this->renderOperand($this->operand, $grammar)
            .', '.$this->renderOperand($this->path, $grammar).')';
    }
}
