<?php

namespace App\Database;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * REPLACE INTO: insert rows, first deleting any row that has the same unique key.
 *
 * Unlike an upsert, a replaced row loses every column the new row does not set (they take their defaults), so a
 * statement that sets only some columns resets the rest. MySQL has REPLACE; PostgreSQL has no equivalent, so
 * there it is the same delete and insert in one transaction.
 */
final class Replace
{
    private const CHUNK = 200;

    /**
     * @param  array<int, array<string, mixed>>  $rows  Every row has the same columns.
     * @param  array<int, string>  $uniqueBy  The columns of the unique key that identifies a row.
     */
    public static function rows(string $table, array $rows, array $uniqueBy): void
    {
        if ($rows === []) {
            return;
        }

        $connection = DB::connection();
        $grammar = $connection->getQueryGrammar();
        $columns = array_keys($rows[0]);

        if ($connection->getDriverName() !== 'pgsql') {
            $row = '('.implode(', ', array_fill(0, count($columns), '?')).')';
            $connection->statement(
                'REPLACE INTO '.$grammar->wrapTable($table).' ('.$grammar->columnize($columns).') VALUES '
                .implode(', ', array_fill(0, count($rows), $row)),
                array_merge(...array_map(fn (array $r) => array_values($r), $rows))
            );

            return;
        }

        $connection->transaction(function () use ($table, $rows, $uniqueBy) {
            foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                DB::table($table)->where(function (Builder $query) use ($chunk, $uniqueBy) {
                    foreach ($chunk as $row) {
                        $query->orWhere(function (Builder $one) use ($row, $uniqueBy) {
                            foreach ($uniqueBy as $column) {
                                $one->where($column, $row[$column]);
                            }
                        });
                    }
                })->delete();

                DB::table($table)->insert($chunk);
            }
        });
    }
}
