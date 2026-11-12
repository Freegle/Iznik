<?php

namespace App\Database;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Dropping a session-local temporary table without ever touching a permanent table of the same name.
 * Schema::dropIfExists() cannot promise that: it emits a plain DROP TABLE. MySQL has DROP TEMPORARY TABLE;
 * PostgreSQL has no such statement, but a name qualified with pg_temp can only ever name a temporary table.
 */
final class TemporaryTable
{
    public static function dropIfExists(string $name, ?Connection $connection = null): void
    {
        $connection ??= DB::connection();
        $grammar = $connection->getQueryGrammar();

        if ($connection->getDriverName() === 'pgsql') {
            $connection->statement('DROP TABLE IF EXISTS pg_temp.'.$grammar->wrap($name));

            return;
        }

        $connection->statement('DROP TEMPORARY TABLE IF EXISTS '.$name);
    }
}
