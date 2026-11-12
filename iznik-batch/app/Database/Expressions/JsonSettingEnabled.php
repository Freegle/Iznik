<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\PostgresDialect;
use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\ConditionExpression;
use Illuminate\Database\Grammar;

/**
 * True when the JSON setting at a path is present and is not 0 or false: the test for an opt-out switch
 * stored as a number or a boolean. MySQL: COALESCE(JSON_EXTRACT(col, path), 0) != 0. PostgreSQL reads the
 * value as text and tests it against '0' and 'false', because jsonb does not compare with an integer.
 *
 * $path must be a literal (Value::of('$.a.b')).
 */
final class JsonSettingEnabled implements ConditionExpression
{
    use PostgresDialect;
    use RendersOperands;

    public function __construct(
        private readonly mixed $operand,
        private readonly mixed $path,
    ) {
    }

    public function getValue(Grammar $grammar): string
    {
        if ($this->isPostgres($grammar)) {
            return "(COALESCE(CAST({$this->renderOperand($this->operand, $grammar)} AS jsonb) #>> "
                .$grammar->escape($this->jsonPathArray($this->path))."::text[], '0') NOT IN ('0', 'false'))";
        }

        return 'COALESCE('.(new JsonExtract($this->operand, $this->path))->getValue($grammar).', 0) != 0';
    }
}
