<?php

namespace App\Database;

use Illuminate\Database\Connectors\PostgresConnector;
use InvalidArgumentException;

/**
 * Opens a PostgreSQL connection whose statements give up waiting for a lock.
 *
 * InnoDB stops a lock wait after 50 seconds (innodb_lock_wait_timeout) and the statement fails with error
 * 1205. PostgreSQL's default is to wait for ever, so one stuck writer would leave every job behind it
 * hanging with no error. With lock_timeout set the wait ends with SQLSTATE 55P03, which TransactionPolicy
 * and JsonTextPostgresConnection treat as they treat 1205.
 *
 * The time is the connection's 'lock_timeout' setting in PostgreSQL's own form (50s, 200ms, 2min). A value
 * of 0 means no limit. With the setting absent or empty nothing is sent and the server's setting stands.
 */
class LockTimeoutPostgresConnector extends PostgresConnector
{
    public function connect(array $config)
    {
        $connection = parent::connect($config);

        $timeout = self::lockTimeout($config);
        if ($timeout !== null) {
            // SET takes no bound parameter. The value has been checked to be digits and a unit.
            $connection->exec("SET lock_timeout = '{$timeout}'");
        }

        return $connection;
    }

    /** The checked lock timeout for a connection's settings, or null when none is to be sent. */
    public static function lockTimeout(array $config): ?string
    {
        $value = trim((string) ($config['lock_timeout'] ?? ''));
        if ($value === '') {
            return null;
        }
        if (! preg_match('/^\d+(us|ms|s|min|h)?$/', $value)) {
            throw new InvalidArgumentException("PGSQL_LOCK_TIMEOUT must be a number with an optional unit (50s, 200ms), not '{$value}'.");
        }

        return $value;
    }
}
