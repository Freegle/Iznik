<?php

namespace Tests\Unit\OrmHarness;

use Illuminate\Support\Facades\DB;
use Tests\Support\OrmHarness\GoldenSql;
use Tests\TestCase;

/**
 * Wave 1, batch 2: Layer 1 parity for converted sites in the test-push command,
 * the purge service, the Doogal postcode importer and the authority stats.
 */
class Wave1Batch2Test extends TestCase
{
    // SELECT * FROM users_push_notifications WHERE userid = ? AND apptype = ?
    // The sixth call site of this statement, and the one ProvenSitesTest used
    // to demonstrate the harness while it was still raw.
    private const SITE_TEST_PUSH_LOOKUP = '6c4256a96688';

    // DELETE FROM logs WHERE id = ?  (two call sites, same statement)
    private const SITE_PURGE_LOG_A = '50062be723d4';
    private const SITE_PURGE_LOG_B = '09aefe3fcfb5';

    // SELECT id FROM locations WHERE name = ? AND type = 'Postcode' LIMIT 1
    private const SITE_PARENT_POSTCODE = '001920a6d03b';

    // UPDATE locations SET postcodeid = ? WHERE id = ?
    private const SITE_SET_POSTCODEID = 'adfd6465efda';

    // SELECT id, userid FROM users_stories
    //   WHERE reviewed = 1 AND public = 1 AND userid IS NOT NULL ORDER BY date DESC
    private const SITE_PUBLIC_STORIES = '9e1132f382e3';

    public function test_push_subscription_lookup(): void
    {
        GoldenSql::assert(self::SITE_TEST_PUSH_LOOKUP, fn () => DB::table('users_push_notifications')
            ->where('userid', 1)
            ->where('apptype', 'User'));
    }

    public function test_purge_log_rows(): void
    {
        $build = fn () => DB::table('logs')->where('id', 1);

        GoldenSql::assertDelete(self::SITE_PURGE_LOG_A, $build);
        GoldenSql::assertDelete(self::SITE_PURGE_LOG_B, $build);
    }

    public function test_parent_postcode_lookup(): void
    {
        GoldenSql::assert(self::SITE_PARENT_POSTCODE, fn () => DB::table('locations')
            ->select('id')
            ->where('name', 'SW1A')
            ->where('type', 'Postcode')
            ->limit(1));
    }

    public function test_set_parent_postcode_id(): void
    {
        GoldenSql::assertUpdate(self::SITE_SET_POSTCODEID, fn () => [
            DB::table('locations')->where('id', 1),
            ['postcodeid' => 2],
        ]);
    }

    public function test_public_stories(): void
    {
        GoldenSql::assert(self::SITE_PUBLIC_STORIES, fn () => DB::table('users_stories')
            ->select('id', 'userid')
            ->where('reviewed', 1)
            ->where('public', 1)
            ->whereNotNull('userid')
            ->orderByDesc('date'));
    }
}
