<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Switches foreign key enforcement off and on for the current session, on either engine.
 *
 * MySQL has a session variable. PostgreSQL has no equivalent, so the session skips the triggers that
 * enforce foreign keys (session_replication_role), which needs a superuser, as the test database user is.
 */
final class ForeignKeyChecks
{
    public static function disable(): void
    {
        DB::statement(DB::connection()->getDriverName() === 'pgsql'
            ? 'SET session_replication_role = replica'
            : 'SET FOREIGN_KEY_CHECKS=0');
    }

    public static function enable(): void
    {
        DB::statement(DB::connection()->getDriverName() === 'pgsql'
            ? 'SET session_replication_role = DEFAULT'
            : 'SET FOREIGN_KEY_CHECKS=1');
    }
}
