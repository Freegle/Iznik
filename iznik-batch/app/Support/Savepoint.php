<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Keeps a database error that the caller catches from spoiling an outer transaction.
 *
 * MySQL carries on after a failed statement. PostgreSQL aborts the whole transaction, so every later
 * statement fails until it is rolled back. Code that catches a database error (a duplicate key, a missing
 * table) and carries on is therefore safe on PostgreSQL only when the failing statement ran under a
 * savepoint.
 *
 * Outside a transaction (how the batch jobs run, one statement at a time) this just calls the callback: no
 * transaction is opened, so the policy in TransactionPolicy is untouched. Inside one (a test, or a caller that
 * chose a transaction) the callback runs as a nested transaction, which Laravel makes a savepoint.
 */
final class Savepoint
{
    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public static function around(callable $work): mixed
    {
        return DB::transactionLevel() > 0 ? DB::transaction($work) : $work();
    }
}
