<?php

namespace App\Database;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\PostgresGrammar;

/**
 * The query builder on PostgreSQL where it differs from stock Laravel.
 *
 * Index hints (forceIndex, ignoreIndex) are MySQL optimiser hints and render as nothing here.
 *
 * JSON paths (where('settings->key', ...)):
 * Several JSON columns (users.settings, groups.settings, memberships.settings) are text, as they were
 * stored on MySQL, and PostgreSQL's -> and ->> operators need json or jsonb. The column is cast to jsonb
 * first; for a column that is already jsonb the cast does nothing. MySQL is not affected.
 */
class JsonTextPostgresGrammar extends PostgresGrammar
{
    protected function compileIndexHint(Builder $query, $indexHint)
    {
        return '';
    }

    protected function wrapJsonSelector($value)
    {
        $path = explode('->', $value);

        $field = $this->wrapSegments(explode('.', array_shift($path))).'::jsonb';

        $wrappedPath = $this->wrapJsonPathAttributes($path);

        $attribute = array_pop($wrappedPath);

        if (! empty($wrappedPath)) {
            return $field.'->'.implode('->', $wrappedPath).'->>'.$attribute;
        }

        return $field.'->>'.$attribute;
    }
}
