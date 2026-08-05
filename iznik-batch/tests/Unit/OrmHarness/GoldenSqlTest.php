<?php

namespace Tests\Unit\OrmHarness;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\OrmHarness\GoldenSql;
use Tests\Support\OrmHarness\Manifest;
use Tests\TestCase;

/**
 * Self-tests for GoldenSql, proving it actually FAILS on a genuine
 * divergence and not just passes on a correct one - the same discipline
 * iznik-server-go/ormharness/golden_test.go applies (its own header note:
 * "expected failure" cases exist because a harness that only demonstrates
 * passing has not demonstrated it catches anything). Scenarios mirror that
 * file's synthetic-manifest tests one for one, via Manifest::setSitesForTest.
 *
 * Extends the app's Tests\TestCase (not a bare PHPUnit\Framework\TestCase,
 * unlike CanonicalTest): GoldenSql::assert() calls ->toSql()/->getGrammar()
 * on a real query builder, which needs a bound Connection even though no
 * query is ever executed - DatabaseTransactions still applies here, cheap
 * insurance against a test leaking connection state, even though none of
 * these tests write anything.
 */
class GoldenSqlTest extends TestCase
{
    protected function tearDown(): void
    {
        Manifest::resetCacheForTest();
        parent::tearDown();
    }

    /**
     * Named runAssertion(), not run(): PHPUnit\Framework\TestCase::run() is
     * final, and a private method with the same name still collides with
     * it - PHP's "cannot override a final method" check is name-based
     * across the whole class, not scoped by visibility. Found the hard way:
     * this crashed PHPUnit's own bootstrap with a FatalError before a
     * single test could run.
     *
     * @return array{0:bool,1:string} [passed, failure message if any]
     */
    private function runAssertion(string $siteId, callable $build): array
    {
        try {
            GoldenSql::assert($siteId, $build);

            return [true, ''];
        } catch (AssertionFailedError $e) {
            return [false, $e->getMessage()];
        }
    }

    /**
     * @return array{0:bool,1:string}
     */
    private function runUpdate(string $siteId, callable $build): array
    {
        try {
            GoldenSql::assertUpdate($siteId, $build);

            return [true, ''];
        } catch (AssertionFailedError $e) {
            return [false, $e->getMessage()];
        }
    }

    public function test_cosmetically_different_render_passes(): void
    {
        Manifest::setSitesForTest([
            'site1' => ['goldenSql' => 'SELECT id FROM users WHERE id = ?'],
        ]);

        // Deliberately different cosmetically (backticks, keyword case) to
        // prove the pass goes through Canonical, not a literal comparison.
        [$passed, $msg] = $this->runAssertion('site1', fn () => DB::table('users')->select('id')->where('id', 5));

        $this->assertTrue($passed, "expected a canonically equivalent render to pass: {$msg}");
    }

    public function test_genuine_mismatch_fails(): void
    {
        Manifest::setSitesForTest([
            'site1' => ['goldenSql' => 'SELECT id FROM users WHERE id = ?'],
        ]);

        [$passed] = $this->runAssertion('site1', fn () => DB::table('groups')->select('id')->where('id', 5));

        $this->assertFalse($passed, 'expected a genuinely different rendered statement (different table) to fail parity');
    }

    public function test_no_golden_sql_fails(): void
    {
        Manifest::setSitesForTest([
            'site1' => ['goldenSql' => ''], // present in the manifest, but with no goldenSql recorded
        ]);

        [$passed, $msg] = $this->runAssertion('site1', fn () => DB::table('users')->select('id'));

        $this->assertFalse($passed, 'expected a site with no goldenSql to fail rather than pass vacuously');
        $this->assertStringContainsString('has no goldenSql', $msg);
    }

    public function test_unknown_site_fails(): void
    {
        // Not routed through runAssertion(): Manifest::site() rejects an
        // unknown id via a plain \RuntimeException (Manifest.php), not
        // Assert::fail() - so it is not an AssertionFailedError and would
        // never be caught by runAssertion()'s catch clause. That mismatch
        // let this exact exception escape uncaught on the first real run,
        // which PHPUnit correctly recorded as an ERROR rather than a pass -
        // the harness was behaving exactly as it should (an unknown site id
        // MUST be rejected hard; Gate 2's whole meaning depends on that
        // strictness, so this must never be loosened into a caught/ignored
        // case). The fix is entirely in how the test expresses "this should
        // throw", not in the harness.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no manifest entry for site/');

        GoldenSql::assert('not-a-real-site-id', fn () => DB::table('users'));
    }

    public function test_approved_diff_passes(): void
    {
        // Modelled on the plan's own example of a legitimate divergence: the
        // ORM selecting an explicit column list where hand-written SQL used *.
        Manifest::setSitesForTest([
            'site1' => [
                'goldenSql' => 'SELECT * FROM users WHERE id = ?',
                'approvedDiff' => 'SELECT id, email FROM users WHERE id = ?',
            ],
        ]);

        [$passed, $msg] = $this->runAssertion('site1', fn () => DB::table('users')->select('id', 'email')->where('id', 5));

        $this->assertTrue($passed, "expected a render matching the recorded approvedDiff to pass: {$msg}");
    }

    public function test_approved_diff_does_not_cover_other_divergences(): void
    {
        // The escape hatch is for exactly one reviewed divergence, not
        // "anything goes once a site has an approvedDiff": a third,
        // unreviewed rendering must still fail.
        Manifest::setSitesForTest([
            'site1' => [
                'goldenSql' => 'SELECT * FROM users WHERE id = ?',
                'approvedDiff' => 'SELECT id, email FROM users WHERE id = ?',
            ],
        ]);

        [$passed] = $this->runAssertion('site1', fn () => DB::table('users')->select('id', 'firstname')->where('id', 5));

        $this->assertFalse($passed, 'expected a rendering matching neither goldenSql nor approvedDiff to fail');
    }

    public function test_placeholder_count_mismatch_between_rendered_and_bindings_fails(): void
    {
        // Deliberately provoke the guard that exists for the real bug
        // golden.go documents: extra Where() calls bind more arguments than
        // the query has placeholders for.
        Manifest::setSitesForTest([
            'site1' => ['goldenSql' => 'SELECT id FROM users WHERE id = ? AND firstname = ?'],
        ]);

        [$passed, $msg] = $this->runAssertion('site1', function () {
            // whereRaw with a literal condition that also happens to bind an
            // extra, unused value - the shape golden.go's own doc comment
            // describes ("a value passed to Select/Joins/Where that the
            // expression has no '?' for, or one bound twice").
            $q = DB::table('users')->select('id')->where('id', 5);
            $q->addBinding(['extra'], 'where');

            return $q;
        });

        $this->assertFalse($passed, 'expected a placeholder/binding count mismatch to fail before any text comparison');
        $this->assertStringContainsString('placeholder(s)', $msg);
    }

    // --- assertUpdate(): same "cosmetic pass / genuine fail" pair, proving
    // the compileUpdate()-based path is not just structurally different
    // from assert() but actually exercises real Laravel Grammar code -----

    public function test_assert_update_cosmetically_different_render_passes(): void
    {
        Manifest::setSitesForTest([
            'site1' => ['goldenSql' => 'UPDATE users SET firstname = ? WHERE id = ?'],
        ]);

        [$passed, $msg] = $this->runUpdate('site1', fn () => [
            // The cosmetic difference under test is KEYWORD case: this
            // golden is hand-written with UPDATE/SET/WHERE uppercase, and
            // Laravel's grammar always renders lowercase - that must be
            // normalised away. Table/column identifier case is NOT
            // cosmetic to Canonical (see CanonicalTest's corpus case for
            // why: it is exactly the kind of typo Layer 1 exists to catch),
            // so both sides use "users"/"id"/"firstname" verbatim here -
            // varying those would test a different, unrelated guard.
            DB::table('users')->where('id', 5),
            ['firstname' => 'Alice'],
        ]);

        // Binding order for an UPDATE is SET-values-then-WHERE (Laravel's
        // prepareBindingsForUpdate), matching the golden's own placeholder
        // order - proves that ordering, not just that some canonical form
        // happens to match by coincidence.
        $this->assertTrue($passed, "expected a canonically equivalent UPDATE render to pass: {$msg}");
    }

    public function test_assert_update_wrong_set_column_fails(): void
    {
        Manifest::setSitesForTest([
            'site1' => ['goldenSql' => 'UPDATE users SET firstname = ? WHERE id = ?'],
        ]);

        [$passed] = $this->runUpdate('site1', fn () => [
            DB::table('users')->where('id', 5),
            ['lastname' => 'Alice'], // wrong column: lastname, not firstname
        ]);

        $this->assertFalse($passed, 'expected an UPDATE setting a different column to fail parity');
    }

    /**
     * Team lead's explicit ask after reviewing the compileUpdate() design:
     * prove the SET-clause column ORDER is genuinely reconciled, not
     * loosened away to make comparisons pass. Unlike GORM (which sorts a
     * map-valued Updates() alphabetically, forcing canonical.go's
     * normaliseColumnOrder to exist), Laravel's compileUpdateColumns()
     * follows the PHP array's own insertion order with no reordering step -
     * so this port correctly has NO SET-order normaliser at all (see
     * Canonical.php's header). That is only safe if a genuine order
     * mismatch still fails, which is exactly what this proves: two SET
     * columns supplied in the wrong order against a golden that names them
     * in a specific order must NOT canonicalise as equal.
     */
    public function test_assert_update_set_clause_column_order_is_significant(): void
    {
        Manifest::setSitesForTest([
            'site1' => ['goldenSql' => 'UPDATE users SET firstname = ?, lastname = ? WHERE id = ?'],
        ]);

        [$passed] = $this->runUpdate('site1', fn () => [
            DB::table('users')->where('id', 5),
            // Same two columns and values as the golden, but written in the
            // REVERSE order - a real bug a Wave 2 conversion could make
            // (e.g. copying an array literal from a different site).
            ['lastname' => 'Smith', 'firstname' => 'Alice'],
        ]);

        $this->assertFalse($passed, 'expected a SET clause with columns in a different order to fail, not be silently reordered into matching');
    }
}
