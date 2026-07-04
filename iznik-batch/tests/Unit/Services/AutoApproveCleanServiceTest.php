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

        // Deterministic site defaults for the tests. The rollout gate defaults OFF in
        // config; these tests exercise the behaviour WHEN ENABLED, so switch it on here.
        // The gate itself is covered by the test_rollout_gate_* tests below.
        config([
            'freegle.autoapprove.enabled' => true,
            'freegle.autoapprove.trial_group_ids' => '',
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

    // --- A2: autoapprove_hold_until predicate ---

    public function test_hold_until_future_keeps_post_pending(): void
    {
        // A post whose autoapprove_hold_until is 5 minutes in the future must
        // be skipped by the auto-approver even though the delay has elapsed.
        [$user, $group, $message] = $this->makeApprovable();
        DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $group->id)
            ->update(['autoapprove_hold_until' => now()->addMinutes(5)]);

        $this->service->process();

        $this->assertStillPending($message->id, $group->id);
    }

    public function test_hold_until_past_allows_approval(): void
    {
        // A post whose autoapprove_hold_until has already expired must still
        // be auto-approved normally.
        [$user, $group, $message] = $this->makeApprovable();
        DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $group->id)
            ->update(['autoapprove_hold_until' => now()->subMinutes(1)]);

        $this->service->process();

        $this->assertApproved($message->id, $group->id);
    }

    // --- D1: cross-group Spam-collection guard ---

    public function test_spam_on_another_group_blocks_approval_on_this_group(): void
    {
        // Message is clean and pending on group A (eligible for auto-approval),
        // BUT also in the Spam collection on group B.
        // AutoApproveCleanService must NOT auto-approve it on group A.
        [$user, $groupA, $message] = $this->makeApprovable();

        $groupB = $this->createTestGroup();
        // Insert a Spam-collection row for the same message on group B.
        DB::table('messages_groups')->insert([
            'msgid'      => $message->id,
            'groupid'    => $groupB->id,
            'collection' => MessageGroup::COLLECTION_SPAM,
            'arrival'    => now(),
            'deleted'    => 0,
        ]);

        $this->service->process();

        $this->assertStillPending($message->id, $groupA->id);
    }

    // --- D3: quality-sample filter + dry-run stat placement ---

    public function test_already_quality_sampled_row_is_excluded_from_candidate_query(): void
    {
        // A row already marked quality_sample=1 must not appear in the candidate
        // query at all. This verifies the ->where('mg.quality_sample', 0) guard.
        [$user, $group, $message] = $this->makeApprovable([
            'group' => ['settings' => ['autoapprove' => ['quality_check_percent' => 0]]],
        ]);
        // Manually pre-mark the row as already sampled (e.g. by a previous run).
        DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $group->id)
            ->update(['quality_sample' => 1]);

        $stats = $this->service->process();

        // The row is excluded from the candidate set entirely — held_quality is 0
        // because the service never even sees it.
        $this->assertEquals(0, $stats['held_quality'], 'already-sampled row must not increment held_quality again');
        $this->assertStillPending($message->id, $group->id);
    }

    public function test_held_quality_stat_not_incremented_in_dry_run(): void
    {
        // $stats['held_quality']++ must be inside the if(!$dryRun) block so a
        // dry-run pass doesn't falsely inflate the cron stat.
        [$user, $group, $message] = $this->makeApprovable([
            'group' => ['settings' => ['autoapprove' => ['quality_check_percent' => 100]]],
        ]);

        $stats = $this->service->process(dryRun: true);

        $this->assertEquals(0, $stats['held_quality'], 'held_quality must be 0 in a dry run');
        $this->assertStillPending($message->id, $group->id);
    }

    // --- D6: OFFER freebie-alert background task ---

    public function test_approved_offer_queues_freebie_alert_background_task(): void
    {
        // An approved OFFER must insert a background_tasks row with
        // task_type=TASK_FREEBIE_ALERTS_ADD containing the msgid, so the Go
        // background-task processor can fan out push notifications to nearby users.
        [$user, $group, $message] = $this->makeApprovable([
            'message' => ['type' => \App\Models\Message::TYPE_OFFER],
        ]);

        $this->service->process();

        $this->assertApproved($message->id, $group->id);
        $this->assertDatabaseHas('background_tasks', [
            'task_type' => \App\Models\BackgroundTask::TASK_FREEBIE_ALERTS_ADD,
        ]);
        // Verify the data payload contains the correct msgid.
        $task = DB::table('background_tasks')
            ->where('task_type', \App\Models\BackgroundTask::TASK_FREEBIE_ALERTS_ADD)
            ->first();
        $data = json_decode($task->data, true);
        $this->assertEquals((int) $message->id, $data['msgid']);
    }

    public function test_approved_wanted_does_not_queue_freebie_alert(): void
    {
        // Only OFFERs trigger freebie alerts; WANTEDs must not insert a row.
        [$user, $group, $message] = $this->makeApprovable([
            'message' => ['type' => \App\Models\Message::TYPE_WANTED],
        ]);

        $this->service->process();

        $this->assertApproved($message->id, $group->id);
        $this->assertDatabaseMissing('background_tasks', [
            'task_type' => \App\Models\BackgroundTask::TASK_FREEBIE_ALERTS_ADD,
        ]);
    }

    public function test_approval_seeds_spatial_index_immediately(): void
    {
        // Parity with the Go manual-approve path: the post must hit messages_spatial at
        // approval time (browse/search/rippling visibility), not after the 5-min cron.
        [$user, $group, $message] = $this->makeApprovable();
        DB::table('messages_spatial')->where('msgid', $message->id)->delete();

        $this->service->process();

        $this->assertApproved($message->id, $group->id);
        $this->assertSame(
            1,
            DB::table('messages_spatial')->where('msgid', $message->id)->where('groupid', $group->id)->count(),
            'auto-approved post is in messages_spatial immediately'
        );
    }

    // ───────────────────────── Rollout gate (dark by default) ─────────────────────────

    public function test_rollout_gate_off_by_default_approves_nothing(): void
    {
        // The config default (enabled=false, no trial groups) must make deploying this
        // code a no-op: an otherwise-eligible post stays in Pending untouched.
        config(['freegle.autoapprove.enabled' => false, 'freegle.autoapprove.trial_group_ids' => '']);
        [$user, $group, $message] = $this->makeApprovable();

        $stats = $this->service->process();

        $this->assertStillPending($message->id, $group->id);
        $this->assertSame(0, array_sum($stats), 'feature dark: nothing approved, held, vetoed or skipped');
    }

    public function test_rollout_gate_trial_groups_enable_only_those_groups(): void
    {
        // Phased trial: with the master switch off, only posts on the listed groups
        // auto-approve; identical posts on other groups are untouched.
        [$userA, $groupA, $messageA] = $this->makeApprovable();
        [$userB, $groupB, $messageB] = $this->makeApprovable();
        config([
            'freegle.autoapprove.enabled' => false,
            'freegle.autoapprove.trial_group_ids' => (string) $groupA->id,
        ]);

        $this->service->process();

        $this->assertApproved($messageA->id, $groupA->id);
        $this->assertStillPending($messageB->id, $groupB->id);
    }

    public function test_rollout_gate_trial_list_tolerates_spaces_and_multiple_ids(): void
    {
        [$userA, $groupA, $messageA] = $this->makeApprovable();
        [$userB, $groupB, $messageB] = $this->makeApprovable();
        config([
            'freegle.autoapprove.enabled' => false,
            'freegle.autoapprove.trial_group_ids' => ' ' . $groupA->id . ' , ' . $groupB->id . ' ',
        ]);

        $this->service->process();

        $this->assertApproved($messageA->id, $groupA->id);
        $this->assertApproved($messageB->id, $groupB->id);
    }

    public function test_rollout_gate_enabled_ignores_trial_list(): void
    {
        // enabled=true is the master switch: the trial list no longer restricts anything.
        [$user, $group, $message] = $this->makeApprovable();
        config([
            'freegle.autoapprove.enabled' => true,
            'freegle.autoapprove.trial_group_ids' => '999999999',
        ]);

        $this->service->process();

        $this->assertApproved($message->id, $group->id);
    }
}
