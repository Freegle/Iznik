<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\PostgresDialect;
use App\Database\Expressions\Concerns\RendersOperands;
use Illuminate\Contracts\Database\Query\ConditionExpression;
use Illuminate\Database\Grammar;

/**
 * MBRWithin(g1, g2) - true when the bounding box of the first geometry lies within the bounding box of the second. It compares
 * bounding boxes only, so it is cheap and works on geometry too invalid for the exact predicates. On
 * PostgreSQL it renders the PostGIS bounding-box operator `@`, which has the same meaning and returns
 * a boolean where MySQL returns 1 or 0.
 *
 * Operand rendering (see RendersOperands): bare strings are column names, Value::of()/nested
 * Expressions embed as-is.
 */
final class MbrWithin implements ConditionExpression
{
    use PostgresDialect;
    use RendersOperands;

    public function __construct(
        private readonly mixed $g1,
        private readonly mixed $g2,
    ) {
    }

    public function getValue(Grammar $grammar): string
    {
        $a = $this->renderOperand($this->g1, $grammar);
        $b = $this->renderOperand($this->g2, $grammar);

        if ($this->isPostgres($grammar)) {
            return '('.$a.' @ '.$b.')';
        }

        return 'MBRWithin('.$a.', '.$b.')';
    }
}
