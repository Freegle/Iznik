<?php

namespace Tests\Unit\Database;

use App\Database\PostgresTextJsonGrammar;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A JSON path on a text column reads on PostgreSQL as it does on MySQL: the column is cast to jsonb first.
 */
class PostgresTextJsonGrammarTest extends TestCase
{
    private function sql(Builder $query): string
    {
        $grammar = new PostgresTextJsonGrammar(DB::connection());

        return $grammar->compileSelect($query);
    }

    private function builder(): Builder
    {
        return new Builder(DB::connection(), new PostgresTextJsonGrammar(DB::connection()), DB::connection()->getPostProcessor());
    }

    public function test_a_path_on_a_column_is_read_through_a_jsonb_cast(): void
    {
        $sql = $this->sql($this->builder()->from('users')->where('settings->mylocation->lat', '>', 5));

        $this->assertStringContainsString('("settings")::jsonb->\'mylocation\'->>\'lat\'', $sql);
    }

    public function test_a_single_step_path_is_cast_too(): void
    {
        $sql = $this->sql($this->builder()->from('users')->whereNull('settings->nodonationasksuntil'));

        $this->assertStringContainsString('("settings")::jsonb->>\'nodonationasksuntil\'', $sql);
    }

    public function test_a_path_on_a_text_column_runs_on_postgresql(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The suite is not running on PostgreSQL.');
        }

        $user = $this->createTestUser();
        DB::table('users')->where('id', $user->id)->update(['settings' => json_encode(['mylocation' => ['lat' => 52.5], 'on' => true])]);

        $this->assertSame(1, DB::table('users')->where('id', $user->id)->where('settings->on', true)->count());
        $this->assertSame('52.5', DB::table('users')->where('id', $user->id)->value('settings->mylocation->lat'));
    }
}
