<?php

namespace Tests\Unit\Services\Ripple;

use App\Services\Ripple\ReachMemberReconcileService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The daily backstop for the member queue. The hooks that queue a member on join, move,
 * return or frequency change are in two codebases; if one is missed or wrong the member is
 * never picked up and nothing says so. Once a day this re-queues anyone whose join or postcode
 * change since yesterday has no reach mail ledger row after it. The drain then decides whether
 * any live reach covers them, so this pass needs no containment test of its own.
 */
class ReachMemberReconcileServiceTest extends TestCase
{
    private function queued(int $userid): ?string
    {
        return DB::table('rippling_reach_member_pending')->where('userid', $userid)->value('reason');
    }

    public function test_a_join_since_yesterday_with_no_ledger_row_after_it_is_queued(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['added' => now()->subHours(20)]);

        (new ReachMemberReconcileService())->reconcile();

        $this->assertSame('joined', $this->queued($user->id));
    }

    public function test_a_join_already_followed_by_reach_mail_is_not_queued(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $poster = $this->createTestUser();
        $msg = $this->createTestMessage($poster, $group);
        $this->createMembership($user, $group, ['added' => now()->subHours(20)]);
        DB::table('rippling_reach_notified')->insert([
            'msgid' => $msg->id, 'userid' => $user->id, 'notified_at' => now()->subHours(19),
        ]);

        (new ReachMemberReconcileService())->reconcile();

        $this->assertNull($this->queued($user->id));
    }

    public function test_a_join_older_than_the_lookback_is_not_queued(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['added' => now()->subDays(3)]);

        (new ReachMemberReconcileService())->reconcile();

        $this->assertNull($this->queued($user->id));
    }

    public function test_a_postcode_change_since_yesterday_is_queued_as_moved(): void
    {
        $user = $this->createTestUser();
        DB::table('logs')->insert([
            'timestamp' => now()->subHours(6), 'type' => 'User', 'subtype' => 'PostcodeChange',
            'user' => $user->id, 'byuser' => $user->id,
        ]);

        (new ReachMemberReconcileService())->reconcile();

        $this->assertSame('moved', $this->queued($user->id));
    }

    public function test_reconcile_reports_how_many_it_queued(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['added' => now()->subHours(2)]);

        $stats = (new ReachMemberReconcileService())->reconcile();

        $this->assertGreaterThanOrEqual(1, $stats['queued']);
    }
}
