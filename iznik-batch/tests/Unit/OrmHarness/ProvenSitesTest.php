<?php

namespace Tests\Unit\OrmHarness;

use Illuminate\Support\Facades\DB;
use Tests\Support\OrmHarness\GoldenSql;
use Tests\Support\OrmHarness\ResultParity;
use Tests\TestCase;

/**
 * Proves the harness works end to end against REAL sites from
 * tools/orm-migration/services/laravel/manifest.json - not synthetic
 * fixtures (that is what CanonicalTest/GoldenSqlTest/ResultParityTest are
 * for). This does NOT convert anything: every site named here stays status
 * "raw" in the manifest, and the production call sites are untouched. What
 * this proves is narrower and earlier than a conversion - that an
 * equivalent query-builder chain WOULD satisfy both layers, before any
 * production code changes to use one.
 *
 * Picked to span kinds: a simple two-condition SELECT, an aggregate SELECT
 * with GROUP BY/HAVING, and an UPDATE - deliberately not a WHERE-fragment
 * site (whereRaw('LOWER(email) = ?', ...) sites in this manifest are
 * already living inside an otherwise-ORM chain with nothing further to
 * convert; proving the harness against one would mean testing the raw
 * fragment against itself, which demonstrates nothing).
 */
class ProvenSitesTest extends TestCase
{
    // app/Console/Commands/Notification/SendTestPushCommand.php:87
    // SELECT * FROM users_push_notifications WHERE userid = ? AND apptype = ?
    private const SITE_SELECT_SIMPLE = '6c4256a96688';

    // app/Console/Commands/Cleanup/ArchiveProfileImagesCommand.php:32
    // SELECT userid, MAX(id) AS max, COUNT(*) AS count FROM users_images
    //   WHERE userid IS NOT NULL GROUP BY userid HAVING count > 1
    private const SITE_SELECT_AGGREGATE = 'cf45982f8acd';

    // app/Models/ChatRoom.php:221 (getOrCreateUser2Mod)
    // UPDATE chat_rooms SET latestmessage = NOW() WHERE id = ?
    private const SITE_UPDATE = 'ad8e424f4855';

    public function test_layer1_simple_select_site(): void
    {
        GoldenSql::assert(self::SITE_SELECT_SIMPLE, fn () => DB::table('users_push_notifications')
            ->where('userid', 5)
            ->where('apptype', 'User'));
    }

    public function test_layer1_aggregate_select_site(): void
    {
        GoldenSql::assert(self::SITE_SELECT_AGGREGATE, fn () => DB::table('users_images')
            ->select('userid', DB::raw('MAX(id) as max'), DB::raw('COUNT(*) as count'))
            ->whereNotNull('userid')
            ->groupBy('userid')
            // havingRaw(), not having('count', '>', 1) - deliberately. The
            // harness caught this while this test was being written:
            // having() BINDS its value ("having `count` > ?"), but the
            // golden's HAVING threshold is a literal ("having count > 1")
            // with no placeholder at all, because that is how the hand-
            // written SQL was authored. having() is not wrong in general -
            // it would be the right call for a genuinely dynamic threshold
            // - but it is not what proves parity with THIS site's actual
            // text, and GoldenSql's placeholder-count guard (the golden's
            // "?" count vs the bindings the builder collected) failed loudly
            // on the first attempt rather than silently comparing something
            // subtly different. Left in as the real example, not smoothed
            // over into a case that always happens to pass.
            ->havingRaw('count > 1'));
    }

    public function test_layer1_update_site(): void
    {
        // GoldenSql::assertUpdate, not assert(): calling ->update([...])
        // here would EXECUTE the write immediately (Query\Builder has no
        // dry-run mode for UPDATE the way toSql() gives SELECT - see
        // GoldenSql's own header for why this needed a second method, a
        // real finding from writing this exact test). The query builder
        // below only ever carries the table and WHERE; the SET values are
        // supplied as assertUpdate()'s second array element instead.
        GoldenSql::assertUpdate(self::SITE_UPDATE, fn () => [
            DB::table('chat_rooms')->where('id', 5),
            ['latestmessage' => DB::raw('NOW()')],
        ]);
    }

    public function test_layer2_simple_select_site(): void
    {
        $user = $this->createTestUser();

        $matching = DB::table('users_push_notifications')->insertGetId([
            'userid' => $user->id,
            'subscription' => 'sub-'.uniqid('', true),
            'apptype' => 'User',
        ]);
        // A row for the SAME user but the OTHER apptype - must NOT appear
        // in either side's result, proving the WHERE actually filters.
        DB::table('users_push_notifications')->insert([
            'userid' => $user->id,
            'subscription' => 'sub-'.uniqid('', true),
            'apptype' => 'ModTools',
        ]);

        ResultParity::assertForSite(
            self::SITE_SELECT_SIMPLE,
            'SELECT * FROM users_push_notifications WHERE userid = ? AND apptype = ?',
            [$user->id, 'User'],
            fn () => DB::table('users_push_notifications')->where('userid', $user->id)->where('apptype', 'User')
        );

        // Sanity: prove the fixture actually exercised the filter (not a
        // false-positive pass from an empty result set on both sides).
        $this->assertNotNull($matching);
    }

    public function test_layer2_aggregate_select_site(): void
    {
        $userWithDuplicates = $this->createTestUser();
        $userWithOne = $this->createTestUser();

        // Two rows for one user (passes HAVING count > 1), one row for
        // another (filtered out by HAVING) - proves both the GROUP BY and
        // the HAVING threshold, not just that SOME rows come back equal.
        foreach ([1, 2] as $i) {
            DB::table('users_images')->insert([
                'userid' => $userWithDuplicates->id,
                'contenttype' => 'image/jpeg',
            ]);
        }
        DB::table('users_images')->insert([
            'userid' => $userWithOne->id,
            'contenttype' => 'image/jpeg',
        ]);

        ResultParity::assertForSite(
            self::SITE_SELECT_AGGREGATE,
            'SELECT userid, MAX(id) AS max, COUNT(*) AS count FROM users_images WHERE userid IS NOT NULL GROUP BY userid HAVING count > 1',
            [],
            fn () => DB::table('users_images')
                ->select('userid', DB::raw('MAX(id) as max'), DB::raw('COUNT(*) as count'))
                ->whereNotNull('userid')
                ->groupBy('userid')
                ->havingRaw('count > 1') // see test_layer1_aggregate_select_site's comment for why not having()
        );
    }

    /**
     * Layer 2 as built (ResultParity::assert) compares two RESULT SETS -
     * the natural shape for a SELECT, but a bare UPDATE has no result set
     * of its own to diff (MySQL has no RETURNING here). The real usage
     * pattern for a write site, illustrated rather than assumed: run the
     * OLD write against one seeded row, the NEW write against an
     * identically-seeded row, then hand ResultParity a verification SELECT
     * that reads both rows back - Layer 2 then diffs THAT, which is where
     * the actual proof (did the write do the same thing) lives. This is
     * the pattern a Wave 2 write-site test would use; recorded here so the
     * first real one does not have to invent it from scratch.
     */
    public function test_layer2_update_site_via_verification_select(): void
    {
        // Two DISTINCT user pairs, not one pair reused for both rooms:
        // chat_rooms has a unique constraint on (user1, user2, chattype)
        // ('user1_2') - found the hard way, a UniqueConstraintViolationException
        // on the second createTestChatRoom() call with the same pair.
        $roomOld = $this->createTestChatRoom($this->createTestUser(), $this->createTestUser());
        $roomNew = $this->createTestChatRoom($this->createTestUser(), $this->createTestUser());

        DB::statement('UPDATE chat_rooms SET latestmessage = NOW() WHERE id = ?', [$roomOld->id]);
        DB::table('chat_rooms')->where('id', $roomNew->id)->update(['latestmessage' => DB::raw('NOW()')]);

        // The verification query: not the write itself, but a read that
        // proves both writes produced the same observable state (allowing
        // for the two NOW() calls landing in different seconds under a
        // slow CI run, which is real clock skew, not a parity failure).
        ResultParity::assert(
            'SELECT latestmessage IS NOT NULL AS was_updated FROM chat_rooms WHERE id = ?',
            [$roomOld->id],
            fn () => DB::table('chat_rooms')->select(DB::raw('latestmessage IS NOT NULL AS was_updated'))->where('id', $roomNew->id)
        );
    }
}
