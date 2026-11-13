<?php

namespace App\Database\Expressions;

use App\Database\Expressions\Concerns\PostgresDialect;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;

/**
 * NOW() - the current server date/time, evaluated by MySQL at statement
 * execution, not by PHP at query-build time. Deliberately NOT
 * `Value::of(now())`: that would bake in PHP's clock (and Laravel's
 * possibly test-frozen `now()`) as an escaped literal, whereas call sites
 * using this class want the database's own clock, consistently with the
 * rest of the expression the function sits in (e.g.
 * `DATEDIFF(NOW(), posted_at)` scored against MySQL's now, not the request's
 * start time).
 *
 * Zero operands - unlike the rest of this namespace there is nothing to
 * render via RendersOperands.
 */
final class Now implements ExpressionContract
{
    use PostgresDialect;

    public function getValue(Grammar $grammar): string
    {
        // MySQL's NOW() is the time the statement started. PostgreSQL's NOW() is the time the transaction started,
        // which inside a long transaction is earlier than a time written by the same transaction a moment ago.
        // statement_timestamp() is the PostgreSQL clock that behaves like MySQL's NOW().
        return $this->isPostgres($grammar) ? 'statement_timestamp()' : 'NOW()';
    }
}
