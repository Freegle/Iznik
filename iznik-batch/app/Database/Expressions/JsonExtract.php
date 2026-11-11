<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;

/**
 * JSON_EXTRACT(operand, path) - extracts the value at $path from a JSON
 * document/column, returning a JSON value (a JSON string stays quoted; pair
 * with JsonUnquote to get the bare scalar).
 *
 * $path is a JSON path expression such as '$.area' - always a string
 * literal, never a column, so callers pass it pre-wrapped:
 * `new JsonExtract('metadata', Value::of('$.area'))`. A bare PHP string for
 * $path would otherwise be treated as a column identifier by
 * RendersOperands, which is never what a JSON path means.
 *
 * Operand rendering (see RendersOperands): a bare string is a column name,
 * Value::of()/nested Expressions embed as-is.
 *
 * NOT BOUND - a literal operand is rendered via Grammar::escape() (see
 * Value's docblock). Column identifiers go through Grammar::wrap().
 */
final class JsonExtract implements ExpressionContract
{
    use RendersOperands;

    public function __construct(
        private readonly mixed $operand,
        private readonly mixed $path,
    ) {
    }

    public function getValue(Grammar $grammar): string
    {
        return 'JSON_EXTRACT('.$this->renderOperand($this->operand, $grammar)
            .', '.$this->renderOperand($this->path, $grammar).')';
    }
}
