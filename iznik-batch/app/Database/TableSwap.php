<?php

namespace App\Database;

use Illuminate\Support\Facades\DB;

/**
 * Whole-table replacement for the jobs that rebuild a table beside the live one and swap them: a new table
 * with the structure of an existing one, and an atomic exchange of two names.
 *
 * MySQL has CREATE TABLE ... LIKE and a multi-table RENAME TABLE, which is atomic. PostgreSQL has
 * CREATE TABLE (LIKE ... INCLUDING ALL), and its DDL is transactional, so the renames run in one
 * transaction and the live name is never missing for another session.
 */
final class TableSwap
{
    /** Creates $new with the columns, defaults and indexes of $like. */
    public static function createLike(string $new, string $like): void
    {
        $grammar = DB::getSchemaGrammar();

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE TABLE '.$grammar->wrapTable($new).' (LIKE '.$grammar->wrapTable($like).' INCLUDING ALL)');

            return;
        }

        DB::statement('CREATE TABLE '.$grammar->wrapTable($new).' LIKE '.$grammar->wrapTable($like));
    }

    /** Renames $live to $old and $new to $live, as one atomic step. */
    public static function exchange(string $live, string $new, string $old): void
    {
        $grammar = DB::getSchemaGrammar();

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::transaction(function () use ($live, $new, $old, $grammar) {
                DB::statement('ALTER TABLE '.$grammar->wrapTable($live).' RENAME TO '.$grammar->wrap($old));
                DB::statement('ALTER TABLE '.$grammar->wrapTable($new).' RENAME TO '.$grammar->wrap($live));
            });

            return;
        }

        DB::statement('RENAME TABLE '.$grammar->wrapTable($live).' TO '.$grammar->wrapTable($old).', '.$grammar->wrapTable($new).' TO '.$grammar->wrapTable($live));
    }

}
