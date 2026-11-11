<?php

namespace Tests\Feature\Database;

use Illuminate\Cache\DatabaseLock;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\LagsTheReadNode;
use Tests\TestCase;

/**
 * DatabaseLock::release() reads the owner back before it deletes the row. On a connection with a read
 * host, that read can reach a node that has not applied the row yet, so release() does nothing and the
 * lock stays held until it expires. On live (20 October 2026) the UX experiments tick took its lock,
 * left it held, and failed on its next lock in the same run, hour after hour. Cache locks therefore use
 * 'mysql_write', which has the write host only.
 */
class CacheLockWriteNodeTest extends TestCase
{
    use LagsTheReadNode;

    public function test_cache_locks_use_a_connection_with_no_read_host(): void
    {
        $this->assertSame('mysql_write', config('cache.stores.database.lock_connection'));
        $this->assertArrayNotHasKey('read', config('database.connections.mysql_write'));
        $this->assertSame(
            config('database.connections.mysql.write.host'),
            config('database.connections.mysql_write.host')
        );
    }

    public function test_a_lock_is_released_on_the_write_node_when_the_read_node_is_behind(): void
    {
        $made = $this->ensureLockTable();
        try {
            $this->withLaggingReadNode(['cache_locks'], function (string $lag) {
                // Through a connection whose read node is behind, release() cannot see its own row.
                $name = 'lock-lag-'.random_int(100000, 999999);
                $lagged = new DatabaseLock(DB::connection($lag), 'cache_locks', $name, 60);
                $this->assertTrue($lagged->get());
                $this->assertFalse($lagged->release());
                $this->assertSame(1, DB::connection('mysql_write')->table('cache_locks')->where('key', $name)->count());
                DB::connection('mysql_write')->table('cache_locks')->where('key', $name)->delete();

                // Through mysql_write it is released and gone.
                $name = 'lock-write-'.random_int(100000, 999999);
                $lock = new DatabaseLock(DB::connection('mysql_write'), 'cache_locks', $name, 60);
                $this->assertTrue($lock->get());
                $this->assertTrue($lock->release());
                $this->assertSame(0, DB::connection('mysql_write')->table('cache_locks')->where('key', $name)->count());
            });
        } finally {
            if ($made) {
                Schema::connection('mysql_write')->dropIfExists('cache_locks');
            }
        }
    }

    /** The live table is Laravel's own; the test database may not have it. Returns whether it was made here. */
    private function ensureLockTable(): bool
    {
        if (Schema::connection('mysql_write')->hasTable('cache_locks')) {
            return false;
        }
        Schema::connection('mysql_write')->create('cache_locks', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->string('owner');
            $t->integer('expiration')->index();
        });

        return true;
    }
}
