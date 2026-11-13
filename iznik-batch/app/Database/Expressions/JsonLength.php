<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\PostgresDialect;
use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;

/**
 * JSON_LENGTH(operand, path): the number of elements of the array, or members of the object, at the path.
 * Any other JSON value has length 1 and a missing path or NULL gives NULL.
 *
 * The operand may be text holding JSON or jsonb. The path is a literal such as Value::of('$.items'); only simple
 * member and index steps are supported on PostgreSQL.
 */
final class JsonLength implements ExpressionContract
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
            $v = '(CAST('.$this->renderOperand($this->operand, $grammar).' AS jsonb) #> '
                .$grammar->escape($this->jsonPathArray($this->path)).'::text[])';

            return "(CASE WHEN {$v} IS NULL THEN NULL WHEN jsonb_typeof({$v}) = 'array' THEN jsonb_array_length({$v}) "
                ."WHEN jsonb_typeof({$v}) = 'object' THEN (SELECT COUNT(*) FROM jsonb_object_keys({$v})) ELSE 1 END)";
        }

        return 'JSON_LENGTH('.$this->renderOperand($this->operand, $grammar)
            .', '.$this->renderOperand($this->path, $grammar).')';
    }
}
