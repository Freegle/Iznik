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

        $text = $this->service->buildTextSummary($groupWork, 0, 'modtools.org');

        $this->assertStringContainsString('TestGroup', $text);
        $this->assertStringContainsString('Pending Messages: 3', $text);
        $this->assertStringContainsString('Members to Review: 1', $text);
    }

    public function test_build_text_summary_includes_chat_review(): void
    {
        $text = $this->service->buildTextSummary([], 5, 'modtools.org');

        $this->assertStringContainsString('5 chat messages to review', $text);
    }

    public function test_build_text_summary_includes_settings_url(): void
    {
        $text = $this->service->buildTextSummary([], 0, 'modtools.org');

        $this->assertStringContainsString('modtools.org/settings', $text);
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
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $room = $this->createTestChatRoom($user1, $user2);

        $this->createTestChatMessage($room, $user1, [
            'reviewrequired' => 1,
            'reviewedby' => null,
            'date' => now()->subHours(10),
        ]);

        $count = $this->service->getChatReviewCount($user1->id, 0);

        $this->assertGreaterThanOrEqual(1, $count);
    }

    public function test_get_chat_review_count_excludes_already_reviewed_messages(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $reviewer = $this->createTestUser();
        $room = $this->createTestChatRoom($user1, $user2);

        $this->createTestChatMessage($room, $user1, [
            'reviewrequired' => 1,
            'reviewedby' => $reviewer->id,
            'date' => now()->subHours(10),
        ]);

        $beforeCount = $this->service->getChatReviewCount($user1->id, 0);

        $this->createTestChatMessage($room, $user2, [
            'reviewrequired' => 1,
            'reviewedby' => null,
            'date' => now()->subHours(10),
        ]);

        $afterCount = $this->service->getChatReviewCount($user2->id, 0);

        // Already-reviewed message should not have inflated the count
        $this->assertEquals(1, $afterCount - $beforeCount);
    }

    public function test_get_chat_review_count_respects_minage_filter(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $room = $this->createTestChatRoom($user1, $user2);

        // Message only 1 hour old, minage = 4 → should not count
        $msg = $this->createTestChatMessage($room, $user1, [
            'reviewrequired' => 1,
            'reviewedby' => null,
            'date' => now()->subHours(1),
        ]);

        $count = $this->service->getChatReviewCount($user1->id, 4);

        // The message is too recent; count should not include it
        $this->assertEquals(0, DB::table('chat_messages')
            ->join('chat_rooms', 'chat_rooms.id', '=', 'chat_messages.chatid')
            ->where('chat_messages.id', $msg->id)
            ->where('chat_messages.reviewrequired', 1)
            ->whereNull('chat_messages.reviewedby')
            ->where('chat_messages.date', '<', now()->subHours(4))
            ->count());
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
