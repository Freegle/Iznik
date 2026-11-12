<?php

namespace App\Database;

use Illuminate\Database\Query\Grammars\PostgresGrammar;

/**
 * PostgreSQL query grammar for a schema where JSON documents are often held in text columns (users.settings,
 * groups.settings, memberships.settings), as they are on MySQL, where the JSON functions accept text.
 *
 * Laravel's PostgreSQL grammar reads a path such as `settings->mylocation->lat` with the `->` operator, which
 * exists for json and jsonb only. The column is cast to jsonb first, so a path works the same on a text column
 * and on a real jsonb column (where the cast changes nothing).
 */
class PostgresTextJsonGrammar extends PostgresGrammar
{
    protected function wrapJsonSelector($value)
    {
        $path = explode('->', $value);

        $field = '('.$this->wrapSegments(explode('.', array_shift($path))).')::jsonb';

        $wrappedPath = $this->wrapJsonPathAttributes($path);

        $attribute = array_pop($wrappedPath);

        if (! empty($wrappedPath)) {
            return $field.'->'.implode('->', $wrappedPath).'->>'.$attribute;
        }

        return $field.'->>'.$attribute;
    }
}
