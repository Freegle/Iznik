<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Lets a test run code that swallows a database error inside the test's transaction.
 *
 * MySQL carries on after a failed statement. PostgreSQL does not: every later statement in the
 * transaction fails until it is rolled back to a savepoint. Production code that catches a
 * database error runs outside a transaction, so it is unaffected; a test that provokes the error
 * inside its own transaction needs the savepoint, which this puts around the call and rolls back to
 * afterwards. On MySQL it just runs the callback.
 */
trait RecoversFromDatabaseErrors
{
    protected function recoveringFromDatabaseErrors(callable $work): mixed
    {
        if (DB::connection()->getDriverName() !== 'pgsql' || DB::connection()->transactionLevel() === 0) {
            return $work();
        }

        DB::unprepared('SAVEPOINT recover_from_database_error');

        try {
            return $work();
        } finally {
            DB::unprepared('ROLLBACK TO SAVEPOINT recover_from_database_error');
            DB::unprepared('RELEASE SAVEPOINT recover_from_database_error');
        }
    }
}
