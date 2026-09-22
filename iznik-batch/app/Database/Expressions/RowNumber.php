<?php

namespace App\Database\Expressions;

use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;

/**
 * ROW_NUMBER() - the window function base call, meaningless on its own;
 * always paired with an OVER (...) clause (see App\Database\Expressions\Over),
 * e.g. `new Over(new RowNumber(), 'count')` renders
 * `ROW_NUMBER() OVER (ORDER BY \`count\`)`.
 *
 * Zero operands - unlike most of this namespace there is nothing to render
 * via RendersOperands.
 */
final class RowNumber implements ExpressionContract
{
    public function getValue(Grammar $grammar): string
    {
        return 'ROW_NUMBER()';
    }
}
