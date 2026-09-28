<?php

namespace Tests\Unit\Services;

use App\Models\MessageGroup;
use App\Services\AutoApproveService;
use App\Services\Lockdown\LockdownService;
use App\Services\Lockdown\LockdownTriageService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AutoApproveService::process under the lockdown switch (plan
 * 2026-09-27-lockdown-switch.md, section 11.4): "AutoApproveService does not promote
 * while posts held." Ordinary behaviour is covered by AutoApproveServiceTest.
 */
class AutoApproveServiceLockdownTest extends TestCase
{
    use \Tests\Support\SeedsReachCells;

    private AutoApproveService $service;

    private LockdownService $lockdown;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('lockdowns')->delete();
        // The cache is a static, process-wide property (section 11.6), so a row cached
        // by an earlier test class in this same PHPUnit process would otherwise leak in.
        LockdownService::flushCache();
        $this->lockdown = new LockdownService();
        $this->service = new AutoApproveService($this->lockdown);
    }

    public function test_does_not_auto_approve_while_posts_held(): void
    {
        $this->lockdown->press(null, 'test');

        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['added' => now()->subHours(72)]);

        $message = $this->createTestMessage($user, $group);
        DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $group->id)
            ->update([
                'collection' => MessageGroup::COLLECTION_PENDING,
                'arrival' => now()->subHours(49),
                'contentcheck_checked_at' => now(),
            ]);

        $stats = $this->service->process();

        $this->assertEquals(0, $stats['approved']);

        $mg = DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $group->id)
            ->first();
        $this->assertEquals(MessageGroup::COLLECTION_PENDING, $mg->collection, 'held posts stay Pending, not auto-approved');
    }

    public function test_resumes_auto_approving_once_posts_lifted(): void
    {
        $this->lockdown->press(null, 'test');
        $this->lockdown->setSurfaces(['posts' => false], null);

        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['added' => now()->subHours(72)]);

        $message = $this->createTestMessage($user, $group);
        DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $group->id)
            ->update([
                'collection' => MessageGroup::COLLECTION_PENDING,
                'arrival' => now()->subHours(49),
                'contentcheck_checked_at' => now(),
            ]);

        $stats = $this->service->process();

        $this->assertGreaterThanOrEqual(1, $stats['approved']);

        $mg = DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $group->id)
            ->first();
        $this->assertEquals(MessageGroup::COLLECTION_APPROVED, $mg->collection);
    }

    /**
     * Section 11.6 point 2: "each post before promotion" - a press landing partway
     * through one process() call must stop the NEXT post it looks at, not wait for the
     * next invocation. The candidates query carries no ORDER BY, so this presses on
     * whichever post's per-item check runs first rather than asserting which one that is.
     */
    public function test_stops_promoting_further_posts_once_pressed_mid_run(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['added' => now()->subHours(72)]);

        foreach (['first', 'second'] as $label) {
            $message = $this->createTestMessage($user, $group);
            DB::table('messages_groups')
                ->where('msgid', $message->id)
                ->where('groupid', $group->id)
                ->update([
                    'collection' => MessageGroup::COLLECTION_PENDING,
                    'arrival' => now()->subHours(49),
                    'contentcheck_checked_at' => now(),
                ]);
        }

        // Presses the lockdown as a side effect of the first per-item held() check
        // succeeding, simulating another process pressing the switch right after this
        // run started on its first post. The top-of-run guard call is #1, so the first
        // per-item check inside the loop is #2.
        $lockdown = new class extends LockdownService {
            public int $calls = 0;

            public function held(string $surface): bool
            {
                $this->calls++;
                $result = parent::held($surface);
                if ($this->calls === 2) {
                    $this->press(null, 'pressed mid run');
                }

                return $result;
            }
        };
        $service = new AutoApproveService($lockdown);

        $stats = $service->process();

        $this->assertEquals(1, $stats['approved'], 'the post already under way when the press landed still completes');

        $pending = DB::table('messages_groups')
            ->where('groupid', $group->id)
            ->where('collection', MessageGroup::COLLECTION_PENDING)
            ->count();
        $this->assertEquals(1, $pending, 'the other post is left for the next run, not silently skipped forever');
    }

    /**
     * A risky hold is never resolved by the batch (ContentCheckLockdownTest::
     * test_risky_held_post_left_pending_for_a_moderator_when_posts_lifted) - its
     * lockdown_holds row stays outcome NULL indefinitely, until a moderator approves or
     * rejects it by hand. AutoApproveService's own 48h fallback must not slip it through
     * in the meantime, even long after posts is generally lifted.
     */
    public function test_never_auto_approves_a_risky_held_post_even_after_posts_lifted(): void
    {
        $this->lockdown->press(null, 'test');
        $this->lockdown->setSurfaces(['posts' => false], null);

        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['added' => now()->subHours(72)]);

        $message = $this->createTestMessage($user, $group);
        DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $group->id)
            ->update([
                'collection' => MessageGroup::COLLECTION_PENDING,
                'arrival' => now()->subHours(49),
                'contentcheck_checked_at' => now(),
            ]);

        DB::table('lockdown_holds')->insert([
            'lockdownid' => $this->lockdown->incidentId(),
            'kind' => LockdownTriageService::KIND_POST,
            'refid' => $message->id,
            'userid' => $user->id,
            'risk' => LockdownTriageService::RISK_RISKY,
            'created' => now(),
        ]);

        $stats = $this->service->process();

        $this->assertEquals(0, $stats['approved'], 'a risky hold is left for a moderator, not the 48h fallback');

        $mg = DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $group->id)
            ->first();
        $this->assertEquals(MessageGroup::COLLECTION_PENDING, $mg->collection);
    }

    /**
     * A low-risk hold not yet resolved (outcome still NULL) is ContentCheckService's own
     * paced backlog to admit through the real decision path (admitHeldPost(), plan 10.7 /
     * 11.4) - AutoApproveService's independent 48h fallback must not race ahead of that
     * pacing and approve it directly.
     */
    public function test_never_auto_approves_a_still_open_low_risk_held_post(): void
    {
        $this->lockdown->press(null, 'test');
        $this->lockdown->setSurfaces(['posts' => false], null);

        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['added' => now()->subHours(72)]);

        $message = $this->createTestMessage($user, $group);
        DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $group->id)
            ->update([
                'collection' => MessageGroup::COLLECTION_PENDING,
                'arrival' => now()->subHours(49),
                'contentcheck_checked_at' => now(),
            ]);

        DB::table('lockdown_holds')->insert([
            'lockdownid' => $this->lockdown->incidentId(),
            'kind' => LockdownTriageService::KIND_POST,
            'refid' => $message->id,
            'userid' => $user->id,
            'risk' => LockdownTriageService::RISK_LOW,
            'created' => now(),
        ]);

        $stats = $this->service->process();

        $this->assertEquals(0, $stats['approved'], 'still ContentCheckService\'s to admit, paced, not auto-approve\'s');

        $mg = DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $group->id)
            ->first();
        $this->assertEquals(MessageGroup::COLLECTION_PENDING, $mg->collection);
    }

    public function test_ack_is_written_for_each_candidate_post(): void
    {
        $this->lockdown->press(null, 'test');

        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['added' => now()->subHours(72)]);
        $message = $this->createTestMessage($user, $group);
        DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $group->id)
            ->update([
                'collection' => MessageGroup::COLLECTION_PENDING,
                'arrival' => now()->subHours(49),
                'contentcheck_checked_at' => now(),
            ]);

        $this->service->process();

        $ack = DB::table('lockdown_acks')->where('loop', 'auto-approve')->first();
        $this->assertNotNull($ack, 'auto-approve must ack so the presser sees this loop take effect');
        $this->assertEquals($this->lockdown->current()->id, $ack->lockdownrowid);
    }
}
