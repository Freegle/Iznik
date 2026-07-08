<?php

namespace App\Database;

use Closure;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Support\Facades\Log;
use PDOException;

/**
 * A ConnectionFactory that falls back to the write host when all read-replica
 * hosts are unreachable.
 *
 * Laravel's built-in ConnectionFactory::createPdoResolverWithHosts already
 * cycles through every host in the read array on connection failure, but once
 * all of them are exhausted it throws — it never tries the write host. This
 * subclass wraps the read PDO resolver in a Closure that catches that final
 * failure and re-resolves against the write-host config instead.
 *
 * Periodic retry is natural and free: Laravel resolves the read PDO lazily (the
 * Closure is invoked the first time a read connection is needed after the pool
 * reconnects). Long-running daemons reconnect on error; artisan runs are always
 * fresh. Each reconnect re-enters the Closure and re-attempts the read hosts
 * first — so the write fallback is only held for as long as replicas stay down.
 *
 * The wrapper is omitted entirely for single-host deployments (dev/CI) where the
 * read and write host arrays are identical, keeping those environments unaffected.
 */
class FailoverConnectionFactory extends ConnectionFactory
{
    /**
     * Create the lazy read-PDO resolver, wrapping it with write-host fallback.
     *
     * @param  array  $config  Full connection config including both 'read' and 'write' keys.
     * @return Closure
     */
    protected function createReadPdo(array $config)
    {
        $readConfig  = $this->getReadConfig($config);
        $writeConfig = $this->getWriteConfig($config);

        // Compare host arrays to detect single-host / dev/CI deployments.
        $readHosts  = array_values((array) ($readConfig['host'] ?? []));
        $writeHosts = array_values((array) ($writeConfig['host'] ?? []));
        sort($readHosts);
        sort($writeHosts);

        if ($readHosts === $writeHosts) {
            // Read and write targets are the same — no failover wrapper needed.
            return parent::createReadPdo($config);
        }

        $readResolver  = parent::createReadPdo($config);
        $writeResolver = $this->createPdoResolverWithHosts($writeConfig);

        // Return a Closure so connection establishment stays lazy (Laravel
        // calls this Closure the first time a read connection is needed, not
        // at boot). The try/catch inside the Closure is what gives us failover:
        // if all read hosts fail, we fall back to the write host and log once.
        return function () use ($readResolver, $writeResolver) {
            try {
                return $readResolver();
            } catch (PDOException $e) {
                Log::warning('read-replica unavailable, falling back to write host', [
                    'error' => $e->getMessage(),
                ]);

                return $writeResolver();
            }
        };
    }
}
