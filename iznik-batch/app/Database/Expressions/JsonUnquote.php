<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;

/**
 * JSON_UNQUOTE(operand) - strips the surrounding quotes MySQL puts around a
 * JSON string value, e.g. turning the JSON string `"London"` (as extracted
 * by JsonExtract) into the SQL string `London`. Typically wraps a
 * JsonExtract: `new JsonUnquote(new JsonExtract('metadata',
 * Value::of('$.area')))`.
 *
 * Operand rendering (see RendersOperands): a bare string is a column name,
 * Value::of()/nested Expressions embed as-is.
 *
 * NOT BOUND - a literal operand is rendered via Grammar::escape() (see
 * Value's docblock). Column identifiers go through Grammar::wrap().
 */
final class JsonUnquote implements ExpressionContract
{
    use RendersOperands;

    public function __construct(private readonly mixed $operand)
    {
    }

    public function getValue(Grammar $grammar): string
    {
        return 'JSON_UNQUOTE('.$this->renderOperand($this->operand, $grammar).')';
    }
}
