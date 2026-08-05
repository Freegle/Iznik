<?php

namespace Tests\Unit\OrmHarness;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\OrmHarness\Manifest;
use Tests\Support\OrmHarness\ResultParity;
use Tests\TestCase;

/**
 * Self-tests for ResultParity, proving it actually FAILS on a genuine
 * result-set divergence and not just passes on a correct one - same
 * discipline as GoldenSqlTest.php and resultparity.go's own
 * resultparity_site_test.go. Needs real seeded rows (unlike GoldenSql,
 * which never executes anything), so these run against the actual test
 * database via the standard createTestUser()/createTestGroup() fixtures.
 */
class ResultParityTest extends TestCase
{
    protected function tearDown(): void
    {
        Manifest::resetCacheForTest();
        parent::tearDown();
    }

    /**
     * Named runAssertion(), not run(): PHPUnit\Framework\TestCase::run() is
     * final, and a private method with the same name still collides with
     * it regardless of visibility - see GoldenSqlTest's identical comment,
     * where this was found the hard way (a FatalError before any test ran).
     *
     * @return array{0:bool,1:string}
     */
    private function runAssertion(string $sql, array $bindings, callable $build): array
    {
        try {
            ResultParity::assert($sql, $bindings, $build);

            return [true, ''];
        } catch (AssertionFailedError $e) {
            return [false, $e->getMessage()];
        }
    }

    public function test_identical_query_passes(): void
    {
        $user = $this->createTestUser(['firstname' => 'Alice']);

        [$passed, $msg] = $this->runAssertion(
            'SELECT id, firstname FROM users WHERE id = ?',
            [$user->id],
            fn () => DB::table('users')->select('id', 'firstname')->where('id', $user->id)
        );

        $this->assertTrue($passed, "expected an identical replacement query to pass: {$msg}");
    }

    public function test_genuinely_different_where_clause_fails(): void
    {
        $userA = $this->createTestUser(['firstname' => 'Alice']);
        $this->createTestUser(['firstname' => 'Bob']);

        // The replacement queries a different row than the original -
        // Layer 1 (string comparison) could not catch this if the SQL text
        // happened to canonicalise the same way; Layer 2 must catch it by
        // actually running both and finding different data.
        [$passed] = $this->runAssertion(
            'SELECT id, firstname FROM users WHERE id = ?',
            [$userA->id],
            fn () => DB::table('users')->select('id', 'firstname')->where('firstname', 'Bob')
        );

        $this->assertFalse($passed, 'expected a replacement selecting a genuinely different row to fail parity');
    }

    public function test_null_is_not_conflated_with_empty_string_or_zero(): void
    {
        // onholidaytill is a nullable date column on users (see
        // 2025_12_10_094529_create_users_table.php); a user who is not on
        // holiday has a real NULL there, not an empty string or an
        // arbitrary date - exactly the class of divergence Layer 2 exists
        // to catch that a naive comparison (or PHP's == instead of ===)
        // would miss.
        $user = $this->createTestUser();
        $this->assertNull($user->fresh()->onholidaytill, 'test fixture assumption: a fresh user is not on holiday');

        [$passed, $msg] = $this->runAssertion(
            'SELECT id, onholidaytill FROM users WHERE id = ?',
            [$user->id],
            // Deliberately wrong: coalesces NULL to a literal date, which
            // must be detected as a real divergence from the true NULL.
            fn () => DB::table('users')
                ->selectRaw('id, COALESCE(onholidaytill, "1970-01-01") as onholidaytill')
                ->where('id', $user->id)
        );

        $this->assertFalse($passed, 'expected a COALESCE-masked NULL to fail parity against the real NULL');
        $this->assertStringContainsString('differs', $msg);
    }

    public function test_column_set_mismatch_fails(): void
    {
        $user = $this->createTestUser();

        [$passed, $msg] = $this->runAssertion(
            'SELECT id, firstname FROM users WHERE id = ?',
            [$user->id],
            fn () => DB::table('users')->select('id', 'lastname')->where('id', $user->id)
        );

        $this->assertFalse($passed, 'expected a replacement selecting a different column set to fail');
        $this->assertStringContainsString('column sets differ', $msg);
    }

    public function test_unordered_comparison_tolerates_different_row_order(): void
    {
        // Neither query has ORDER BY, so the engine (and therefore either
        // side) may legitimately return rows in a different order; the
        // canonical-sort comparison must still treat them as equal.
        $userA = $this->createTestUser(['firstname' => 'Zed']);
        $userB = $this->createTestUser(['firstname' => 'Amy']);
        $ids = [$userA->id, $userB->id];

        [$passed, $msg] = $this->runAssertion(
            'SELECT id FROM users WHERE id IN ('.implode(',', array_fill(0, count($ids), '?')).')',
            $ids,
            // whereIn renders the placeholders in the SAME positional order
            // as $ids, but MySQL is free to return the rows in either order
            // for a query with no ORDER BY - the point under test.
            fn () => DB::table('users')->select('id')->whereIn('id', array_reverse($ids))
        );

        $this->assertTrue($passed, "expected row order to be irrelevant without ORDER BY: {$msg}");
    }

    public function test_order_sensitive_comparison_fails_on_wrong_order(): void
    {
        $userA = $this->createTestUser(['firstname' => 'Amy']);
        $userB = $this->createTestUser(['firstname' => 'Zed']);

        [$passed] = $this->runAssertion(
            'SELECT id FROM users WHERE id IN (?, ?) ORDER BY firstname ASC',
            [$userA->id, $userB->id],
            // Deliberately renders DESCENDING while the original SQL text
            // says ASC - orderByPattern's match on the ORIGINAL sql is what
            // should make this position-sensitive and therefore fail.
            fn () => DB::table('users')->select('id')->whereIn('id', [$userA->id, $userB->id])->orderBy('firstname', 'desc')
        );

        $this->assertFalse($passed, 'expected ORDER BY in the original SQL to make the comparison position-sensitive');
    }

    public function test_assert_for_site_rejects_unknown_site_id(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no manifest entry for site/');

        ResultParity::assertForSite('not-a-real-site-id', 'SELECT 1', [], fn () => DB::table('users'));
    }
}
