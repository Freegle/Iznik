<?php

namespace App\Database\Expressions\Concerns;

use App\Database\Expressions\Value;
use Illuminate\Database\Grammar;
use Illuminate\Database\Query\Grammars\PostgresGrammar;

/**
 * The expressions in this namespace render MySQL SQL. Where PostgreSQL spells the same thing differently,
 * or answers differently for the same spelling, the class branches on the grammar it is given: the MySQL text
 * never changes, and the PostgreSQL text is the closest equivalent. tests/Unit/Database/ExpressionsPostgresTest.php
 * pins each branch.
 */
trait PostgresDialect
{
    protected function isPostgres(Grammar $grammar): bool
    {
        return $grammar instanceof PostgresGrammar;
    }

    /**
     * A MySQL JSON path such as `$.a.b` or `$.a[0].c` as a PostgreSQL text array literal, `{a,b}` or `{a,0,c}`.
     * Only simple member and index steps are supported: a quoted member, a wildcard or a range is refused,
     * because it would silently mean something else.
     */
    protected function jsonPathArray(mixed $path): string
    {
        $text = $path instanceof Value ? $path->literal() : $path;

        if (! is_string($text) || $text === '$') {
            return $text === '$' ? '{}' : throw new \InvalidArgumentException('A JSON path must be a literal string such as Value::of(\'$.a.b\').');
        }

        if (preg_match('/^\$((?:\.[A-Za-z_][A-Za-z0-9_]*|\[\d+\])+)$/', $text, $m) !== 1) {
            throw new \InvalidArgumentException("Unsupported JSON path [{$text}] on PostgreSQL: only \$.member and [index] steps.");
        }

        preg_match_all('/\.([A-Za-z_][A-Za-z0-9_]*)|\[(\d+)\]/', $m[1], $steps, PREG_SET_ORDER);

        return '{'.implode(',', array_map(fn (array $s) => $s[2] ?? $s[1], $steps)).'}';
    }
}
