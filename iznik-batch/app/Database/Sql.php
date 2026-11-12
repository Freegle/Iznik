<?php

namespace App\Database;

use Illuminate\Support\Facades\DB;

/**
 * Identifier quoting for hand-written (raw) SQL. A table whose name is a reserved word on MySQL (`groups`)
 * needs quoting there and is quoted differently on PostgreSQL: this renders the name with the connection's
 * own grammar.
 */
final class Sql
{
    /** The table name quoted for the default connection: `groups` on MySQL, "groups" on PostgreSQL. */
    public static function table(string $table): string
    {
        return DB::getQueryGrammar()->wrapTable($table);
    }
}
