<?php

namespace Tests\Unit\Database;

use App\Database\JsonTextPostgresConnection;
use App\Database\JsonTextPostgresGrammar;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PostgresDateFormatTest extends TestCase
{
    public function test_a_bound_date_keeps_its_microseconds_on_postgresql(): void
    {
        $grammar = new JsonTextPostgresGrammar(new JsonTextPostgresConnection(fn () => null));
        $connection = new JsonTextPostgresConnection(fn () => null);
        $connection->setQueryGrammar($grammar);

        $bound = $connection->prepareBindings([Carbon::parse('2026-11-12 10:11:12.345678')]);

        $this->assertSame('2026-11-12 10:11:12.345678', $bound[0]);
    }

    public function test_a_date_bound_from_php_is_not_earlier_than_a_stored_now_in_the_same_instant(): void
    {
        if (! $this->postgresOnly('MySQL keeps whole seconds, so a bound time there has no microseconds to keep')) {
            return;
        }

        $this->assertTrue((bool) DB::selectOne('SELECT NOW() <= ? AS ok', [now()->addMilliseconds(5)])->ok);
    }
}
