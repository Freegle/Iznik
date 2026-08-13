<?php

namespace Tests\Unit\OrmHarness;

use Illuminate\Support\Facades\DB;
use Tests\Support\OrmHarness\GoldenSql;
use Tests\TestCase;

/**
 * Wave 1, mixed: Layer 1 parity for converted sites in the reach-bounds
 * migration command, the chat spam service, and the profile-image cleanup.
 */
class Wave1MiscTest extends TestCase
{
    // SELECT COUNT(*) AS n FROM rippling_reach
    private const SITE_REACH_COUNT = '3a7b84328162';

    // SELECT COALESCE(MAX(msgid), 0) AS m FROM rippling_reach_shadow
    private const SITE_SHADOW_CURSOR = '83aec2aa9cd7';

    // DELETE FROM rippling_reach_shadow WHERE msgid = ?
    private const SITE_SHADOW_DELETE = 'faf6aadc5ed8';

    // UPDATE chat_rooms SET flaggedspam = 1 WHERE id = ?
    private const SITE_FLAG_SPAM = '167eea65c5ac';

    // UPDATE chat_messages SET reviewrequired = 0, ... WHERE userid = ? AND ...
    private const SITE_CLEAR_REVIEW = '72404d41f2b6';

    // DELETE FROM users_images WHERE userid = ? AND id < ?
    private const SITE_PRUNE_IMAGES = '9ec7961822f5';

    public function test_reach_row_count(): void
    {
        GoldenSql::assert(self::SITE_REACH_COUNT, function () {
            $q = DB::table('rippling_reach');
            // What ->count() assembles, without executing it.
            $q->aggregate = ['function' => 'count', 'columns' => ['*']];

            return $q;
        });
    }

    /**
     * COALESCE(MAX(msgid), 0) becomes ->max('msgid') with a PHP `?? 0`.
     * ->max() returns null on an empty table, which is the only case the
     * COALESCE was guarding, so the guard moves from SQL to PHP and the value
     * the caller sees is unchanged. Keeping COALESCE in SQL would have needed
     * DB::raw - itself a raw site here.
     */
    public function test_shadow_cursor(): void
    {
        GoldenSql::assert(self::SITE_SHADOW_CURSOR, function () {
            $q = DB::table('rippling_reach_shadow');
            $q->aggregate = ['function' => 'max', 'columns' => ['msgid']];

            return $q;
        });
    }

    public function test_shadow_delete(): void
    {
        GoldenSql::assertDelete(self::SITE_SHADOW_DELETE, fn () => DB::table('rippling_reach_shadow')
            ->where('msgid', 1));
    }

    public function test_flag_chat_room_spam(): void
    {
        GoldenSql::assertUpdate(self::SITE_FLAG_SPAM, fn () => [
            DB::table('chat_rooms')->where('id', 1),
            ['flaggedspam' => 1],
        ]);
    }

    public function test_clear_review_flags(): void
    {
        GoldenSql::assertUpdate(self::SITE_CLEAR_REVIEW, fn () => [
            DB::table('chat_messages')
                ->where('userid', 1)
                ->where('reviewrequired', 1)
                ->whereNull('reviewedby'),
            [
                'reviewrequired' => 0,
                'processingrequired' => 0,
                'processingsuccessful' => 0,
                'reviewrejected' => 1,
                'reviewedby' => null,
            ],
        ]);
    }

    public function test_prune_duplicate_profile_images(): void
    {
        GoldenSql::assertDelete(self::SITE_PRUNE_IMAGES, fn () => DB::table('users_images')
            ->where('userid', 1)
            ->where('id', '<', 2));
    }
}
