<?php

namespace Tests\Unit\Services\Ripple;

use App\Services\Ripple\RippleMembershipBackfillService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RippleMembershipBackfillServiceTest extends TestCase
{
    private function service(): RippleMembershipBackfillService
    {
        return new RippleMembershipBackfillService();
    }

    /** Set up a member auto-joined BEFORE the mitigations: Rippled provenance log, rippled=0, immediate. */
    private function seedPreMitigationRippledMember(): array
    {
        $user = $this->createTestUser();
        $this->createTestUserEmail($user);
        $group = $this->createTestGroup();

        DB::table('memberships')->insert([
            'userid' => $user->id, 'groupid' => $group->id, 'role' => 'Member',
            'collection' => 'Approved', 'emailfrequency' => -1, 'rippled' => 0, 'added' => now(),
        ]);
        DB::table('logs')->insert([
            'timestamp' => now()->subDay(), 'type' => 'Group', 'subtype' => 'Joined',
            'user' => $user->id, 'groupid' => $group->id, 'text' => 'Rippled',
        ]);

        return [$user->id, $group->id];
    }

    public function test_dry_run_changes_nothing(): void
    {
        [$userId, $groupId] = $this->seedPreMitigationRippledMember();

        $stats = $this->service()->backfill(null, 0, send: false);

        $this->assertSame(1, $stats['users']);
        $this->assertSame(1, $stats['flagged']);
        $this->assertSame(1, $stats['downgraded']);
        $this->assertSame(1, $stats['intros']);

        // Nothing actually changed.
        $m = DB::table('memberships')->where('userid', $userId)->where('groupid', $groupId)->first();
        $this->assertSame(0, (int) $m->rippled, 'dry run does not flag');
        $this->assertSame(-1, (int) $m->emailfrequency, 'dry run does not downgrade');
        $this->assertFalse(
            DB::table('logs')->where('user', $userId)->where('type', 'User')
                ->where('subtype', 'Mailed')->where('text', 'RippleIntro')->exists(),
            'dry run sends no intro'
        );
    }

    public function test_send_flags_downgrades_and_sends_intro_once(): void
    {
        [$userId, $groupId] = $this->seedPreMitigationRippledMember();

        $stats = $this->service()->backfill(null, 0, send: true);
        $this->assertSame(1, $stats['intros']);

        $m = DB::table('memberships')->where('userid', $userId)->where('groupid', $groupId)->first();
        $this->assertSame(1, (int) $m->rippled, 'membership flagged rippled=1');
        $this->assertSame(24, (int) $m->emailfrequency, 'immediate downgraded to daily');
        $this->assertSame(
            1,
            DB::table('logs')->where('user', $userId)->where('type', 'User')
                ->where('subtype', 'Mailed')->where('text', 'RippleIntro')->count(),
            'one intro marker written'
        );

        // Idempotent: a second send re-flags nothing and re-sends no intro.
        $stats2 = $this->service()->backfill(null, 0, send: true);
        $this->assertSame(0, $stats2['intros'], 'no intro re-sent on re-run');
        $this->assertSame(0, $stats2['flagged'], 'nothing left to flag');
        $this->assertSame(0, $stats2['downgraded'], 'nothing left to downgrade');
        $this->assertSame(
            1,
            DB::table('logs')->where('user', $userId)->where('type', 'User')
                ->where('subtype', 'Mailed')->where('text', 'RippleIntro')->count(),
            'still exactly one intro marker'
        );
    }

    public function test_user_filter_restricts_scope(): void
    {
        [$userId1] = $this->seedPreMitigationRippledMember();
        [$userId2] = $this->seedPreMitigationRippledMember();

        $stats = $this->service()->backfill($userId1, 0, send: true);

        $this->assertSame(1, $stats['users'], 'only the targeted user is processed');
        $this->assertSame(0, (int) DB::table('memberships')->where('userid', $userId2)
            ->value('rippled'), 'the other user is untouched');
    }
}
