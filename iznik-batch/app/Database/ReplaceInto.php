<?php

namespace App\Database;

use Illuminate\Support\Facades\DB;

/**
 * REPLACE INTO: insert rows, first deleting any existing row that collides on a unique key. The whole row is
 * replaced, so columns the statement does not name go back to their defaults, which an upsert would not do.
 * MySQL has the statement; PostgreSQL does the same as a delete and an insert in one transaction.
 */
final class ReplaceInto
{
    /**
     * @param  array<int, array<string, mixed>>  $rows  every row has the same columns
     * @param  array<int, string>  $uniqueBy  the columns that identify a row
     */
    public static function rows(string $table, array $rows, array $uniqueBy): void
    {
        if ($rows === []) {
            return;
        }

        $columns = array_keys($rows[0]);

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $one = '('.implode(', ', array_fill(0, count($columns), '?')).')';
            $bindings = [];
            foreach ($rows as $row) {
                foreach ($columns as $column) {
                    $bindings[] = $row[$column];
                }
            }

            DB::statement(
                'REPLACE INTO '.$table.' ('.implode(', ', $columns).') VALUES '.implode(', ', array_fill(0, count($rows), $one)),
                $bindings
            );

            return;
        }

        DB::transaction(function () use ($table, $rows, $uniqueBy) {
            foreach ($rows as $row) {
                $query = DB::table($table);
                foreach ($uniqueBy as $column) {
                    $query->where($column, $row[$column]);
                }
                $query->delete();
            }

            DB::table($table)->insert($rows);
        });
    }
}
