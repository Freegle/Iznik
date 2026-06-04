<?php

namespace Tests\Unit\Services;

use App\Models\Group;
use App\Models\Membership;
use App\Models\Message;
use App\Models\MessageGroup;
use App\Models\User;
use App\Services\AutoApproveCleanService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Auto-approve of content-check-clean posts from NULL-posting-status ("auto-moderated")
 * members after a configurable delay, with danger-signal vetoes and a quality-check sample.
 */
class AutoApproveCleanServiceTest extends TestCase
{
    protected AutoApproveCleanService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AutoApproveCleanService();

        // Deterministic site defaults for the tests.
        config([
            'freegle.autoapprove.delay_minutes' => 20,
            'freegle.autoapprove.quality_check_percent' => 0,
            'freegle.autoapprove.danger_log_days' => 90,
        ]);
    }

    /**
     * Build a clean, content-checked, NULL-status pending post that is eligible for
     * auto-approval (arrival older than the default 20-minute delay).
     *
     * @return array{0: User, 1: Group, 2: Message}
     */
    private function makeApprovable(array $opts = []): array
    {
        $user  = $this->createTestUser();
        $group = $this->createTestGroup($opts['group'] ?? []);
        $this->createMembership($user, $group, array_merge([
            'added' => now()->subDays(2),
            // ourPostingStatus left NULL — the auto-moderated tier.
        ], $opts['membership'] ?? []));

        $message = $this->createTestMessage($user, $group, $opts['message'] ?? []);

        DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $group->id)
            ->update(array_merge([
                'collection'              => MessageGroup::COLLECTION_PENDING,
                'arrival'                 => now()->subMinutes(21),
                'contentcheck_checked_at' => now()->subMinutes(20),
                'contentcheck_reasons'    => null,
            ], $opts['mg'] ?? []));

        return [$user, $group, $message];
    }

    private function assertApproved(int $msgid, int $groupid): void
    {
        $mg = DB::table('messages_groups')->where('msgid', $msgid)->where('groupid', $groupid)->first();
        $this->assertEquals(MessageGroup::COLLECTION_APPROVED, $mg->collection, 'message should be Approved');
        $this->assertNull($mg->approvedby, 'auto-approval leaves approvedby NULL');
        $this->assertDatabaseHas('logs', [
            'msgid' => $msgid, 'groupid' => $groupid, 'type' => 'Message', 'subtype' => 'Autoapproved',
        ]);
    }

    private function assertStillPending(int $msgid, int $groupid): void
    {
        $this->assertDatabaseHas('messages_groups', [
            'msgid' => $msgid, 'groupid' => $groupid, 'collection' => MessageGroup::COLLECTION_PENDING,
        ]);
        $this->assertDatabaseMissing('logs', [
            'msgid' => $msgid, 'type' => 'Message', 'subtype' => 'Autoapproved',
        ]);
    }

    public function test_stats_structure(): void
    {
        $stats = $this->service->process();
        foreach (['approved', 'held_quality', 'vetoed', 'skipped', 'errors'] as $key) {
            $this->assertArrayHasKey($key, $stats);
        }
    }

    public function test_approves_clean_null_status_post_after_delay(): void
    {
        [$user, $group, $message] = $this->makeApprovable();

        $stats = $this->service->process();

        $this->assertGreaterThanOrEqual(1, $stats['approved']);
        $this->assertApproved($message->id, $group->id);
    }

    public function test_does_not_approve_before_delay(): void
    {
        [$user, $group, $message] = $this->makeApprovable([
            'mg' => ['arrival' => now()->subMinutes(19)],
        ]);

        $this->service->process();

        $this->assertStillPending($message->id, $group->id);
    }

    public function test_skips_default_posting_status(): void
    {
        // DEFAULT members are trusted and approved immediately by contentcheck — not our job.
        [$user, $group, $message] = $this->makeApprovable([
            'membership' => ['ourPostingStatus' => 'DEFAULT'],
        ]);

        $this->service->process();

        $this->assertStillPending($message->id, $group->id);
    }

    public function test_skips_moderated_posting_status(): void
    {
        // Explicit MODERATED stays moderated.
        [$user, $group, $message] = $this->makeApprovable([
            'membership' => ['ourPostingStatus' => 'MODERATED'],
        ]);

        $this->service->process();

        $this->assertStillPending($message->id, $group->id);
    }

    public function test_skips_when_content_check_not_run(): void
    {
        [$user, $group, $message] = $this->makeApprovable([
            'mg' => ['contentcheck_checked_at' => null],
        ]);

        $this->service->process();

        $this->assertStillPending($message->id, $group->id);
    }

    public function test_skips_when_content_check_flagged_reasons(): void
    {
        // Suspect content (reasons present) must keep the post in Pending for a mod.
        [$user, $group, $message] = $this->makeApprovable([
            'mg' => ['contentcheck_reasons' => json_encode([['check' => 'Money', 'action' => 'flag']])],
        ]);

        $this->service->process();

        $this->assertStillPending($message->id, $group->id);
    }

    public function test_skips_held_message(): void
    {
        [$user, $group, $message] = $this->makeApprovable();
        DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $group->id)
            ->update(['heldby' => $user->id]);

        $this->service->process();

        $this->assertStillPending($message->id, $group->id);
    }

    public function test_skips_message_with_spamreason(): void
    {
        [$user, $group, $message] = $this->makeApprovable();
        DB::table('messages')->where('id', $message->id)->update(['spamreason' => 'CountryBlocked']);

        $this->service->process();

        $this->assertStillPending($message->id, $group->id);
    }

    public function test_skips_soft_deleted_message(): void
    {
        [$user, $group, $message] = $this->makeApprovable();
        DB::table('messages')->where('id', $message->id)->update(['deleted' => now()]);

        $this->service->process();

        $this->assertStillPending($message->id, $group->id);
    }

    public function test_skips_moderated_group(): void
    {
        [$user, $group, $message] = $this->makeApprovable([
            'group' => ['settings' => ['moderated' => 1]],
        ]);

        $this->service->process();

        $this->assertStillPending($message->id, $group->id);
    }

    public function test_skips_closed_group(): void
    {
        [$user, $group, $message] = $this->makeApprovable([
            'group' => ['settings' => ['closed' => true]],
        ]);

        $this->service->process();

        $this->assertStillPending($message->id, $group->id);
    }

    public function test_veto_microvolunteering_reject(): void
    {
        [$user, $group, $message] = $this->makeApprovable();
        $reviewer = $this->createTestUser();
        DB::table('microactions')->insert([
            'userid'         => $reviewer->id,
            'msgid'          => $message->id,
            'actiontype'     => 'CheckMessage',
            'result'         => 'Reject',
            'score_negative' => 1,
        ]);

        $stats = $this->service->process();

        $this->assertGreaterThanOrEqual(1, $stats['vetoed']);
        $this->assertStillPending($message->id, $group->id);
    }

    public function test_does_not_veto_microvolunteering_approve(): void
    {
        [$user, $group, $message] = $this->makeApprovable();
        $reviewer = $this->createTestUser();
        DB::table('microactions')->insert([
            'userid'         => $reviewer->id,
            'msgid'          => $message->id,
            'actiontype'     => 'CheckMessage',
            'result'         => 'Approve',
            'score_negative' => 0,
        ]);

        $this->service->process();

        $this->assertApproved($message->id, $group->id);
    }

    public function test_veto_user_note(): void
    {
        [$user, $group, $message] = $this->makeApprovable();
        DB::table('users_comments')->insert([
            'userid'  => $user->id,
            'groupid' => $group->id,
            'user1'   => 'Keep an eye on this member.',
        ]);

        $stats = $this->service->process();

        $this->assertGreaterThanOrEqual(1, $stats['vetoed']);
        $this->assertStillPending($message->id, $group->id);
    }

    public function test_veto_recent_negative_mod_log(): void
    {
        [$user, $group, $message] = $this->makeApprovable();
        $mod = $this->createTestUser();
        DB::table('logs')->insert([
            'timestamp' => now()->subDays(1),
            'type'      => 'User',
            'subtype'   => 'Mailed',
            'user'      => $user->id,
            'byuser'    => $mod->id,
            'groupid'   => $group->id,
        ]);

        $stats = $this->service->process();

        $this->assertGreaterThanOrEqual(1, $stats['vetoed']);
        $this->assertStillPending($message->id, $group->id);
    }

    public function test_does_not_veto_old_negative_log(): void
    {
        [$user, $group, $message] = $this->makeApprovable();
        $mod = $this->createTestUser();
        DB::table('logs')->insert([
            'timestamp' => now()->subDays(120), // outside the 90-day danger window
            'type'      => 'User',
            'subtype'   => 'Mailed',
            'user'      => $user->id,
            'byuser'    => $mod->id,
            'groupid'   => $group->id,
        ]);

        $this->service->process();

        $this->assertApproved($message->id, $group->id);
    }

    public function test_veto_known_spammer(): void
    {
        [$user, $group, $message] = $this->makeApprovable();
        DB::table('spam_users')->insert([
            'userid'     => $user->id,
            'collection' => 'Spammer',
        ]);

        $stats = $this->service->process();

        $this->assertGreaterThanOrEqual(1, $stats['vetoed']);
        $this->assertStillPending($message->id, $group->id);
    }

    public function test_veto_membership_review_pending(): void
    {
        [$user, $group, $message] = $this->makeApprovable();
        DB::table('memberships')
            ->where('userid', $user->id)
            ->where('groupid', $group->id)
            ->update(['reviewrequestedat' => now()->subHour(), 'reviewedat' => null]);

        $stats = $this->service->process();

        $this->assertGreaterThanOrEqual(1, $stats['vetoed']);
        $this->assertStillPending($message->id, $group->id);
    }

    public function test_quality_sample_100_holds_all(): void
    {
        [$user, $group, $message] = $this->makeApprovable([
            'group' => ['settings' => ['autoapprove' => ['quality_check_percent' => 100]]],
        ]);

        $stats = $this->service->process();

        $this->assertGreaterThanOrEqual(1, $stats['held_quality']);
        $this->assertStillPending($message->id, $group->id);
        // Held posts are marked as a quality-check sample for the stats dashboard.
        $this->assertDatabaseHas('messages_groups', [
            'msgid' => $message->id,
            'groupid' => $group->id,
            'quality_sample' => 1,
        ]);
    }

    public function test_quality_sample_zero_holds_none(): void
    {
        [$user, $group, $message] = $this->makeApprovable([
            'group' => ['settings' => ['autoapprove' => ['quality_check_percent' => 0]]],
        ]);

        $this->service->process();

        $this->assertApproved($message->id, $group->id);
    }

    public function test_per_group_delay_override(): void
    {
        // Site default is 20 min; this group overrides to 5 min, so a 6-min-old post approves.
        [$user, $group, $message] = $this->makeApprovable([
            'group' => ['settings' => ['autoapprove' => ['delay_minutes' => 5]]],
            'mg'    => ['arrival' => now()->subMinutes(6)],
        ]);

        $this->service->process();

        $this->assertApproved($message->id, $group->id);
    }

    public function test_zero_group_delay_falls_back_to_site_default(): void
    {
        // A 0 override means "use the site default" (20), so a 6-min-old post stays pending.
        [$user, $group, $message] = $this->makeApprovable([
            'group' => ['settings' => ['autoapprove' => ['delay_minutes' => 0]]],
            'mg'    => ['arrival' => now()->subMinutes(6)],
        ]);

        $this->service->process();

        $this->assertStillPending($message->id, $group->id);
    }

    public function test_dry_run_does_not_modify_database(): void
    {
        [$user, $group, $message] = $this->makeApprovable();

        $stats = $this->service->process(dryRun: true);

        $this->assertGreaterThanOrEqual(1, $stats['approved']);
        $this->assertStillPending($message->id, $group->id);
    }

    public function test_records_ham_for_spam_message(): void
    {
        [$user, $group, $message] = $this->makeApprovable();
        DB::table('messages')->where('id', $message->id)->update(['spamtype' => 'SubjectUsedForDifferentGroups']);

        $this->service->process();

        $this->assertApproved($message->id, $group->id);
        $this->assertDatabaseHas('messages_spamham', ['msgid' => $message->id, 'spamham' => 'Ham']);
    }
}
