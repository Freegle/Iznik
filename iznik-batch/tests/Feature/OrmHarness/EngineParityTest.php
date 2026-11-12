<?php

namespace Tests\Feature\OrmHarness;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\OrmHarness\EngineParity;
use Tests\TestCase;

/**
 * The comparison in EngineParity, with the MySQL connection standing in for both engines, so a
 * result that differs between them has to be made to differ by the test. Nothing here needs
 * PostgreSQL.
 */
class EngineParityTest extends TestCase
{
    private const SITE = 'aaaaaaaaaaaa';

    private function fixture(): array
    {
        return [EngineParity::table('users', [
            ['id' => EngineParity::id(7001)],
            ['id' => EngineParity::id(7002)],
        ])];
    }

    private function ids(): array
    {
        return DB::table('users')
            ->whereBetween('id', [EngineParity::id(7001), EngineParity::id(7002)])
            ->orderBy('id')->pluck('id')->all();
    }

    private function compare(
        callable $run,
        string $golden = 'SELECT id FROM users',
        array $engines = ['mysql', 'mysql'],
        ?bool $ordered = null,
        bool $allowEmpty = false,
        mixed $expect = null,
        ?array $fixture = null,
    ): void {
        EngineParity::compare(self::SITE, $golden, $engines, $fixture ?? $this->fixture(), $run, $ordered, $allowEmpty, $expect, $expect !== null);
    }

    private function assertFailsWith(string $needle, callable $call): void
    {
        try {
            $call();
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString($needle, $e->getMessage());

            return;
        }
        $this->fail("expected the parity check to fail with: {$needle}");
    }

    public function test_engines_that_agree_pass(): void
    {
        $this->compare(fn () => $this->ids(), expect: [EngineParity::id(7001), EngineParity::id(7002)]);
    }

    public function test_different_rows_fail(): void
    {
        $calls = 0;
        $this->assertFailsWith('gives different rows on mysql and mysql', function () use (&$calls) {
            $this->compare(function () use (&$calls) {
                return ++$calls === 2 ? [EngineParity::id(7009)] : $this->ids();
            });
        });
    }

    public function test_order_is_checked_only_where_the_golden_orders(): void
    {
        $reversed = function () {
            $calls = 0;

            return function () use (&$calls) {
                $ids = $this->ids();

                return ++$calls === 2 ? array_reverse($ids) : $ids;
            };
        };

        $this->assertFailsWith('compared in order', fn () => $this->compare($reversed(), 'SELECT id FROM users ORDER BY id'));
        $this->compare($reversed(), 'SELECT id FROM users');
        $this->assertFailsWith('compared in order', fn () => $this->compare($reversed(), 'SELECT id FROM users', ordered: true));
        $this->compare($reversed(), 'SELECT id FROM users ORDER BY id', ordered: false);
    }

    public function test_an_empty_result_fails_unless_allowed(): void
    {
        $this->assertFailsWith('agreeing on nothing', fn () => $this->compare(fn () => []));
        $this->compare(fn () => [], allowEmpty: true);
    }

    public function test_expect_catches_two_engines_drifting_together(): void
    {
        $this->assertFailsWith('not what the fixture should give', fn () => $this->compare(fn () => $this->ids(), expect: [1]));
    }

    public function test_one_engine_still_checks_the_site_gives_the_expected_rows(): void
    {
        $this->compare(fn () => $this->ids(), engines: ['mysql'], expect: [EngineParity::id(7001), EngineParity::id(7002)]);
        $this->assertFailsWith('not what the fixture should give', fn () => $this->compare(fn () => $this->ids(), engines: ['mysql'], expect: [1]));
    }

    public function test_no_engine_is_not_a_comparison(): void
    {
        $this->assertFailsWith('no engine to run on', fn () => $this->compare(fn () => $this->ids(), engines: []));
    }

    public function test_a_fixture_the_engine_refuses_names_the_table_and_row(): void
    {
        $this->assertFailsWith('fixture table users row 0', function () {
            $this->compare(fn () => 1, fixture: [EngineParity::table('users', [['id' => EngineParity::id(7001), 'no_such_column' => 1]])]);
        });
    }

    public function test_a_statement_the_engine_refuses_is_reported_with_the_engine(): void
    {
        $this->assertFailsWith('on mysql', fn () => $this->compare(fn () => DB::table('no_such_table')->count()));
    }

    public function test_the_fixture_is_rolled_back_and_the_default_connection_restored(): void
    {
        $before = DB::getDefaultConnection();

        $this->compare(fn () => $this->ids());

        $this->assertFalse(DB::table('users')->where('id', EngineParity::id(7001))->exists());
        $this->assertSame($before, DB::getDefaultConnection());
    }
}
