<?php

namespace Tests\Unit\OrmHarness;

use Illuminate\Support\Facades\DB;
use Tests\Support\OrmHarness\GoldenSql;
use Tests\TestCase;

/**
 * Wave 1, batch 3: background tasks, group boundaries, postcode remap, spam
 * cleanup, and the first two gift-aid consent UPDATE variants.
 */
class Wave1Batch3Test extends TestCase
{
    // SELECT * FROM background_tasks WHERE processed_at IS NULL AND failed_at IS NULL
    //   AND attempts < ? ORDER BY created_at ASC LIMIT ?
    private const SITE_PENDING_TASKS = '30a2059ffce8';

    // SELECT id, nameshort, poly FROM `groups` WHERE type='Freegle' AND publish=1 AND onmap=1
    private const SITE_MAPPED_GROUPS = '44c4c74bbb4e';

    // UPDATE locations SET areaid = ? WHERE id = ?
    private const SITE_REMAP_AREA = '40edf8fe4c91';

    // UPDATE chat_messages SET reviewrejected = 1, reviewrequired = 0 WHERE id = ?
    private const SITE_REJECT_REVIEW = '0089fe542c58';

    // UPDATE users_donations SET giftaidconsent = 1
    //   WHERE userid = ? AND giftaidconsent = 0 AND timestamp >= ?
    private const SITE_CONSENT_PAST4 = '3d1dc34753bd';
    private const SITE_CONSENT_SINCE = '54bddc06f05c';

    public function test_pending_background_tasks(): void
    {
        GoldenSql::assert(self::SITE_PENDING_TASKS, fn () => DB::table('background_tasks')
            ->whereNull('processed_at')
            ->whereNull('failed_at')
            ->where('attempts', '<', 5)
            ->orderBy('created_at')
            ->limit(10));
    }

    public function test_mapped_groups(): void
    {
        GoldenSql::assert(self::SITE_MAPPED_GROUPS, fn () => DB::table('groups')
            ->select('id', 'nameshort', 'poly')
            ->where('type', 'Freegle')
            ->where('publish', 1)
            ->where('onmap', 1));
    }

    public function test_remap_location_area(): void
    {
        GoldenSql::assertUpdate(self::SITE_REMAP_AREA, fn () => [
            DB::table('locations')->where('id', 1),
            ['areaid' => 2],
        ]);
    }

    public function test_reject_chat_review(): void
    {
        GoldenSql::assertUpdate(self::SITE_REJECT_REVIEW, fn () => [
            DB::table('chat_messages')->where('id', 1),
            ['reviewrejected' => 1, 'reviewrequired' => 0],
        ]);
    }

    /**
     * Two of the four gift-aid consent variants. The other two add a
     * date(timestamp) comparison, which needs ->whereDate() and is handled
     * separately rather than lumped in here.
     */
    public function test_giftaid_consent_variants(): void
    {
        $build = fn () => [
            DB::table('users_donations')
                ->where('userid', 1)
                ->where('giftaidconsent', 0)
                ->where('timestamp', '>=', '2020-01-01'),
            ['giftaidconsent' => 1],
        ];

        GoldenSql::assertUpdate(self::SITE_CONSENT_PAST4, $build);
        GoldenSql::assertUpdate(self::SITE_CONSENT_SINCE, $build);
    }
}
