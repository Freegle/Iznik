<?php

namespace Tests\Unit\Services;

use App\Models\Group;
use App\Models\Membership;
use App\Models\MessageGroup;
use App\Services\ModNotifService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ModNotifServiceTest extends TestCase
{
    private ModNotifService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ModNotifService;
    }

    // ===================================================================
    // getPendingWork
    // ===================================================================

    public function test_pending_work_counts_pending_message(): void
    {
        $mod = $this->createTestUser();
        $group = $this->createTestGroup();
        $author = $this->createTestUser();

        // Create a pending message (collection = Pending, not held, not deleted)
        $message = $this->createTestMessage($author, $group);
        MessageGroup::where('msgid', $message->id)->update([
            'collection' => MessageGroup::COLLECTION_PENDING,
            'arrival' => now()->subHours(10),
        ]);

        $work = $this->service->getPendingWork($mod->id, $group->id, 0);

        $this->assertEquals(1, $work['Pending Messages']);
    }

    public function test_pending_work_respects_minage_filter(): void
    {
        $mod = $this->createTestUser();
        $group = $this->createTestGroup();
        $author = $this->createTestUser();

        $message = $this->createTestMessage($author, $group);
        // Message arrived only 1 hour ago
        MessageGroup::where('msgid', $message->id)->update([
            'collection' => MessageGroup::COLLECTION_PENDING,
            'arrival' => now()->subHours(1),
        ]);

        // minage = 4 hours: items must be 4+ hours old
        $work = $this->service->getPendingWork($mod->id, $group->id, 4);

        $this->assertEquals(0, $work['Pending Messages']);
    }

    public function test_pending_work_includes_old_message_when_minage_filter_applies(): void
    {
        $mod = $this->createTestUser();
        $group = $this->createTestGroup();
        $author = $this->createTestUser();

        $message = $this->createTestMessage($author, $group);
        // Message arrived 5 hours ago, minage = 4 hours
        MessageGroup::where('msgid', $message->id)->update([
            'collection' => MessageGroup::COLLECTION_PENDING,
            'arrival' => now()->subHours(5),
        ]);

        $work = $this->service->getPendingWork($mod->id, $group->id, 4);

        $this->assertEquals(1, $work['Pending Messages']);
    }

    public function test_pending_work_excludes_held_message(): void
    {
        $mod = $this->createTestUser();
        $group = $this->createTestGroup();
        $author = $this->createTestUser();
        $holder = $this->createTestUser();

        $message = $this->createTestMessage($author, $group, ['heldby' => $holder->id]);
        MessageGroup::where('msgid', $message->id)->update([
            'collection' => MessageGroup::COLLECTION_PENDING,
        ]);

        $work = $this->service->getPendingWork($mod->id, $group->id, 0);

        $this->assertEquals(0, $work['Pending Messages']);
    }

    public function test_pending_work_counts_members_to_review(): void
    {
        $mod = $this->createTestUser();
        $group = $this->createTestGroup();
        $member = $this->createTestUser();

        $this->createMembership($member, $group, [
            'reviewrequestedat' => now()->subHours(2),
            'reviewedat' => null,
        ]);

        $work = $this->service->getPendingWork($mod->id, $group->id, 0);

        $this->assertEquals(1, $work['Members to Review']);
    }

    public function test_pending_work_excludes_recently_reviewed_member(): void
    {
        $mod = $this->createTestUser();
        $group = $this->createTestGroup();
        $member = $this->createTestUser();

        $this->createMembership($member, $group, [
            'reviewrequestedat' => now()->subDays(5),
            'reviewedat' => now()->subDays(1), // Reviewed recently
        ]);

        $work = $this->service->getPendingWork($mod->id, $group->id, 0);

        $this->assertEquals(0, $work['Members to Review']);
    }

    // ===================================================================
    // shouldSend / recordSent
    // ===================================================================

    public function test_should_send_when_no_previous_record(): void
    {
        $mod = $this->createTestUser();
        $this->assertTrue($this->service->shouldSend($mod->id, 'Some summary'));
    }

    public function test_should_send_when_summary_changed(): void
    {
        $mod = $this->createTestUser();

        DB::table('modnotifs')->insert([
            'userid' => $mod->id,
            'data' => 'Old summary',
            'timestamp' => now()->subHours(1),
        ]);

        $this->assertTrue($this->service->shouldSend($mod->id, 'New summary'));
    }

    public function test_should_not_send_when_summary_same_and_within_24h(): void
    {
        $mod = $this->createTestUser();
        $summary = "There's stuff to do.\r\n";

        DB::table('modnotifs')->insert([
            'userid' => $mod->id,
            'data' => $summary,
            'timestamp' => now()->subHours(12),
        ]);

        $this->assertFalse($this->service->shouldSend($mod->id, $summary));
    }

    public function test_should_send_when_summary_same_but_over_24h_old(): void
    {
        $mod = $this->createTestUser();
        $summary = "There's stuff to do.\r\n";

        DB::table('modnotifs')->insert([
            'userid' => $mod->id,
            'data' => $summary,
            'timestamp' => now()->subHours(25),
        ]);

        $this->assertTrue($this->service->shouldSend($mod->id, $summary));
    }

    public function test_record_sent_inserts_new_record(): void
    {
        $mod = $this->createTestUser();
        $summary = "Some work\r\n";

        $this->service->recordSent($mod->id, $summary);

        $record = DB::table('modnotifs')->where('userid', $mod->id)->first();
        $this->assertNotNull($record);
        $this->assertEquals($summary, $record->data);
    }

    public function test_record_sent_updates_existing_record(): void
    {
        $mod = $this->createTestUser();

        DB::table('modnotifs')->insert([
            'userid' => $mod->id,
            'data' => 'Old summary',
            'timestamp' => now()->subHours(12),
        ]);

        $this->service->recordSent($mod->id, 'New summary');

        $record = DB::table('modnotifs')->where('userid', $mod->id)->first();
        $this->assertEquals('New summary', $record->data);
    }

    // ===================================================================
    // isModRecentlyActive
    // ===================================================================

    public function test_mod_recently_active_returns_true_for_recent_activity(): void
    {
        $mod = $this->createTestUser();
        $group = $this->createTestGroup();
        $author = $this->createTestUser();

        $message = $this->createTestMessage($author, $group);
        MessageGroup::where('msgid', $message->id)->update([
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'approvedby' => $mod->id,
            'arrival' => now()->subDays(5),
        ]);

        $this->assertTrue($this->service->isModRecentlyActive($mod->id));
    }

    public function test_mod_recently_active_returns_false_for_no_activity(): void
    {
        $mod = $this->createTestUser();
        $this->assertFalse($this->service->isModRecentlyActive($mod->id));
    }

    // ===================================================================
    // buildTextSummary
    // ===================================================================

    public function test_build_text_summary_includes_group_work(): void
    {
        $groupWork = [
            'TestGroup' => ['Pending Messages' => 3, 'Members to Review' => 1],
        ];

        $text = $this->service->buildTextSummary($groupWork, 0, 'https://modtools.org/modtools/settings');

        $this->assertStringContainsString('TestGroup', $text);
        $this->assertStringContainsString('Pending Messages: 3', $text);
        $this->assertStringContainsString('Members to Review: 1', $text);
    }

    public function test_build_text_summary_includes_chat_review(): void
    {
        $text = $this->service->buildTextSummary([], 5, 'https://modtools.org/modtools/settings');

        $this->assertStringContainsString('5 chat messages to review', $text);
    }

    public function test_build_text_summary_includes_settings_url(): void
    {
        $text = $this->service->buildTextSummary([], 0, 'https://modtools.org/modtools/settings');

        $this->assertStringContainsString('https://modtools.org/modtools/settings', $text);
    }

    // ===================================================================
    // getModSettings
    // ===================================================================

    public function test_get_mod_settings_returns_default_for_active_mod(): void
    {
        $mod = $this->createTestUser();
        $group = $this->createTestGroup();
        $membership = $this->createMembership($mod, $group, ['role' => Membership::ROLE_MODERATOR]);

        $settings = $this->service->getModSettings($mod->id, $membership, true);

        $this->assertEquals(4, $settings['minage']);
    }

    public function test_get_mod_settings_returns_backup_threshold_for_inactive_mod(): void
    {
        $mod = $this->createTestUser();
        $group = $this->createTestGroup();
        $membership = $this->createMembership($mod, $group, ['role' => Membership::ROLE_MODERATOR]);

        $settings = $this->service->getModSettings($mod->id, $membership, false);

        $this->assertEquals(12, $settings['minage']);
    }

    public function test_get_mod_settings_respects_user_custom_threshold(): void
    {
        $mod = $this->createTestUser(['settings' => ['modnotifs' => 8]]);
        $group = $this->createTestGroup();
        $membership = $this->createMembership($mod, $group, ['role' => Membership::ROLE_MODERATOR]);

        $settings = $this->service->getModSettings($mod->id, $membership, true);

        $this->assertEquals(8, $settings['minage']);
    }

    public function test_get_mod_settings_returns_negative_when_notifications_disabled(): void
    {
        $mod = $this->createTestUser(['settings' => ['modnotifs' => -1]]);
        $group = $this->createTestGroup();
        $membership = $this->createMembership($mod, $group, ['role' => Membership::ROLE_MODERATOR]);

        $settings = $this->service->getModSettings($mod->id, $membership, true);

        $this->assertEquals(-1, $settings['minage']);
    }

    // ===================================================================
    // getChatReviewCount
    // ===================================================================

    public function test_get_chat_review_count_returns_count_of_pending_review_messages(): void
    {
        $mod = $this->createTestUser();
        $member = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($mod, $group, ['role' => Membership::ROLE_MODERATOR]);
        $this->createMembership($member, $group);

        $room = $this->createTestChatRoom($mod, $member);

        $this->createTestChatMessage($room, $mod, [
            'reviewrequired' => 1,
            'reviewrejected' => 0,
            'date' => now()->subHours(10),
        ]);

        $count = $this->service->getChatReviewCount($mod->id, 0);

        $this->assertEquals(1, $count);
    }

    public function test_get_chat_review_count_ignores_chats_outside_mod_groups(): void
    {
        // Bug fix: previously this returned a global count across all of Freegle,
        // so mods were notified about chats they couldn't see in ModTools.
        $mod = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($mod, $group, ['role' => Membership::ROLE_MODERATOR]);

        $author = $this->createTestUser();
        $stranger = $this->createTestUser();
        $room = $this->createTestChatRoom($author, $stranger);

        $this->createTestChatMessage($room, $author, [
            'reviewrequired' => 1,
            'reviewrejected' => 0,
            'date' => now()->subHours(10),
        ]);

        $count = $this->service->getChatReviewCount($mod->id, 0);

        $this->assertEquals(0, $count);
    }

    public function test_get_chat_review_count_excludes_already_held_messages(): void
    {
        $mod = $this->createTestUser();
        $member = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($mod, $group, ['role' => Membership::ROLE_MODERATOR]);
        $this->createMembership($member, $group);

        $room = $this->createTestChatRoom($mod, $member);
        $msg = $this->createTestChatMessage($room, $mod, [
            'reviewrequired' => 1,
            'reviewrejected' => 0,
            'date' => now()->subHours(10),
        ]);

        // Mark the message as held by some mod
        DB::table('chat_messages_held')->insert([
            'msgid' => $msg->id,
            'userid' => $mod->id,
            'timestamp' => now(),
        ]);

        $count = $this->service->getChatReviewCount($mod->id, 0);

        $this->assertEquals(0, $count);
    }

    public function test_get_chat_review_count_excludes_reviewrejected_chats(): void
    {
        $mod = $this->createTestUser();
        $member = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($mod, $group, ['role' => Membership::ROLE_MODERATOR]);
        $this->createMembership($member, $group);

        $room = $this->createTestChatRoom($mod, $member);

        $this->createTestChatMessage($room, $mod, [
            'reviewrequired' => 1,
            'reviewrejected' => 1,
            'date' => now()->subHours(10),
        ]);

        $count = $this->service->getChatReviewCount($mod->id, 0);

        $this->assertEquals(0, $count);
    }

    public function test_get_chat_review_count_skips_backup_mods(): void
    {
        // Backup mods (settings.active = false) don't get the chat-review queue.
        $mod = $this->createTestUser();
        $member = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($mod, $group, [
            'role' => Membership::ROLE_MODERATOR,
            'settings' => ['active' => false],
        ]);
        $this->createMembership($member, $group);

        $room = $this->createTestChatRoom($mod, $member);
        $this->createTestChatMessage($room, $mod, [
            'reviewrequired' => 1,
            'reviewrejected' => 0,
            'date' => now()->subHours(10),
        ]);

        $count = $this->service->getChatReviewCount($mod->id, 0);

        $this->assertEquals(0, $count);
    }

    public function test_get_chat_review_count_skips_backup_mod_via_legacy_showmessages(): void
    {
        // V1 falls back to legacy showmessages when active is absent. Old
        // memberships may have showmessages = false and no active key.
        $mod = $this->createTestUser();
        $member = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($mod, $group, [
            'role' => Membership::ROLE_MODERATOR,
            'settings' => ['showmessages' => false],
        ]);
        $this->createMembership($member, $group);

        $room = $this->createTestChatRoom($mod, $member);
        $this->createTestChatMessage($room, $mod, [
            'reviewrequired' => 1,
            'reviewrejected' => 0,
            'date' => now()->subHours(10),
        ]);

        $count = $this->service->getChatReviewCount($mod->id, 0);

        $this->assertEquals(0, $count);
    }

    public function test_get_chat_review_count_counts_active_mod_via_legacy_showmessages(): void
    {
        // Sanity check: showmessages = true with no active key → active.
        $mod = $this->createTestUser();
        $member = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($mod, $group, [
            'role' => Membership::ROLE_MODERATOR,
            'settings' => ['showmessages' => true],
        ]);
        $this->createMembership($member, $group);

        $room = $this->createTestChatRoom($mod, $member);
        $this->createTestChatMessage($room, $mod, [
            'reviewrequired' => 1,
            'reviewrejected' => 0,
            'date' => now()->subHours(10),
        ]);

        $count = $this->service->getChatReviewCount($mod->id, 0);

        $this->assertEquals(1, $count);
    }

    public function test_get_chat_review_count_respects_minage_filter(): void
    {
        $mod = $this->createTestUser();
        $member = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($mod, $group, ['role' => Membership::ROLE_MODERATOR]);
        $this->createMembership($member, $group);

        $room = $this->createTestChatRoom($mod, $member);
        // Message only 1 hour old, minage = 4 → should not count
        $this->createTestChatMessage($room, $mod, [
            'reviewrequired' => 1,
            'reviewrejected' => 0,
            'date' => now()->subHours(1),
        ]);

        $count = $this->service->getChatReviewCount($mod->id, 4);

        $this->assertEquals(0, $count);
    }

    // ===================================================================
    // buildHtmlSummary
    // ===================================================================

    public function test_build_html_summary_includes_group_work(): void
    {
        $groupWork = [
            'TestGroup' => ['Pending Messages' => 3, 'Members to Review' => 1],
        ];

        $html = $this->service->buildHtmlSummary($groupWork, 0);

        $this->assertStringContainsString('TestGroup', $html);
        $this->assertStringContainsString('Pending Messages', $html);
        $this->assertStringContainsString('<b>3</b>', $html);
    }

    public function test_build_html_summary_includes_chat_review(): void
    {
        $html = $this->service->buildHtmlSummary([], 5);

        $this->assertStringContainsString('5', $html);
        $this->assertStringContainsString('chat message', $html);
    }

    public function test_build_html_summary_is_empty_with_no_work(): void
    {
        $html = $this->service->buildHtmlSummary([], 0);

        $this->assertSame('', $html);
    }

    // ===================================================================
    // isModRecentlyActive (additional edge cases)
    // ===================================================================

    public function test_mod_recently_active_returns_false_for_activity_over_90_days_ago(): void
    {
        $mod = $this->createTestUser();
        $group = $this->createTestGroup();
        $author = $this->createTestUser();

        $message = $this->createTestMessage($author, $group);
        MessageGroup::where('msgid', $message->id)->update([
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'approvedby' => $mod->id,
            'arrival' => now()->subDays(91),
        ]);

        $this->assertFalse($this->service->isModRecentlyActive($mod->id));
    }

    // ===================================================================
    // getNotificationsToSend
    // ===================================================================

    public function test_get_notifications_returns_empty_when_mod_has_no_recent_activity(): void
    {
        $mod = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($mod, $group, ['role' => Membership::ROLE_MODERATOR]);

        // No messages approved by this mod → isModRecentlyActive returns false → skipped
        $result = $this->service->getNotificationsToSend();

        $modIds = array_column($result, 'user_id');
        $this->assertNotContains($mod->id, $modIds);
    }

    public function test_get_notifications_returns_notification_for_mod_with_pending_work(): void
    {
        $mod = $this->createTestUser();
        $group = $this->createTestGroup();
        $author = $this->createTestUser();

        $this->createMembership($mod, $group, ['role' => Membership::ROLE_MODERATOR]);

        // Record recent activity for the mod
        $message = $this->createTestMessage($author, $group);
        MessageGroup::where('msgid', $message->id)->update([
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'approvedby' => $mod->id,
            'arrival' => now()->subDays(1),
        ]);

        // Add a pending message requiring moderation in this group
        $pending = $this->createTestMessage($author, $group);
        MessageGroup::where('msgid', $pending->id)->update([
            'collection' => MessageGroup::COLLECTION_PENDING,
            'arrival' => now()->subDays(2),
        ]);

        $result = $this->service->getNotificationsToSend();

        $modIds = array_column($result, 'user_id');
        $this->assertContains($mod->id, $modIds);
    }

    public function test_chat_review_not_multiplied_for_mod_on_multiple_groups(): void
    {
        // Regression: getChatReviewCount used += in a per-group loop, multiplying
        // the count by the number of groups the mod belonged to. A mod on N groups
        // with 1 chat to review would be told they had N to review.
        $mod = $this->createTestUser();
        $group1 = $this->createTestGroup();
        $group2 = $this->createTestGroup();
        $author = $this->createTestUser();
        $member = $this->createTestUser();

        $this->createMembership($mod, $group1, ['role' => Membership::ROLE_MODERATOR]);
        $this->createMembership($mod, $group2, ['role' => Membership::ROLE_MODERATOR]);
        // Recipient must be on one of the mod's groups for the chat to count
        $this->createMembership($member, $group1);

        // Record recent activity so isModRecentlyActive() passes
        foreach ([$group1, $group2] as $group) {
            $msg = $this->createTestMessage($author, $group);
            MessageGroup::where('msgid', $msg->id)->update([
                'collection' => MessageGroup::COLLECTION_APPROVED,
                'approvedby' => $mod->id,
                'arrival' => now()->subDays(1),
            ]);
        }

        // One pending message per group so both groups contribute non-zero work
        foreach ([$group1, $group2] as $group) {
            $pending = $this->createTestMessage($author, $group);
            MessageGroup::where('msgid', $pending->id)->update([
                'collection' => MessageGroup::COLLECTION_PENDING,
                'arrival' => now()->subDays(2),
            ]);
        }

        // One chat message flagged for review with recipient on group1
        $room = $this->createTestChatRoom($author, $member);
        $this->createTestChatMessage($room, $author, [
            'reviewrequired' => 1,
            'reviewrejected' => 0,
            'date' => now()->subHours(10),
        ]);

        // 2 pending messages (one per group) + 1 chat review = 3 total.
        // The buggy code would count the chat twice (once per group) → 4.
        $result = $this->service->getNotificationsToSend();
        $notif = collect($result)->firstWhere('user_id', $mod->id);

        $this->assertNotNull($notif, 'Mod should receive a notification');
        $this->assertEquals(3, $notif['total'],
            'Total should be 2 pending + 1 chat review; chat review must not be counted once per group');
    }

    public function test_pending_work_excludes_messages_from_deleted_users(): void
    {
        // ModTools UI hides messages from deleted users; the notification count
        // must match or mods chase phantom messages.
        $mod = $this->createTestUser();
        $group = $this->createTestGroup();
        $deletedAuthor = $this->createTestUser(['deleted' => now()]);

        $message = $this->createTestMessage($deletedAuthor, $group);
        MessageGroup::where('msgid', $message->id)->update([
            'collection' => MessageGroup::COLLECTION_PENDING,
            'arrival' => now()->subHours(10),
        ]);

        $work = $this->service->getPendingWork($mod->id, $group->id, 0);

        $this->assertEquals(0, $work['Pending Messages']);
    }

    public function test_get_notifications_skips_mod_with_notifications_disabled(): void
    {
        // Settings on the User (not membership) control minage; -1 = disabled
        $mod = $this->createTestUser(['settings' => ['modnotifs' => -1]]);
        $group = $this->createTestGroup();
        $author = $this->createTestUser();

        $this->createMembership($mod, $group, ['role' => Membership::ROLE_MODERATOR]);

        // Record recent activity so isModRecentlyActive() passes
        $message = $this->createTestMessage($author, $group);
        MessageGroup::where('msgid', $message->id)->update([
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'approvedby' => $mod->id,
            'arrival' => now()->subDays(1),
        ]);

        $result = $this->service->getNotificationsToSend();

        $modIds = array_column($result, 'user_id');
        $this->assertNotContains($mod->id, $modIds);
    }
}
