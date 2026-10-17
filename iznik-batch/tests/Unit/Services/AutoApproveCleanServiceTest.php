<?php

namespace Tests\Unit\Services;

use App\Models\Group;
use App\Models\Membership;
use App\Models\Message;
use App\Models\MessageAutomod;
use App\Models\MessageGroup;
use App\Models\User;
use App\Services\AutoApproveCleanService;
use App\Services\ContentCheckService;
use Illuminate\Support\Facades\DB;
use Tests\Support\LokiSpy;
use Tests\Support\MakesApprovablePosts;
use Tests\TestCase;

/**
 * Auto-approve of content-check-clean posts from NULL-posting-status ("auto-moderated")
 * members after a configurable delay, with danger-signal vetoes and a quality-check sample.
 */
class AutoApproveCleanServiceTest extends TestCase
{
    use MakesApprovablePosts;

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

    public function test_stats_structure(): void
    {
        $stats = $this->service->process();
        foreach (['approved', 'held_quality', 'errors'] as $key) {
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

    /**
     * The wait is counted from the chart's decision, not only from arrival. If the review
     * lands late (service down, backlog, UNAVAILABLE retry) a post that is already older than
     * the delay must not publish the minute its verdict is written: moderators would have had
     * no countdown and no window at all.
     */
    public function test_does_not_approve_within_the_delay_of_the_automod_decision(): void
    {
        [$user, $group, $message] = $this->makeApprovable([
            'mg'      => ['arrival' => now()->subHours(2)],
            'automod' => ['created' => now()->subMinutes(19)],
        ]);

        $this->service->process();

        $this->assertStillPending($message->id, $group->id);
    }

    public function test_approves_once_the_automod_decision_is_as_old_as_the_delay(): void
    {
        [$user, $group, $message] = $this->makeApprovable([
            'mg'      => ['arrival' => now()->subHours(2), 'contentcheck_checked_at' => now()->subMinutes(22)],
            'automod' => ['created' => now()->subMinutes(21)],
        ]);

        $this->service->process();

        $this->assertApproved($message->id, $group->id);
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

    public function test_the_real_content_check_then_the_automod_approve_path_publishes(): void
    {
        // End to end through the two crons, in order. The content check writes a
        // MemberModerated explanation onto every NULL-status member's clean post (that is
        // why it is waiting), so a clean path that asked for reasons IS NULL matched none of
        // the population it was built for. Seeding reasons = NULL directly, as the other
        // tests here do, could never see that. Eligibility for auto-approval is now an
        // automod "approve" verdict (messages_automod), seeded here the way the automod
        // command would write it once the chart has reviewed the post — the content check
        // and automod review are independent crons, run in the order they would in prod.
        [$user, $group, $message] = $this->makeApprovable([
            'mg' => ['contentcheck_checked_at' => null, 'contentcheck_reasons' => null],
            'automod' => false,
        ]);

        (new ContentCheckService())->processUnprocessed();

        $mg = DB::table('messages_groups')->where('msgid', $message->id)->where('groupid', $group->id)->first();
        $this->assertSame(MessageGroup::COLLECTION_PENDING, $mg->collection, 'the content check keeps a NULL-status post Pending');
        $this->assertNotNull($mg->contentcheck_checked_at);
        $this->assertStringContainsString(
            ContentCheckService::CHECK_MEMBER_MODERATED,
            (string) $mg->contentcheck_reasons,
            'the content check records why the post is waiting'
        );

        // The check just ran and the chart decides straight after it; wind both back so the
        // wait after the decision has passed, keeping the decision after the check.
        DB::table('messages_groups')->where('msgid', $message->id)->where('groupid', $group->id)
            ->update(['contentcheck_checked_at' => now()->subMinutes(21)]);
        MessageAutomod::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'mode' => MessageAutomod::MODE_APPROVE,
            'chart_version' => 'test',
            'verdict' => MessageAutomod::VERDICT_APPROVE,
            'end_node' => 'APPROVE',
            'reason' => null,
            'path' => [],
            'created' => now()->subMinutes(20),
        ]);

        $stats = $this->service->process();

        $this->assertGreaterThanOrEqual(1, $stats['approved']);
        $this->assertApproved($message->id, $group->id);
    }

    /**
     * A copy pulled back by a moderator (Back to Pending, or Reject from the Check queue)
     * carries needs_moderator until a moderator's own Approve clears it. Release clears
     * heldby alone, so without this the still-current approve verdict would publish the
     * post again a minute later.
     */
    public function test_skips_copy_pulled_back_for_a_moderator(): void
    {
        [$user, $group, $message] = $this->makeApprovable([
            'mg' => ['needs_moderator' => 1],
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

    public function test_quality_sample_100_holds_all(): void
    {
        config(['freegle.autoapprove.quality_check_percent' => 100]);
        [$user, $group, $message] = $this->makeApprovable();

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
        config(['freegle.autoapprove.quality_check_percent' => 0]);
        [$user, $group, $message] = $this->makeApprovable();

        $this->service->process();

        $this->assertApproved($message->id, $group->id);
    }

    public function test_quality_sample_is_the_same_for_every_community(): void
    {
        // A leftover community setting of 100% is ignored: with the site-wide rate at 0
        // the post publishes, and with the site-wide rate at 100 it is held even though
        // the community says 0.
        config(['freegle.autoapprove.quality_check_percent' => 0]);
        [$user, $group, $message] = $this->makeApprovable([
            'group' => ['settings' => ['autoapprove' => ['quality_check_percent' => 100]]],
        ]);
        $this->service->process();
        $this->assertApproved($message->id, $group->id);

        config(['freegle.autoapprove.quality_check_percent' => 100]);
        [$user2, $group2, $message2] = $this->makeApprovable([
            'group' => ['settings' => ['autoapprove' => ['quality_check_percent' => 0]]],
        ]);
        $this->service->process();
        $this->assertStillPending($message2->id, $group2->id);
    }

    public function test_delay_is_the_same_for_every_community(): void
    {
        // A community cannot shorten the wait: a leftover settings.autoapprove.delay_minutes
        // of 5 is ignored, so a 6-minute-old post stays pending until the site-wide 20.
        [$user, $group, $message] = $this->makeApprovable([
            'group' => ['settings' => ['autoapprove' => ['delay_minutes' => 5]]],
            'mg'    => ['arrival' => now()->subMinutes(6)],
        ]);

        $this->service->process();

        $this->assertStillPending($message->id, $group->id);
    }

    public function test_post_older_than_the_site_wide_delay_approves(): void
    {
        [$user, $group, $message] = $this->makeApprovable([
            'group' => ['settings' => ['autoapprove' => ['delay_minutes' => 120]]],
            'mg'    => ['arrival' => now()->subMinutes(21)],
        ]);

        $this->service->process();

        $this->assertApproved($message->id, $group->id);
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
        config(['freegle.autoapprove.quality_check_percent' => 0]);
        [$user, $group, $message] = $this->makeApprovable();
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
        config(['freegle.autoapprove.quality_check_percent' => 100]);
        [$user, $group, $message] = $this->makeApprovable();

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

    /**
     * A typo must never give a wait of zero: (int) "abc" is 0, which would publish the minute
     * a decision lands. Blank, zero, negative and non-numeric values all mean the default 20,
     * as they do in the Go countdown (utils.AutoapproveDelayMinutes).
     */
    public function test_a_junk_delay_means_the_default_not_no_wait(): void
    {
        foreach (['abc', '-5', '0', '', '20min', '1e1', null] as $junk) {
            config(['freegle.autoapprove.delay_minutes' => $junk]);

            $this->assertSame(20, $this->service->delayMinutes(), 'delay for ' . var_export($junk, true));
        }

        config(['freegle.autoapprove.delay_minutes' => ' 35 ']);
        $this->assertSame(35, $this->service->delayMinutes());
    }

    // --- decision vs content check, shadow, and the write-time guards -------------------------

    /** The chart's decision must postdate the content check it drew its facts from. */
    public function test_does_not_approve_on_a_decision_older_than_the_latest_content_check(): void
    {
        [$user, $group, $message] = $this->makeApprovable([
            'mg'      => ['contentcheck_checked_at' => now()->subMinutes(5)],
            'automod' => ['created' => now()->subMinutes(25)],
        ]);

        $this->service->process();

        $this->assertStillPending($message->id, $group->id);
    }

    public function test_a_shadow_community_never_publishes_even_when_approve_is_on_everywhere(): void
    {
        [$user, $group, $message] = $this->makeApprovable();
        config(['freegle.automod.shadow_group_ids' => (string) $group->id]);

        $this->service->process();

        $this->assertStillPending($message->id, $group->id);
    }

    /** Runs approve() on a row exactly as process() would have selected it a moment earlier. */
    private function approveSelectedRow(Message $message, Group $group, User $user): void
    {
        $row = (object) [
            'msgid'      => $message->id,
            'groupid'    => $group->id,
            'fromuser'   => $user->id,
            'spamtype'   => null,
            'subject'    => $message->subject,
            'msgtype'    => $message->type,
            'arrival'    => now()->subMinutes(21)->toDateTimeString(),
            'decided_at' => now()->subMinutes(20)->toDateTimeString(),
        ];
        $method = new \ReflectionMethod($this->service, 'approve');
        $method->setAccessible(true);
        $method->invoke($this->service, $row);
    }

    private function assertNothingPublished(int $msgid, int $groupid, string $collection): void
    {
        $this->assertDatabaseHas('messages_groups', ['msgid' => $msgid, 'groupid' => $groupid, 'collection' => $collection]);
        $this->assertDatabaseMissing('logs', ['msgid' => $msgid, 'type' => 'Message', 'subtype' => 'Autoapproved']);
        $this->assertDatabaseMissing('messages_spatial', ['msgid' => $msgid]);
        $this->assertSame(0, DB::table('background_tasks')->where('data', 'like', '%"msgid":' . $msgid . '%')->count());
    }

    /**
     * Candidates are selected once and written one by one; a moderator can act in between.
     * A Reject landing in that window must stand: the write re-checks that the copy is still
     * an unheld, un-pulled-back Pending row with no live hold, and does nothing otherwise.
     */
    public function test_a_reject_landing_before_the_write_is_not_overturned(): void
    {
        [$user, $group, $message] = $this->makeApprovable();
        DB::table('messages_groups')->where('msgid', $message->id)->where('groupid', $group->id)
            ->update(['collection' => MessageGroup::COLLECTION_REJECTED, 'rejectedat' => now()]);

        $this->approveSelectedRow($message, $group, $user);

        $this->assertNothingPublished($message->id, $group->id, MessageGroup::COLLECTION_REJECTED);
    }

    public function test_a_hold_bumped_before_the_write_wins(): void
    {
        [$user, $group, $message] = $this->makeApprovable();
        DB::table('messages_groups')->where('msgid', $message->id)->where('groupid', $group->id)
            ->update(['autoapprove_hold_until' => now()->addMinutes(10)]);

        $this->approveSelectedRow($message, $group, $user);

        $this->assertNothingPublished($message->id, $group->id, MessageGroup::COLLECTION_PENDING);
    }

    public function test_a_copy_pulled_back_before_the_write_is_not_published(): void
    {
        [$user, $group, $message] = $this->makeApprovable();
        DB::table('messages_groups')->where('msgid', $message->id)->where('groupid', $group->id)
            ->update(['needs_moderator' => 1]);

        $this->approveSelectedRow($message, $group, $user);

        $this->assertNothingPublished($message->id, $group->id, MessageGroup::COLLECTION_PENDING);
    }

    // --- what reaches Loki -------------------------------------------------------------------

    public function test_a_publish_is_logged_to_loki_with_how_long_it_waited(): void
    {
        $loki = new LokiSpy();
        $service = new AutoApproveCleanService($loki);
        [$user, $group, $message] = $this->makeApprovable([
            'mg'      => ['arrival' => now()->subMinutes(40), 'contentcheck_checked_at' => now()->subMinutes(39)],
            'automod' => ['created' => now()->subMinutes(25)],
        ]);

        $service->process();

        $this->assertApproved($message->id, $group->id);
        $events = $loki->eventsOf('autoapprove', 'published');
        $this->assertCount(1, $events);
        $this->assertSame($message->id, $events[0]['context']['msgid']);
        $this->assertSame($group->id, $events[0]['context']['groupid']);
        $this->assertGreaterThanOrEqual(39, $events[0]['context']['minutes_waited']);
        $this->assertLessThanOrEqual(41, $events[0]['context']['minutes_waited']);
        $this->assertGreaterThanOrEqual(24 * 60, $events[0]['context']['decision_age_seconds']);
        $this->assertLessThanOrEqual(26 * 60, $events[0]['context']['decision_age_seconds']);
    }

    // --- round 2: signals that arrive inside the wait, the whole write guard, mode, caps ------

    /** Runs the atomic write on a row exactly as process() would have selected it a moment earlier. */
    private function writeSelectedRow(Message $message, Group $group, User $user): bool
    {
        $row = (object) [
            'msgid'      => $message->id,
            'groupid'    => $group->id,
            'fromuser'   => $user->id,
            'spamtype'   => null,
            'subject'    => $message->subject,
            'msgtype'    => $message->type,
            'arrival'    => now()->subMinutes(22)->toDateTimeString(),
        ];
        $method = new \ReflectionMethod($this->service, 'publishIfStillDue');
        $method->setAccessible(true);

        return $method->invoke($this->service, $row);
    }

    private function skipEvent(LokiSpy $loki, string $reason): ?array
    {
        foreach ($loki->eventsOf('autoapprove', 'skipped') as $event) {
            if ($event['context']['reason'] === $reason) {
                return $event['context'];
            }
        }

        return null;
    }

    /**
     * The point of the wait is that someone can spot a problem inside it. The signals used to be
     * read once, when the chart decided; a microvolunteer rejecting at minute five changed
     * nothing, and two rejecting only pulled back an Approved copy, never a Pending one.
     */
    public function test_a_microvolunteer_reject_after_the_decision_blocks_the_publish(): void
    {
        $loki = new LokiSpy();
        $service = new AutoApproveCleanService($loki);
        [$user, $group, $message] = $this->makeApprovable();
        $reviewer = $this->createTestUser();
        DB::table('microactions')->insert([
            'userid' => $reviewer->id, 'msgid' => $message->id, 'actiontype' => 'CheckMessage',
            'result' => 'Reject', 'score_negative' => 1,
        ]);

        $stats = $service->process();

        $this->assertSame(0, $stats['approved']);
        $this->assertStillPending($message->id, $group->id);
        $event = $this->skipEvent($loki, 'danger signal');
        $this->assertNotNull($event, 'the skip is written to Loki');
        $this->assertSame('microvol_reject', $event['signal']);
        $this->assertSame($message->id, $event['msgid']);
    }

    public function test_a_post_stopped_by_a_late_signal_is_decided_again_on_the_current_facts(): void
    {
        $loki = new LokiSpy();
        $service = new AutoApproveCleanService($loki);
        [$user, $group, $message] = $this->makeApprovable();
        DB::table('users_comments')->insert(['userid' => $user->id, 'groupid' => $group->id, 'user1' => 'Keep an eye on this member.']);

        $service->process();

        $this->assertNull(
            DB::table('messages_automod')->where('msgid', $message->id)->where('groupid', $group->id)->first(),
            'the stale approve is withdrawn, so messages:automod decides again (it holds, with the right reason) and no countdown is promised'
        );
    }

    public function test_every_member_and_community_signal_is_rechecked_at_publish(): void
    {
        $cases = [
            'mod_note' => function ($user, $group, $message) {
                DB::table('users_comments')->insert(['userid' => $user->id, 'groupid' => $group->id, 'user1' => 'note']);
            },
            'recent_action' => function ($user, $group, $message) {
                $mod = $this->createTestUser();
                DB::table('logs')->insert([
                    'timestamp' => now()->subHours(2), 'type' => 'Message', 'subtype' => 'Rejected',
                    'user' => $user->id, 'byuser' => $mod->id, 'groupid' => $group->id,
                ]);
            },
            'spammer' => function ($user, $group, $message) {
                DB::table('spam_users')->insert(['userid' => $user->id, 'collection' => 'Spammer']);
            },
            'membership_review' => function ($user, $group, $message) {
                DB::table('memberships')->where('userid', $user->id)->where('groupid', $group->id)
                    ->update(['reviewrequestedat' => now()->subHour(), 'reviewedat' => null]);
            },
            'group_disallows' => function ($user, $group, $message) {
                DB::table('groups')->where('id', $group->id)->update(['settings' => json_encode(['moderated' => 1])]);
            },
        ];

        foreach ($cases as $signal => $arrange) {
            $loki = new LokiSpy();
            $service = new AutoApproveCleanService($loki);
            [$user, $group, $message] = $this->makeApprovable();
            $arrange($user, $group, $message);

            $stats = $service->process();

            $this->assertSame(0, $stats['approved'], "{$signal} arriving after the decision must stop the publish");
            $this->assertStillPending($message->id, $group->id);
            $event = $this->skipEvent($loki, 'danger signal');
            $this->assertNotNull($event, "{$signal} is named in the skip event");
            $this->assertSame($signal, $event['signal']);
        }
    }

    public function test_a_system_log_with_no_actor_is_not_a_danger_signal(): void
    {
        // The ripple engine writes Message/Deleted and User/Mailed rows with no byuser; they
        // are lifecycle, not a moderator acting, and must not stop a member's next post.
        [$user, $group, $message] = $this->makeApprovable();
        DB::table('logs')->insert([
            'timestamp' => now()->subDays(2), 'type' => 'Message', 'subtype' => 'Deleted',
            'user' => $user->id, 'byuser' => null, 'groupid' => $group->id,
        ]);

        $stats = $this->service->process();

        $this->assertSame(1, $stats['approved']);
        $this->assertApproved($message->id, $group->id);
    }

    // The write itself carries the whole decision: what was true when the candidates were read
    // may not be true when this one is written (after a backlog that is tens of seconds later).

    public function test_an_edit_in_the_gap_blocks_the_write(): void
    {
        [$user, $group, $message] = $this->makeApprovable();
        DB::table('messages')->where('id', $message->id)->update(['editedat' => now()]);

        $this->assertFalse($this->writeSelectedRow($message, $group, $user));

        $this->assertNothingPublished($message->id, $group->id, MessageGroup::COLLECTION_PENDING);
    }

    public function test_a_hold_verdict_in_the_gap_blocks_the_write(): void
    {
        [$user, $group, $message] = $this->makeApprovable();
        DB::table('messages_automod')->where('msgid', $message->id)
            ->update(['verdict' => MessageAutomod::VERDICT_HOLD, 'end_node' => 'HOLD_MONEY']);

        $this->assertFalse($this->writeSelectedRow($message, $group, $user));

        $this->assertNothingPublished($message->id, $group->id, MessageGroup::COLLECTION_PENDING);
    }

    public function test_a_decision_withdrawn_in_the_gap_blocks_the_write(): void
    {
        [$user, $group, $message] = $this->makeApprovable();
        DB::table('messages_automod')->where('msgid', $message->id)->delete();

        $this->assertFalse($this->writeSelectedRow($message, $group, $user));

        $this->assertNothingPublished($message->id, $group->id, MessageGroup::COLLECTION_PENDING);
    }

    public function test_a_content_check_re_run_in_the_gap_blocks_the_write(): void
    {
        [$user, $group, $message] = $this->makeApprovable();
        DB::table('messages_groups')->where('msgid', $message->id)->where('groupid', $group->id)
            ->update(['contentcheck_checked_at' => now()]);

        $this->assertFalse($this->writeSelectedRow($message, $group, $user));

        $this->assertNothingPublished($message->id, $group->id, MessageGroup::COLLECTION_PENDING);
    }

    public function test_a_quality_sample_stamped_in_the_gap_blocks_the_write(): void
    {
        [$user, $group, $message] = $this->makeApprovable();
        DB::table('messages_groups')->where('msgid', $message->id)->where('groupid', $group->id)
            ->update(['quality_sample' => 1]);

        $this->assertFalse($this->writeSelectedRow($message, $group, $user));

        $this->assertNothingPublished($message->id, $group->id, MessageGroup::COLLECTION_PENDING);
    }

    public function test_a_spam_reason_or_message_hold_in_the_gap_blocks_the_write(): void
    {
        $arrangements = [
            'copy spamreason' => fn ($m, $g) => DB::table('messages_groups')->where('msgid', $m->id)->where('groupid', $g->id)->update(['spamreason' => 'reported']),
            'message spamreason' => fn ($m, $g) => DB::table('messages')->where('id', $m->id)->update(['spamreason' => 'reported']),
            'message hold' => fn ($m, $g) => DB::table('messages')->where('id', $m->id)->update(['heldby' => $this->createTestUser()->id]),
            'message deleted' => fn ($m, $g) => DB::table('messages')->where('id', $m->id)->update(['deleted' => now()]),
        ];

        foreach ($arrangements as $label => $arrange) {
            [$user, $group, $message] = $this->makeApprovable();
            $arrange($message, $group);

            $this->assertFalse($this->writeSelectedRow($message, $group, $user), $label);

            $this->assertNothingPublished($message->id, $group->id, MessageGroup::COLLECTION_PENDING);
        }
    }

    public function test_a_microvolunteer_reject_in_the_gap_blocks_the_write(): void
    {
        [$user, $group, $message] = $this->makeApprovable();
        DB::table('microactions')->insert([
            'userid' => $this->createTestUser()->id, 'msgid' => $message->id, 'actiontype' => 'CheckMessage',
            'result' => 'Reject', 'score_negative' => 1,
        ]);

        $this->assertFalse($this->writeSelectedRow($message, $group, $user));

        $this->assertNothingPublished($message->id, $group->id, MessageGroup::COLLECTION_PENDING);
    }

    // One-second resolution: a decision in the same second as an edit or the content check might
    // have read the text before it, so it is not published; messages:automod decides again.

    public function test_a_decision_in_the_same_second_as_an_edit_is_not_published(): void
    {
        $at = now()->subMinutes(25)->setMicroseconds(0);
        [$user, $group, $message] = $this->makeApprovable([
            'mg'      => ['contentcheck_checked_at' => $at->copy()->subMinute()],
            'automod' => ['created' => $at],
        ]);
        DB::table('messages')->where('id', $message->id)->update(['editedat' => $at]);

        $stats = $this->service->process();

        $this->assertSame(0, $stats['approved']);
        $this->assertStillPending($message->id, $group->id);
    }

    public function test_a_decision_in_the_same_second_as_the_content_check_is_not_published(): void
    {
        $at = now()->subMinutes(25)->setMicroseconds(0);
        [$user, $group, $message] = $this->makeApprovable([
            'mg'      => ['contentcheck_checked_at' => $at],
            'automod' => ['created' => $at],
        ]);

        $stats = $this->service->process();

        $this->assertSame(0, $stats['approved']);
        $this->assertStillPending($message->id, $group->id);
    }

    // A shadow decision was never a promise. Moving a community from shadow to approve must not
    // publish the old shadow approves the moment it flips: the wait starts again from a decision
    // made in approve mode.

    public function test_a_shadow_decision_is_never_published(): void
    {
        [$user, $group, $message] = $this->makeApprovable([
            'automod' => ['mode' => MessageAutomod::MODE_SHADOW, 'created' => now()->subMinutes(30)],
        ]);
        // The community is in approve mode now (setUp: master switch on, no shadow list).

        $stats = $this->service->process();

        $this->assertSame(0, $stats['approved'], 'a decision recorded in shadow mode is not due on the first tick of approve mode');
        $this->assertStillPending($message->id, $group->id);
    }

    public function test_a_shadow_decision_cannot_be_written_through_either(): void
    {
        [$user, $group, $message] = $this->makeApprovable([
            'automod' => ['mode' => MessageAutomod::MODE_SHADOW],
        ]);

        $this->assertFalse($this->writeSelectedRow($message, $group, $user));
        $this->assertNothingPublished($message->id, $group->id, MessageGroup::COLLECTION_PENDING);
    }

    // --- the cap on one run -----------------------------------------------------------------

    public function test_a_run_publishes_at_most_the_configured_number_of_posts(): void
    {
        config(['freegle.autoapprove.max_per_run' => 2]);
        $loki = new LokiSpy();
        $service = new AutoApproveCleanService($loki);
        $posts = [];
        for ($i = 0; $i < 3; $i++) {
            $posts[] = $this->makeApprovable();
        }

        $stats = $service->process();

        $this->assertSame(2, $stats['approved']);
        $this->assertSame(1, $stats['capped']);
        $approved = DB::table('messages_groups')
            ->whereIn('msgid', array_map(fn ($p) => $p[2]->id, $posts))
            ->where('collection', MessageGroup::COLLECTION_APPROVED)
            ->count();
        $this->assertSame(2, $approved, 'the rest wait for the next tick');
        $capped = $loki->eventsOf('autoapprove', 'capped');
        $this->assertCount(1, $capped);
        $this->assertSame(2, $capped[0]['context']['limit']);
        $this->assertSame(1, $capped[0]['context']['waiting']);
    }

    public function test_the_cap_defaults_to_twenty_and_a_typo_does_not_remove_it(): void
    {
        foreach ([null, '', 'abc', '0', '-3'] as $junk) {
            config(['freegle.autoapprove.max_per_run' => $junk]);
            $this->assertSame(20, $this->service->maxPerRun(), 'cap for ' . var_export($junk, true));
        }

        config(['freegle.autoapprove.max_per_run' => '5']);
        $this->assertSame(5, $this->service->maxPerRun());
    }

    // --- why did this go live: the published event says what decided and what it saw ----------

    public function test_the_published_event_carries_the_decision_and_the_text_it_covered(): void
    {
        $loki = new LokiSpy();
        $service = new AutoApproveCleanService($loki);
        [$user, $group, $message] = $this->makeApprovable([
            'automod' => ['chart_version' => '6', 'detail' => ['text_sha' => str_repeat('a', 64)]],
        ]);

        $service->process();

        $event = $loki->eventsOf('autoapprove', 'published')[0]['context'];
        $this->assertSame('approve', $event['verdict']);
        $this->assertSame('APPROVE', $event['end']);
        $this->assertSame('6', $event['chart_version']);
        $this->assertSame('approve', $event['mode']);
        $this->assertNotEmpty($event['decision_created']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $event['text_sha'], 'the live text at publish');
        $this->assertSame(str_repeat('a', 64), $event['decision_text_sha'], 'the text the chart read');
    }
}
