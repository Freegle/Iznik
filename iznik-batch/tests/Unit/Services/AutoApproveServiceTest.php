<?php

namespace Tests\Unit\Services;

use App\Models\Group;
use App\Models\Message;
use App\Models\MessageGroup;
use App\Models\MessageOutcome;
use App\Services\AutoApproveService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AutoApproveServiceTest extends TestCase
{
    protected AutoApproveService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AutoApproveService();
    }

    public function test_stats_structure(): void
    {
        $stats = $this->service->process();

        $this->assertArrayHasKey('approved', $stats);
        $this->assertArrayHasKey('skipped', $stats);
        $this->assertArrayHasKey('errors', $stats);
    }

    public function test_approves_message_pending_over_48_hours(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        // Membership added 72 hours ago (exceeds 48h threshold).
        $this->createMembership($user, $group, [
            'added' => now()->subHours(72),
        ]);

        $message = $this->createTestMessage($user, $group);

        // Set message to pending and arrival 49 hours ago.
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

        // Verify messages_groups updated to Approved.
        $mg = DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $group->id)
            ->first();
        $this->assertEquals(MessageGroup::COLLECTION_APPROVED, $mg->collection);
        $this->assertNull($mg->approvedby);

        // Auto-approve logs only Autoapproved — not the generic Approved entry.
        $this->assertDatabaseMissing('logs', [
            'msgid' => $message->id,
            'groupid' => $group->id,
            'type' => 'Message',
            'subtype' => 'Approved',
        ]);
        $this->assertDatabaseHas('logs', [
            'msgid' => $message->id,
            'groupid' => $group->id,
            'type' => 'Message',
            'subtype' => 'Autoapproved',
        ]);
    }

    public function test_auto_approve_mails_newly_reached_members_of_a_done_rippling_post(): void
    {
        // A rippling post auto-approved on a group AFTER its reach has finished expanding
        // ('done') must still mail the now-reachable immediate members (the ExpandService tick
        // loop won't revisit a 'done' post) — closing the post-'done' approval gap.
        config(['freegle.digest.immediate_allowlist' => '*']);

        $poster = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($poster, $group, ['added' => now()->subHours(72)]);
        $member = $this->createTestUser();
        $this->createMembership($member, $group, ['added' => now()->subHours(72)]); // immediate by default
        $member->settings = ['mylocation' => ['lat' => 51.5, 'lng' => -0.1]];
        $member->save();

        $message = $this->createTestMessage($poster, $group);
        DB::table('messages_groups')->where('msgid', $message->id)->where('groupid', $group->id)->update([
            'collection' => MessageGroup::COLLECTION_PENDING,
            'arrival' => now()->subHours(49),
            'contentcheck_checked_at' => now(),
        ]);
        // Reach (status 'done') covering the member's location.
        DB::statement(
            "INSERT INTO rippling_reach (msgid, lat, lng, polygon, arrival, mode, tick, total_ticks, "
            . "total_freeglers, max_drive_min, schedule, next_expansion_at, status, created_at, updated_at) "
            . "VALUES (?, 51.5, -0.1, ST_GeomFromText(?, 3857), NOW(), 'drive', 3, 3, 0, 30, NULL, NULL, 'done', NOW(), NOW())",
            [$message->id, 'POLYGON((-0.2 51.4,0.0 51.4,0.0 51.6,-0.2 51.6,-0.2 51.4))']
        );

        $this->service->process();

        $this->assertTrue(
            DB::table('rippling_reach_notified')->where('msgid', $message->id)->where('userid', $member->id)->exists(),
            'auto-approve mails a now-reachable immediate member of a done-reach rippling post'
        );
    }

    /**
     * The newly-reached reach mail must never go out for a post that has already been collected -
     * notifying people about a gone item. mailNewlyReachedForPost guards on the outcome directly.
     */
    public function test_mail_newly_reached_skips_taken_post(): void
    {
        config(['freegle.digest.immediate_allowlist' => '*']);

        $poster = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($poster, $group, ['added' => now()->subHours(72)]);
        $member = $this->createTestUser();
        $this->createMembership($member, $group, ['added' => now()->subHours(72)]);
        $member->settings = ['mylocation' => ['lat' => 51.5, 'lng' => -0.1]];
        $member->save();

        $message = $this->createTestMessage($poster, $group);
        DB::table('messages_groups')->where('msgid', $message->id)->where('groupid', $group->id)->update([
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now()->subHours(1),
        ]);
        DB::statement(
            "INSERT INTO rippling_reach (msgid, lat, lng, polygon, arrival, mode, tick, total_ticks, "
            . "total_freeglers, max_drive_min, schedule, next_expansion_at, status, created_at, updated_at) "
            . "VALUES (?, 51.5, -0.1, ST_GeomFromText(?, 3857), NOW(), 'drive', 3, 3, 0, 30, NULL, NULL, 'done', NOW(), NOW())",
            [$message->id, 'POLYGON((-0.2 51.4,0.0 51.4,0.0 51.6,-0.2 51.6,-0.2 51.4))']
        );
        // The item has been collected before the newly-reached mail runs.
        DB::table('messages_outcomes')->insert([
            'msgid' => $message->id, 'outcome' => 'Taken', 'timestamp' => now(),
        ]);

        $sent = app(\App\Services\UnifiedDigestService::class)->mailNewlyReachedForPost((int) $message->id);

        $this->assertEquals(0, $sent, 'a taken post is not mailed to newly-reached members');
        $this->assertFalse(
            DB::table('rippling_reach_notified')->where('msgid', $message->id)->exists(),
            'no reach notification recorded for a taken post'
        );
    }

    public function test_does_not_auto_approve_message_marked_spam_on_another_group(): void
    {
        // A message that is Pending (and otherwise eligible) on one group but
        // marked Spam on another must NOT be auto-approved on its Pending group
        // (Discourse #9654: spam surfaces in the Pending queue but is never
        // auto-sent by the 48h fallback).
        $user = $this->createTestUser();
        $groupA = $this->createTestGroup();
        $groupB = $this->createTestGroup();
        $this->createMembership($user, $groupA, ['added' => now()->subHours(72)]);
        $this->createMembership($user, $groupB, ['added' => now()->subHours(72)]);

        $message = $this->createTestMessage($user, $groupA);

        // Pending on group A — would be eligible on its own.
        DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $groupA->id)
            ->update([
                'collection' => MessageGroup::COLLECTION_PENDING,
                'arrival' => now()->subHours(49),
                'contentcheck_checked_at' => now(),
            ]);

        // Same message marked Spam on group B.
        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $groupB->id,
            'collection' => MessageGroup::COLLECTION_SPAM,
            'arrival' => now()->subHours(49),
        ]);

        $this->service->process();

        // The Pending row on group A must remain Pending — not auto-approved.
        $mg = DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $groupA->id)
            ->first();
        $this->assertEquals(
            MessageGroup::COLLECTION_PENDING,
            $mg->collection,
            'A message marked Spam on any group must not be auto-approved on its Pending groups'
        );
    }

    public function test_dry_run_does_not_modify_database(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, [
            'added' => now()->subHours(72),
        ]);

        $message = $this->createTestMessage($user, $group);

        DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $group->id)
            ->update([
                'collection' => MessageGroup::COLLECTION_PENDING,
                'arrival' => now()->subHours(49),
                'contentcheck_checked_at' => now(),
            ]);

        $stats = $this->service->process(dryRun: true);

        $this->assertGreaterThanOrEqual(1, $stats['approved']);

        // Message should still be pending.
        $this->assertDatabaseHas('messages_groups', [
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_PENDING,
        ]);

        // No log entries should exist.
        $this->assertDatabaseMissing('logs', [
            'msgid' => $message->id,
            'type' => 'Message',
            'subtype' => 'Autoapproved',
        ]);
    }

    public function test_skips_message_not_pending_long_enough(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, [
            'added' => now()->subHours(72),
        ]);

        $message = $this->createTestMessage($user, $group);

        // Set message to pending but only 24 hours ago (under 48h threshold).
        DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $group->id)
            ->update([
                'collection' => MessageGroup::COLLECTION_PENDING,
                'arrival' => now()->subHours(24),
                'contentcheck_checked_at' => now(),
            ]);

        $this->service->process();

        // Message should still be pending.
        $this->assertDatabaseHas('messages_groups', [
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_PENDING,
        ]);
    }

    public function test_skips_message_with_recent_logs(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, [
            'added' => now()->subHours(72),
        ]);

        $message = $this->createTestMessage($user, $group);

        DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $group->id)
            ->update([
                'collection' => MessageGroup::COLLECTION_PENDING,
                'arrival' => now()->subHours(49),
                'contentcheck_checked_at' => now(),
            ]);

        // Add a recent log entry (within 48 hours).
        DB::table('logs')->insert([
            'timestamp' => now()->subHours(1),
            'type' => 'Message',
            'subtype' => 'Hold',
            'msgid' => $message->id,
        ]);

        $this->service->process();

        // Message should still be pending (skipped due to recent logs).
        $this->assertDatabaseHas('messages_groups', [
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_PENDING,
        ]);
    }

    public function test_skips_closed_group(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup([
            'settings' => ['closed' => true],
        ]);
        $this->createMembership($user, $group, [
            'added' => now()->subHours(72),
        ]);

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

        // Message should still be pending (group is closed).
        $this->assertDatabaseHas('messages_groups', [
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_PENDING,
        ]);
    }

    public function test_skips_group_with_publish_false(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup([
            'settings' => ['publish' => false],
        ]);
        $this->createMembership($user, $group, [
            'added' => now()->subHours(72),
        ]);

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

        // Message should still be pending (group has publish=false).
        $this->assertDatabaseHas('messages_groups', [
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_PENDING,
        ]);
    }

    public function test_skips_group_with_autofunctionoverride(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup([
            'autofunctionoverride' => 1,
        ]);
        $this->createMembership($user, $group, [
            'added' => now()->subHours(72),
        ]);

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

        // Message should still be pending (autofunctionoverride).
        $this->assertDatabaseHas('messages_groups', [
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_PENDING,
        ]);
    }

    /**
     * A rippled-in post (messages_groups.rippled_in = 1) already Approved on its origin
     * group is fast-tracked on nearby groups after the short veto window — even though the
     * poster is NOT a member of the nearby group (the membership gate would block it, and
     * the 48h fallback would leave it Pending forever otherwise).
     */
    public function test_fast_tracks_rippled_in_post_already_approved_on_origin(): void
    {
        $user = $this->createTestUser();
        $originGroup = $this->createTestGroup();
        $nearbyGroup = $this->createTestGroup();
        // Member of their origin group only — NOT the nearby group its reach rippled into.
        $this->createMembership($user, $originGroup, ['added' => now()->subHours(72)]);

        $message = $this->createTestMessage($user, $originGroup);
        DB::table('messages_groups')
            ->where('msgid', $message->id)->where('groupid', $originGroup->id)
            ->update(['collection' => MessageGroup::COLLECTION_APPROVED, 'arrival' => now()->subHours(3)]);

        // Rippled into the nearby group 2h ago (past the 1h veto window), still Pending.
        DB::table('messages_groups')->insert([
            'msgid' => $message->id, 'groupid' => $nearbyGroup->id,
            'collection' => MessageGroup::COLLECTION_PENDING, 'arrival' => now()->subHours(2),
            'msgtype' => 'Offer', 'rippled_in' => 1,
        ]);

        $this->service->process();

        $mg = DB::table('messages_groups')
            ->where('msgid', $message->id)->where('groupid', $nearbyGroup->id)->first();
        $this->assertEquals(MessageGroup::COLLECTION_APPROVED, $mg->collection,
            'rippled-in post vetted on origin is fast-tracked past the membership gate');
    }

    /**
     * A rippled-in post is auto-approved after the veto window even when a recent logs row
     * exists for the message (e.g. the Approved/Autoapproved row created by ProcessBackgroundTasksCommand
     * or AutoApproveService when the post was approved on its origin group).
     *
     * Bug: the $recentLogs check in process() was applied to ALL candidates including rippled-in rows.
     * It found the origin-approval log (<48h old) and skipped auto-approval, keeping rippled-in posts
     * Pending for up to 48h instead of the 1h veto window — then approving them all at once, which is
     * what mods observed as "~30 posts disappeared suddenly" (Discourse 9812 post 3).
     */
    public function test_fast_tracks_rippled_in_despite_recent_origin_approval_log(): void
    {
        config(['freegle.ripple.rippled_in_pending_hours' => 1]);

        $user = $this->createTestUser();
        $originGroup = $this->createTestGroup();
        $nearbyGroup = $this->createTestGroup();
        $this->createMembership($user, $originGroup, ['added' => now()->subHours(72)]);

        $message = $this->createTestMessage($user, $originGroup);
        DB::table('messages_groups')
            ->where('msgid', $message->id)->where('groupid', $originGroup->id)
            ->update(['collection' => MessageGroup::COLLECTION_APPROVED, 'arrival' => now()->subHours(3)]);

        // Simulate the logs row that ProcessBackgroundTasksCommand inserts when a mod approves
        // (or AutoApproveService inserts as 'Autoapproved') on the origin group. This is always
        // present in production and is what the $recentLogs check finds.
        DB::table('logs')->insert([
            'timestamp' => now()->subHours(2),
            'type' => 'Message',
            'subtype' => 'Autoapproved',
            'msgid' => $message->id,
            'groupid' => $originGroup->id,
            'user' => $user->id,
        ]);

        // Rippled into the nearby group 90 minutes ago — past the 1h veto window.
        DB::table('messages_groups')->insert([
            'msgid' => $message->id, 'groupid' => $nearbyGroup->id,
            'collection' => MessageGroup::COLLECTION_PENDING, 'arrival' => now()->subMinutes(90),
            'msgtype' => 'Offer', 'rippled_in' => 1,
        ]);

        $this->service->process();

        $mg = DB::table('messages_groups')
            ->where('msgid', $message->id)->where('groupid', $nearbyGroup->id)->first();
        $this->assertEquals(MessageGroup::COLLECTION_APPROVED, $mg->collection,
            'rippled-in post must be auto-approved after veto window regardless of origin-approval logs');
    }

    /** Within the short veto window a rippled-in post stays Pending (mods can still reject). */
    public function test_holds_rippled_in_post_within_veto_window(): void
    {
        // A mod-veto window only exists when configured > 0 (default is now 0 = approve at
        // ripple-in). Set a 1h window so a just-rippled-in post is held within it.
        config(['freegle.ripple.rippled_in_pending_hours' => 1]);
        $user = $this->createTestUser();
        $originGroup = $this->createTestGroup();
        $nearbyGroup = $this->createTestGroup();
        $this->createMembership($user, $originGroup, ['added' => now()->subHours(72)]);

        $message = $this->createTestMessage($user, $originGroup);
        DB::table('messages_groups')
            ->where('msgid', $message->id)->where('groupid', $originGroup->id)
            ->update(['collection' => MessageGroup::COLLECTION_APPROVED, 'arrival' => now()->subHours(3)]);

        // Rippled in only 30 minutes ago — inside the veto window.
        DB::table('messages_groups')->insert([
            'msgid' => $message->id, 'groupid' => $nearbyGroup->id,
            'collection' => MessageGroup::COLLECTION_PENDING, 'arrival' => now()->subMinutes(30),
            'msgtype' => 'Offer', 'rippled_in' => 1,
        ]);

        $this->service->process();

        $this->assertDatabaseHas('messages_groups', [
            'msgid' => $message->id, 'groupid' => $nearbyGroup->id,
            'collection' => MessageGroup::COLLECTION_PENDING,
        ]);
    }

    /**
     * With ripple.rippled_in_pending_hours = 0 (experiment mode) a rippled-in post already
     * Approved on its origin auto-approves immediately, keeping moderation load off the
     * receiving groups during a reach experiment.
     */
    public function test_rippled_in_pending_hours_zero_auto_approves_immediately(): void
    {
        config(['freegle.ripple.rippled_in_pending_hours' => 0]);

        $user = $this->createTestUser();
        $originGroup = $this->createTestGroup();
        $nearbyGroup = $this->createTestGroup();
        $this->createMembership($user, $originGroup, ['added' => now()->subHours(72)]);

        $message = $this->createTestMessage($user, $originGroup);
        DB::table('messages_groups')
            ->where('msgid', $message->id)->where('groupid', $originGroup->id)
            ->update(['collection' => MessageGroup::COLLECTION_APPROVED, 'arrival' => now()->subHours(3)]);

        // Rippled in 2 minutes ago — inside the default 1h window, but 0h approves immediately.
        DB::table('messages_groups')->insert([
            'msgid' => $message->id, 'groupid' => $nearbyGroup->id,
            'collection' => MessageGroup::COLLECTION_PENDING, 'arrival' => now()->subMinutes(2),
            'msgtype' => 'Offer', 'rippled_in' => 1,
        ]);

        $this->service->process();

        $mg = DB::table('messages_groups')
            ->where('msgid', $message->id)->where('groupid', $nearbyGroup->id)->first();
        $this->assertEquals(MessageGroup::COLLECTION_APPROVED, $mg->collection,
            'rippled_in_pending_hours=0 auto-approves a just-rippled-in post immediately');
    }

    /** A rippled-in post NOT yet Approved on its origin group is never fast-tracked. */
    public function test_does_not_fast_track_rippled_in_when_origin_not_approved(): void
    {
        $user = $this->createTestUser();
        $originGroup = $this->createTestGroup();
        $nearbyGroup = $this->createTestGroup();
        $this->createMembership($user, $originGroup, ['added' => now()->subHours(72)]);

        $message = $this->createTestMessage($user, $originGroup);
        // Origin still Pending (recent) — not yet vetted.
        DB::table('messages_groups')
            ->where('msgid', $message->id)->where('groupid', $originGroup->id)
            ->update(['collection' => MessageGroup::COLLECTION_PENDING, 'arrival' => now()]);

        DB::table('messages_groups')->insert([
            'msgid' => $message->id, 'groupid' => $nearbyGroup->id,
            'collection' => MessageGroup::COLLECTION_PENDING, 'arrival' => now()->subHours(2),
            'msgtype' => 'Offer', 'rippled_in' => 1,
        ]);

        $this->service->process();

        $this->assertDatabaseHas('messages_groups', [
            'msgid' => $message->id, 'groupid' => $nearbyGroup->id,
            'collection' => MessageGroup::COLLECTION_PENDING,
        ]);
    }

    /**
     * A rippled-in post that has already been collected (a Taken/Received outcome exists) is
     * never auto-approved into the receiving group - approving it would re-list a gone item and
     * fire a "newly reached" mail. The take normally retires the pending rows, but a take via a
     * non-Go path leaves them, so this guard is the catch-all.
     */
    public function test_does_not_auto_approve_rippled_in_post_already_taken(): void
    {
        $user = $this->createTestUser();
        $originGroup = $this->createTestGroup();
        $nearbyGroup = $this->createTestGroup();
        $this->createMembership($user, $originGroup, ['added' => now()->subHours(72)]);

        $message = $this->createTestMessage($user, $originGroup);
        DB::table('messages_groups')
            ->where('msgid', $message->id)->where('groupid', $originGroup->id)
            ->update(['collection' => MessageGroup::COLLECTION_APPROVED, 'arrival' => now()->subHours(3)]);

        // Rippled into the nearby group 2h ago (past the 1h veto window), still Pending.
        DB::table('messages_groups')->insert([
            'msgid' => $message->id, 'groupid' => $nearbyGroup->id,
            'collection' => MessageGroup::COLLECTION_PENDING, 'arrival' => now()->subHours(2),
            'msgtype' => 'Offer', 'rippled_in' => 1,
        ]);

        // The item has been collected.
        DB::table('messages_outcomes')->insert([
            'msgid' => $message->id, 'outcome' => 'Taken', 'timestamp' => now(),
        ]);

        $this->service->process();

        $this->assertDatabaseHas('messages_groups', [
            'msgid' => $message->id, 'groupid' => $nearbyGroup->id,
            'collection' => MessageGroup::COLLECTION_PENDING,
        ]);
    }

    public function test_skips_new_member_under_48_hours(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        // Membership added only 24 hours ago.
        $this->createMembership($user, $group, [
            'added' => now()->subHours(24),
        ]);

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

        // Message should still be pending (member too new).
        $this->assertDatabaseHas('messages_groups', [
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_PENDING,
        ]);
    }

    public function test_records_ham_for_spam_message(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, [
            'added' => now()->subHours(72),
        ]);

        $message = $this->createTestMessage($user, $group);

        // Mark as spam type.
        DB::table('messages')->where('id', $message->id)->update(['spamtype' => 'Spam']);

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

        // Verify Ham was recorded in messages_spamham.
        $this->assertDatabaseHas('messages_spamham', [
            'msgid' => $message->id,
            'spamham' => 'Ham',
        ]);
    }

    public function test_skips_held_message(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, [
            'added' => now()->subHours(72),
        ]);

        $message = $this->createTestMessage($user, $group);

        // V1 checks messages_groups.heldby (per-group hold), not messages.heldby.
        DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $group->id)
            ->update([
                'collection' => MessageGroup::COLLECTION_PENDING,
                'arrival' => now()->subHours(49),
                'heldby' => $user->id,
                'contentcheck_checked_at' => now(),
            ]);

        $this->service->process();

        // Message should still be pending (held by a mod).
        $this->assertDatabaseHas('messages_groups', [
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_PENDING,
        ]);
    }

    public function test_skips_soft_deleted_message(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, [
            'added' => now()->subHours(72),
        ]);

        $message = $this->createTestMessage($user, $group);

        // User soft-deleted their message shortly after posting.
        DB::table('messages')->where('id', $message->id)->update(['deleted' => now()->subHours(47)]);

        DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $group->id)
            ->update([
                'collection' => MessageGroup::COLLECTION_PENDING,
                'arrival' => now()->subHours(49),
                'contentcheck_checked_at' => now(),
            ]);

        $this->service->process();

        // Soft-deleted messages must not be auto-approved — mods don't see them
        // in the queue, so an Autoapproved log would appear with no visible review.
        $this->assertDatabaseHas('messages_groups', [
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_PENDING,
        ]);
        $this->assertDatabaseMissing('logs', [
            'msgid' => $message->id,
            'type' => 'Message',
            'subtype' => 'Autoapproved',
        ]);
    }

    public function test_skips_messages_groups_deleted(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, [
            'added' => now()->subHours(72),
        ]);

        $message = $this->createTestMessage($user, $group);

        DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $group->id)
            ->update([
                'collection' => MessageGroup::COLLECTION_PENDING,
                'arrival' => now()->subHours(49),
                'deleted' => 1,
                'contentcheck_checked_at' => now(),
            ]);

        $this->service->process();

        $this->assertDatabaseMissing('logs', [
            'msgid' => $message->id,
            'type' => 'Message',
            'subtype' => 'Autoapproved',
        ]);
    }

    public function test_whitelists_subject_for_subject_used_for_different_groups(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, [
            'added' => now()->subHours(72),
        ]);

        $message = $this->createTestMessage($user, $group, [
            'subject' => '[TestGroup] OFFER: Sofa (Southend)',
        ]);

        // Mark with SubjectUsedForDifferentGroups spamtype.
        DB::table('messages')->where('id', $message->id)->update([
            'spamtype' => 'SubjectUsedForDifferentGroups',
        ]);

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

        // Verify subject was whitelisted (pruned: strips [group] and (location)).
        $this->assertDatabaseHas('spam_whitelist_subjects', [
            'subject' => AutoApproveService::getPrunedSubject('[TestGroup] OFFER: Sofa (Southend)'),
            'comment' => 'Marked as not spam',
        ]);

        // Also verify Ham was recorded.
        $this->assertDatabaseHas('messages_spamham', [
            'msgid' => $message->id,
            'spamham' => 'Ham',
        ]);
    }

    public function test_does_not_whitelist_subject_for_other_spamtypes(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, [
            'added' => now()->subHours(72),
        ]);

        $message = $this->createTestMessage($user, $group, [
            'subject' => 'OFFER: Something',
        ]);

        DB::table('messages')->where('id', $message->id)->update([
            'spamtype' => 'Spam',
        ]);

        DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $group->id)
            ->update([
                'collection' => MessageGroup::COLLECTION_PENDING,
                'arrival' => now()->subHours(49),
                'contentcheck_checked_at' => now(),
            ]);

        $this->service->process();

        // Should NOT whitelist subject for non-SubjectUsedForDifferentGroups spamtype.
        $this->assertDatabaseMissing('spam_whitelist_subjects', [
            'subject' => AutoApproveService::getPrunedSubject('OFFER: Something'),
        ]);

        // But Ham should still be recorded.
        $this->assertDatabaseHas('messages_spamham', [
            'msgid' => $message->id,
            'spamham' => 'Ham',
        ]);
    }

    public function test_multi_group_message_approved_independently(): void
    {
        $user = $this->createTestUser();
        $group1 = $this->createTestGroup();
        $group2 = $this->createTestGroup();
        $this->createMembership($user, $group1, [
            'added' => now()->subHours(72),
        ]);
        $this->createMembership($user, $group2, [
            'added' => now()->subHours(72),
        ]);

        $message = $this->createTestMessage($user, $group1);

        // Add message to second group too.
        DB::table('messages_groups')->insert([
            'msgid' => $message->id,
            'groupid' => $group2->id,
            'collection' => MessageGroup::COLLECTION_PENDING,
            'arrival' => now()->subHours(49),
            'contentcheck_checked_at' => now(),
        ]);

        // Group1: pending 49h (should approve). Group2: pending 49h (should approve).
        DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $group1->id)
            ->update([
                'collection' => MessageGroup::COLLECTION_PENDING,
                'arrival' => now()->subHours(49),
                'contentcheck_checked_at' => now(),
            ]);

        $stats = $this->service->process();

        $this->assertGreaterThanOrEqual(2, $stats['approved']);

        // Both groups should be approved.
        $this->assertDatabaseHas('messages_groups', [
            'msgid' => $message->id,
            'groupid' => $group1->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
        ]);
        $this->assertDatabaseHas('messages_groups', [
            'msgid' => $message->id,
            'groupid' => $group2->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
        ]);
    }

    public function test_multi_group_one_skipped_one_approved(): void
    {
        $user = $this->createTestUser();
        $group1 = $this->createTestGroup();
        $group2 = $this->createTestGroup([
            'settings' => ['closed' => true],
        ]);
        $this->createMembership($user, $group1, [
            'added' => now()->subHours(72),
        ]);
        $this->createMembership($user, $group2, [
            'added' => now()->subHours(72),
        ]);

        $message = $this->createTestMessage($user, $group1);

        // Add message to closed group too.
        DB::table('messages_groups')->insert([
            'msgid' => $message->id,
            'groupid' => $group2->id,
            'collection' => MessageGroup::COLLECTION_PENDING,
            'arrival' => now()->subHours(49),
            'contentcheck_checked_at' => now(),
        ]);

        DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $group1->id)
            ->update([
                'collection' => MessageGroup::COLLECTION_PENDING,
                'arrival' => now()->subHours(49),
                'contentcheck_checked_at' => now(),
            ]);

        $this->service->process();

        // Group1 should be approved (open group).
        $this->assertDatabaseHas('messages_groups', [
            'msgid' => $message->id,
            'groupid' => $group1->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
        ]);

        // Group2 should still be pending (closed group).
        $this->assertDatabaseHas('messages_groups', [
            'msgid' => $message->id,
            'groupid' => $group2->id,
            'collection' => MessageGroup::COLLECTION_PENDING,
        ]);
    }

    public function test_get_pruned_subject(): void
    {
        // Strip location in parentheses.
        $this->assertEquals('OFFER: Sofa', AutoApproveService::getPrunedSubject('OFFER: Sofa (Southend)'));

        // Strip group name in brackets.
        $this->assertEquals('OFFER: Table', AutoApproveService::getPrunedSubject('[Essex] OFFER: Table'));

        // Strip both.
        $pruned = AutoApproveService::getPrunedSubject('[Essex] OFFER: Sofa (Southend)');
        $this->assertEquals('OFFER: Sofa', $pruned);

        // No stripping needed.
        $this->assertEquals('OFFER: Chair', AutoApproveService::getPrunedSubject('OFFER: Chair'));
    }

    public function test_constants(): void
    {
        $this->assertEquals(48, AutoApproveService::PENDING_HOURS);
        $this->assertEquals(48, AutoApproveService::MEMBERSHIP_HOURS);
    }
}
