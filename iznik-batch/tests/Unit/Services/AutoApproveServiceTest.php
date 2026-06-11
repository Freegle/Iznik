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
