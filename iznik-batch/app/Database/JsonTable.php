<?php

namespace App\Database;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * JSON_TABLE(json, '$[*]' COLUMNS (...)) as a FROM source: one row per element of a JSON array. Both engines
 * have it (PostgreSQL since 17) with the same shape. They differ in the column type names (DOUBLE is
 * double precision on PostgreSQL) and PostgreSQL needs the document typed as jsonb. The document is passed as
 * a bound parameter by the caller, in the position of the first `?` of the text returned.
 */
final class JsonTable
{
    /**
     * @param  array<string, array{0: string, 1: string}>  $columns  name => [type, JSON path]; the type
     *                                                                is the MySQL name (BIGINT, DOUBLE, ...)
     * @param  string  $document  SQL for the document: `?` for a bound one, or a column
     */
    public static function from(array $columns, string $alias, string $document = '?', ?Connection $connection = null): string
    {
        $connection ??= DB::connection();
        $pg = $connection->getDriverName() === 'pgsql';

        $defs = [];
        foreach ($columns as $name => [$type, $path]) {
            if ($pg && strtoupper($type) === 'DOUBLE') {
                $type = 'double precision';
            }
            $defs[] = $name.' '.$type." PATH '".$path."'";
        }

        $doc = $pg ? 'CAST('.$document.' AS jsonb)' : $document;

        return 'JSON_TABLE('.$doc.", '$[*]' COLUMNS (".implode(', ', $defs).')) AS '.$alias;
    }
}
