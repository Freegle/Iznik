<?php

namespace Tests\Support\OrmHarness;

use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\DB;
use PDO;

/**
 * A MySQL connection that never connects, for rendering the MySQL statement of a query-builder call when the
 * suite is running on PostgreSQL. It has MySQL's grammar and its literal quoting; anything that tries to run a
 * statement for real fails, because there is nothing behind it.
 */
final class MySqlRenderingConnection
{
    public const NAME = 'mysql_render';

    /** Makes the connection name resolvable. Safe to call more than once. */
    public static function register(): void
    {
        config(['database.connections.'.self::NAME => ['driver' => 'mysql', 'database' => 'render']]);
        DB::extend(self::NAME, static fn (): Connection => new MySqlConnection(static fn () => new class extends PDO
        {
            public function __construct()
            {
            }

            // mysql_real_escape_string: the characters it escapes, with a backslash.
            public function quote(string $string, int $type = PDO::PARAM_STR): string
            {
                return "'".strtr($string, [
                    "\0" => '\\0', "\n" => '\\n', "\r" => '\\r', '\\' => '\\\\', "'" => "\\'", '"' => '\\"', "\x1a" => '\\Z',
                ])."'";
            }
        }, 'render', '', ['name' => self::NAME]));
    }
}
