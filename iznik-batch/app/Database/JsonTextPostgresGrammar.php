<?php

namespace App\Database;

use Illuminate\Database\Query\Grammars\PostgresGrammar;

/**
 * The query builder's JSON path syntax (where('settings->key', ...)) on PostgreSQL.
 *
 * Several JSON columns (users.settings, groups.settings, memberships.settings) are text, as they were
 * stored on MySQL, and PostgreSQL's -> and ->> operators need json or jsonb. The column is cast to jsonb
 * first; for a column that is already jsonb the cast does nothing. MySQL is not affected.
 */
class JsonTextPostgresGrammar extends PostgresGrammar
{
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
