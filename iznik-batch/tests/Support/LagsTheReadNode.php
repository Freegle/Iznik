<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Make the read node look like a node that is behind the write node, for example a node that has not applied
 * the SQL that made a table yet (.claude/rules/laravel-batch-traps.md, "A schema check on the read connection
 * can miss a table you just created").
 *
 * withLaggingReadNode() adds a connection whose writes go to the test database and whose reads go to a second,
 * real database that holds only the tables it is given, empty. The test's own connection is in a transaction,
 * and a connection in a transaction reads from the write node, so the lag cannot be seen on it. This one is
 * not in a transaction, which is how a queue worker or a command meets it.
 *
 * Whatever the test writes through the connection is committed, so the test cleans up what it wrote.
 */
trait LagsTheReadNode
{
    private const LAG_DATABASE = 'iznik_batch_test_lag';

    private const LAG_CONNECTION = 'uxlag';

    /**
     * Run $work($connectionName) while the connection exists, and take it away again whatever happens. The
     * default connection is put back if the work moved it.
     *
     * @param  string[]  $tables  the tables the read node has, made like the test database's and empty
     */
    protected function withLaggingReadNode(array $tables, callable $work): mixed
    {
        $c = config('database.connections.mysql');
        $default = DB::getDefaultConnection();
        $side = $this->lagServerConnection($c);
        $side->exec('DROP DATABASE IF EXISTS `'.self::LAG_DATABASE.'`');
        $side->exec('CREATE DATABASE `'.self::LAG_DATABASE.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        foreach ($tables as $table) {
            $side->exec('CREATE TABLE `'.self::LAG_DATABASE.'`.`'.$table.'` LIKE `'.$c['database'].'`.`'.$table.'`');
        }

        $lag = $c;
        $lag['read'] = ['host' => $c['read']['host'], 'database' => self::LAG_DATABASE];
        config(['database.connections.'.self::LAG_CONNECTION => $lag]);
        DB::purge(self::LAG_CONNECTION);

        try {
            return $work(self::LAG_CONNECTION);
        } finally {
            DB::setDefaultConnection($default);
            DB::purge(self::LAG_CONNECTION);
            config(['database.connections.'.self::LAG_CONNECTION => null]);
            $side->exec('DROP DATABASE IF EXISTS `'.self::LAG_DATABASE.'`');
        }
    }

    /** A connection to the server with no database chosen, so a database can be made and dropped. */
    private function lagServerConnection(array $c): \PDO
    {
        $host = $c['write']['host'] ?? [$c['host'] ?? '127.0.0.1'];
        $host = is_array($host) ? reset($host) : $host;

        $pdo = new \PDO(
            "mysql:host={$host};port={$c['port']};charset=utf8mb4",
            $c['username'],
            $c['password'],
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );
        $pdo->exec('SET SESSION lock_wait_timeout = 5');

        return $pdo;
    }
}
