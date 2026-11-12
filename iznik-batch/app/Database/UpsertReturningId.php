<?php

namespace App\Database;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

/**
 * Insert a row, or on a unique-key collision update the existing one, and give back the row's id either way.
 * MySQL reads it back with ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id); PostgreSQL has ON CONFLICT ... DO
 * UPDATE ... RETURNING id. The query builder's upsert() cannot return an id.
 */
final class UpsertReturningId
{
    /**
     * @param  array<string, mixed>  $values  the row to insert
     * @param  array<int, string>  $uniqueBy  the unique key the collision happens on
     * @param  array<string, mixed>  $update  what to set on the existing row: a value, or an Expression such as DB::raw('NOW()')
     */
    public static function id(string $table, array $values, array $uniqueBy, array $update = []): int
    {
        $connection = DB::connection();
        $grammar = $connection->getQueryGrammar();

        $columns = implode(', ', array_map(fn ($c) => $grammar->wrap($c), array_keys($values)));
        $marks = implode(', ', array_fill(0, count($values), '?'));
        $bindings = array_values($values);

        $sets = [];
        foreach ($update as $column => $value) {
            if ($value instanceof Expression) {
                $sets[] = $grammar->wrap($column).' = '.$value->getValue($grammar);
            } else {
                $sets[] = $grammar->wrap($column).' = ?';
                $updateBindings[] = $value;
            }
        }
        $updateBindings ??= [];

        $insert = 'INSERT INTO '.$grammar->wrapTable($table).' ('.$columns.') VALUES ('.$marks.')';

        if ($connection->getDriverName() === 'pgsql') {
            $key = $grammar->wrap($uniqueBy[0]);
            $set = $sets === [] ? $key.' = excluded.'.$key : implode(', ', $sets);
            $row = $connection->selectOne(
                $insert.' ON CONFLICT ('.implode(', ', array_map(fn ($c) => $grammar->wrap($c), $uniqueBy)).') DO UPDATE SET '.$set.' RETURNING id',
                array_merge($bindings, $updateBindings)
            );

            return (int) ($row->id ?? 0);
        }

        $connection->statement(
            $insert.' ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'.($sets === [] ? '' : ', '.implode(', ', $sets)),
            array_merge($bindings, $updateBindings)
        );

        return (int) $connection->getPdo()->lastInsertId();
    }
}
