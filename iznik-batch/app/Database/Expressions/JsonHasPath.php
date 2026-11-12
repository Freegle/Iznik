<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\PostgresDialect;
use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\ConditionExpression;
use Illuminate\Database\Grammar;

/**
 * True when a JSON document holds the given path, without copying the value out. MySQL:
 * JSON_CONTAINS_PATH(doc, 'one', path). PostgreSQL: jsonb_path_exists(doc, path::jsonpath); the paths this
 * codebase uses ($.a.b and $.a."quoted key") mean the same in both. The path is an operand: a literal
 * (Value::of) or a placeholder.
 */
final class JsonHasPath implements ConditionExpression
{
    use PostgresDialect;
    use RendersOperands;

    public function __construct(
        private readonly mixed $document,
        private readonly mixed $path,
    ) {
    }

    public function getValue(Grammar $grammar): string
    {
        $document = $this->renderOperand($this->document, $grammar);
        $path = $this->renderOperand($this->path, $grammar);

        if ($this->isPostgres($grammar)) {
            return "jsonb_path_exists(CAST({$document} AS jsonb), CAST({$path} AS jsonpath))";
        }

        return "JSON_CONTAINS_PATH({$document}, 'one', {$path})";
    }
}
