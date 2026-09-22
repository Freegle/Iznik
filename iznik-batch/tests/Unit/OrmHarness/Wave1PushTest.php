<?php

namespace Tests\Unit\OrmHarness;

use Illuminate\Support\Facades\DB;
use Tests\Support\OrmHarness\GoldenSql;
use Tests\TestCase;

/**
 * Wave 1, push notifications: Layer 1 parity for the converted sites in
 * app/Services/PushNotificationService.php.
 *
 * Five of these sites are the SAME statement - "SELECT * FROM
 * users_push_notifications WHERE userid = ? AND apptype = ?" - at five call
 * sites. They each carry their own id and so each needs naming here: the
 * extractor promotes per site, not per statement, so proving one and leaving
 * the other four unnamed would report four still-raw sites whose code has
 * already gone, which is the "it vanished" hole the promotion rule exists to
 * close.
 *
 * The three multi-JOIN COUNT queries left raw in that file (pending/spam
 * counts, volunteering) are a later wave: one of them splices a
 * runtime-built {$placeholders} list into its IN clause, which is a different
 * conversion problem from these.
 */
class Wave1PushTest extends TestCase
{
    // SELECT DISTINCT userid FROM memberships
    //   WHERE groupid = ? AND role IN ('Owner', 'Moderator')
    private const SITE_GROUP_MODS = '21c764087977';

    // SELECT groupid, settings FROM memberships
    //   WHERE userid = ? AND role IN ('Owner','Moderator') AND collection = 'Approved'
    private const SITE_MOD_MEMBERSHIPS = '6bac4dbbcabe';

    // SELECT settings FROM memberships WHERE userid = ? AND groupid = ?
    private const SITE_GROUP_SETTINGS = '358b3716020f';

    // SELECT DISTINCT userid, settings FROM memberships WHERE groupid = ? AND role IN (?, ?)
    private const SITE_ACTIVE_MODS = '2ced8b69d566';

    // The five identical users_push_notifications lookups. Named one per
    // constant and asserted one per call rather than looped over an array:
    // the extractor resolves a literal or a self:: constant, and deliberately
    // NOT an id it would have to compute, because an id the test computes is
    // not the id written at the call site. Looping here found that out - the
    // five reported as still-raw with their code already gone.
    private const SITE_PUSH_LOOKUP_A = '7c9db632531a';
    private const SITE_PUSH_LOOKUP_B = 'bf6d88273e85';
    private const SITE_PUSH_LOOKUP_C = 'eba8a4c424be';
    private const SITE_PUSH_LOOKUP_D = '290c878bdae5';
    private const SITE_PUSH_LOOKUP_E = '060d6cce1405';

    public function test_group_mods(): void
    {
        GoldenSql::assert(self::SITE_GROUP_MODS, fn () => DB::table('memberships')
            ->distinct()
            ->select('userid')
            ->where('groupid', 1)
            ->whereIn('role', ['Owner', 'Moderator']));
    }

    public function test_mod_memberships_with_settings(): void
    {
        GoldenSql::assert(self::SITE_MOD_MEMBERSHIPS, fn () => DB::table('memberships')
            ->select('groupid', 'settings')
            ->where('userid', 1)
            ->whereIn('role', ['Owner', 'Moderator'])
            ->where('collection', 'Approved'));
    }

    public function test_group_settings(): void
    {
        GoldenSql::assert(self::SITE_GROUP_SETTINGS, fn () => DB::table('memberships')
            ->select('settings')
            ->where('userid', 1)
            ->where('groupid', 2));
    }

    public function test_active_group_mods(): void
    {
        GoldenSql::assert(self::SITE_ACTIVE_MODS, fn () => DB::table('memberships')
            ->distinct()
            ->select('userid', 'settings')
            ->where('groupid', 1)
            ->whereIn('role', ['Owner', 'Moderator']));
    }

    public function test_push_notification_lookups(): void
    {
        $build = fn () => DB::table('users_push_notifications')
            ->where('userid', 1)
            ->where('apptype', 'User');

        GoldenSql::assert(self::SITE_PUSH_LOOKUP_A, $build);
        GoldenSql::assert(self::SITE_PUSH_LOOKUP_B, $build);
        GoldenSql::assert(self::SITE_PUSH_LOOKUP_C, $build);
        GoldenSql::assert(self::SITE_PUSH_LOOKUP_D, $build);
        GoldenSql::assert(self::SITE_PUSH_LOOKUP_E, $build);
    }
}
