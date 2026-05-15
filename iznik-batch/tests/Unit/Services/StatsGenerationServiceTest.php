<?php

namespace Tests\Unit\Services;

use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\Membership;
use App\Models\Message;
use App\Services\StatsGenerationService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Covers the per-type SQL ported from V1 Stats::generate().
 * Each test seeds the minimum fixtures for one type and asserts the
 * matching row appears in `stats` for the given date with the expected count.
 */
class StatsGenerationServiceTest extends TestCase
{
    protected StatsGenerationService $service;

    protected string $date;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new StatsGenerationService();
        // Use a fixed date in the past so 30-day window doesn't accidentally
        // sweep up fixtures from other tests running on "today".
        $this->date = '2026-04-01';
    }

    private function assertStat(int $groupId, string $type, int $expected): void
    {
        $row = DB::table('stats')
            ->where('date', $this->date)
            ->where('groupid', $groupId)
            ->where('type', $type)
            ->first();

        $this->assertNotNull($row, "Expected `{$type}` stat row for date {$this->date}, group {$groupId}");
        $this->assertEquals($expected, $row->count, "`{$type}` count mismatch");
    }

    private function assertNoStat(int $groupId, string $type): void
    {
        $row = DB::table('stats')
            ->where('date', $this->date)
            ->where('groupid', $groupId)
            ->where('type', $type)
            ->first();
        $this->assertNull($row, "Did not expect a `{$type}` row to be written");
    }

    private function assertBreakdown(int $groupId, string $type, array $expected): void
    {
        $row = DB::table('stats')
            ->where('date', $this->date)
            ->where('groupid', $groupId)
            ->where('type', $type)
            ->first();
        $this->assertNotNull($row, "Expected `{$type}` breakdown row");
        $this->assertEquals($expected, json_decode($row->breakdown, true), "`{$type}` breakdown mismatch");
    }

    public function test_outcomes_counts_distinct_msgids_with_taken_or_received_outcome(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser();
        $msg1 = $this->createTestMessage($user, $group);
        $msg2 = $this->createTestMessage($user, $group);

        // Two outcomes for msg1 (only counted once due to DISTINCT) and one for msg2.
        DB::table('messages_outcomes')->insert([
            ['msgid' => $msg1->id, 'userid' => $user->id, 'outcome' => Message::OUTCOME_TAKEN, 'timestamp' => $this->date.' 10:00:00'],
            ['msgid' => $msg1->id, 'userid' => $user->id, 'outcome' => Message::OUTCOME_RECEIVED, 'timestamp' => $this->date.' 11:00:00'],
            ['msgid' => $msg2->id, 'userid' => $user->id, 'outcome' => Message::OUTCOME_TAKEN, 'timestamp' => $this->date.' 12:00:00'],
        ]);

        $this->service->generate($group->id, $this->date);

        $this->assertStat($group->id, StatsGenerationService::TYPE_OUTCOMES, 2);
    }

    public function test_approved_message_count_uses_arrival_date_and_collection(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser();

        // Two messages arriving on $date, one the day before.
        $this->createTestMessage($user, $group, ['arrival' => $this->date.' 09:00:00']);
        $this->createTestMessage($user, $group, ['arrival' => $this->date.' 15:30:00']);
        $this->createTestMessage($user, $group, ['arrival' => '2026-03-31 23:59:59']);

        $this->service->generate($group->id, $this->date);

        $this->assertStat($group->id, StatsGenerationService::TYPE_APPROVED_MESSAGE_COUNT, 2);
    }

    public function test_approved_member_count_is_cumulative_as_of_date(): void
    {
        $group = $this->createTestGroup();

        // Three members added before $date, one after.
        $u1 = $this->createTestUser();
        $u2 = $this->createTestUser();
        $u3 = $this->createTestUser();
        $u4 = $this->createTestUser();

        DB::table('memberships')->insert([
            ['userid' => $u1->id, 'groupid' => $group->id, 'collection' => Membership::COLLECTION_APPROVED, 'role' => 'Member', 'added' => '2026-01-01 10:00:00'],
            ['userid' => $u2->id, 'groupid' => $group->id, 'collection' => Membership::COLLECTION_APPROVED, 'role' => 'Member', 'added' => '2026-02-01 10:00:00'],
            ['userid' => $u3->id, 'groupid' => $group->id, 'collection' => Membership::COLLECTION_APPROVED, 'role' => 'Member', 'added' => $this->date.' 10:00:00'],
            // Joined after — not counted.
            ['userid' => $u4->id, 'groupid' => $group->id, 'collection' => Membership::COLLECTION_APPROVED, 'role' => 'Member', 'added' => '2026-04-02 10:00:00'],
        ]);

        $this->service->generate($group->id, $this->date);

        $this->assertStat($group->id, StatsGenerationService::TYPE_APPROVED_MEMBER_COUNT, 3);
    }

    public function test_spam_message_count_picks_up_classified_spam_logs(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser();

        DB::table('logs')->insert([
            ['user' => $user->id, 'type' => 'Message', 'subtype' => 'ClassifiedSpam', 'groupid' => $group->id, 'timestamp' => $this->date.' 10:00:00'],
            ['user' => $user->id, 'type' => 'Message', 'subtype' => 'ClassifiedSpam', 'groupid' => $group->id, 'timestamp' => $this->date.' 11:00:00'],
            // Wrong subtype — ignored.
            ['user' => $user->id, 'type' => 'Message', 'subtype' => 'Approved', 'groupid' => $group->id, 'timestamp' => $this->date.' 11:00:00'],
            // Wrong day — ignored.
            ['user' => $user->id, 'type' => 'Message', 'subtype' => 'ClassifiedSpam', 'groupid' => $group->id, 'timestamp' => '2026-04-02 10:00:00'],
        ]);

        $this->service->generate($group->id, $this->date);

        $this->assertStat($group->id, StatsGenerationService::TYPE_SPAM_MESSAGE_COUNT, 2);
    }

    public function test_support_queries_counts_user2mod_chat_rooms_created_on_date(): void
    {
        $group = $this->createTestGroup();
        $u1 = $this->createTestUser();
        $u2 = $this->createTestUser();

        DB::table('chat_rooms')->insert([
            ['chattype' => ChatRoom::TYPE_USER2MOD, 'user1' => $u1->id, 'groupid' => $group->id, 'created' => $this->date.' 10:00:00'],
            ['chattype' => ChatRoom::TYPE_USER2MOD, 'user1' => $u2->id, 'groupid' => $group->id, 'created' => $this->date.' 11:00:00'],
            // Wrong chattype.
            ['chattype' => ChatRoom::TYPE_USER2USER, 'user1' => $u1->id, 'user2' => $u2->id, 'groupid' => $group->id, 'created' => $this->date.' 12:00:00'],
        ]);

        $this->service->generate($group->id, $this->date);

        $this->assertStat($group->id, StatsGenerationService::TYPE_SUPPORTQUERIES_COUNT, 2);
    }

    public function test_feedback_happiness_counts_per_label(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser();
        $msgHappy1 = $this->createTestMessage($user, $group);
        $msgHappy2 = $this->createTestMessage($user, $group);
        $msgFine = $this->createTestMessage($user, $group);
        $msgUnhappy = $this->createTestMessage($user, $group);

        DB::table('messages_outcomes')->insert([
            ['msgid' => $msgHappy1->id, 'userid' => $user->id, 'outcome' => Message::OUTCOME_TAKEN, 'happiness' => 'Happy', 'timestamp' => $this->date.' 10:00:00'],
            ['msgid' => $msgHappy2->id, 'userid' => $user->id, 'outcome' => Message::OUTCOME_TAKEN, 'happiness' => 'Happy', 'timestamp' => $this->date.' 11:00:00'],
            ['msgid' => $msgFine->id, 'userid' => $user->id, 'outcome' => Message::OUTCOME_TAKEN, 'happiness' => 'Fine', 'timestamp' => $this->date.' 12:00:00'],
            ['msgid' => $msgUnhappy->id, 'userid' => $user->id, 'outcome' => Message::OUTCOME_TAKEN, 'happiness' => 'Unhappy', 'timestamp' => $this->date.' 13:00:00'],
        ]);

        $this->service->generate($group->id, $this->date);

        $this->assertStat($group->id, StatsGenerationService::TYPE_FEEDBACK_HAPPY, 2);
        $this->assertStat($group->id, StatsGenerationService::TYPE_FEEDBACK_FINE, 1);
        $this->assertStat($group->id, StatsGenerationService::TYPE_FEEDBACK_UNHAPPY, 1);
    }

    public function test_replies_counts_interested_chat_messages_for_groups_messages(): void
    {
        $group = $this->createTestGroup();
        $u1 = $this->createTestUser();
        $u2 = $this->createTestUser();
        $msg = $this->createTestMessage($u1, $group);
        $room = $this->createTestChatRoom($u1, $u2);

        // Two interested replies on $date for this group's message; one non-Interested ignored.
        $this->createTestChatMessage($room, $u2, [
            'type' => ChatMessage::TYPE_INTERESTED,
            'refmsgid' => $msg->id,
            'date' => $this->date.' 10:00:00',
        ]);
        $this->createTestChatMessage($room, $u2, [
            'type' => ChatMessage::TYPE_INTERESTED,
            'refmsgid' => $msg->id,
            'date' => $this->date.' 11:00:00',
        ]);
        $this->createTestChatMessage($room, $u2, [
            'type' => ChatMessage::TYPE_DEFAULT,
            'refmsgid' => $msg->id,
            'date' => $this->date.' 12:00:00',
        ]);

        $this->service->generate($group->id, $this->date);

        $this->assertStat($group->id, StatsGenerationService::TYPE_REPLIES, 2);
    }

    public function test_activity_is_approved_message_count_plus_replies(): void
    {
        $group = $this->createTestGroup();
        $u1 = $this->createTestUser();
        $u2 = $this->createTestUser();
        // 3 approved + 1 reply -> activity = 4.
        $this->createTestMessage($u1, $group, ['arrival' => $this->date.' 09:00:00']);
        $this->createTestMessage($u1, $group, ['arrival' => $this->date.' 10:00:00']);
        $msg = $this->createTestMessage($u1, $group, ['arrival' => $this->date.' 11:00:00']);
        $room = $this->createTestChatRoom($u1, $u2);
        $this->createTestChatMessage($room, $u2, [
            'type' => ChatMessage::TYPE_INTERESTED,
            'refmsgid' => $msg->id,
            'date' => $this->date.' 12:00:00',
        ]);

        $this->service->generate($group->id, $this->date);

        $this->assertStat($group->id, StatsGenerationService::TYPE_ACTIVITY, 4);
    }

    public function test_post_method_breakdown_is_30_day_window_histogram(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser();

        // Three within the 30-day window ending tomorrow-of-$date:
        $this->createTestMessage($user, $group, ['arrival' => $this->date.' 09:00:00', 'sourceheader' => 'Web']);
        $this->createTestMessage($user, $group, ['arrival' => '2026-03-15 09:00:00', 'sourceheader' => 'Web']);
        $this->createTestMessage($user, $group, ['arrival' => '2026-03-20 09:00:00', 'sourceheader' => 'Platform']);
        // Outside the 30-day window — not counted.
        $this->createTestMessage($user, $group, ['arrival' => '2026-02-15 09:00:00', 'sourceheader' => 'Email']);

        $this->service->generate($group->id, $this->date);

        $this->assertBreakdown($group->id, StatsGenerationService::TYPE_POST_METHOD_BREAKDOWN, [
            'Platform' => 1,
            'Web' => 2,
        ]);
    }

    public function test_message_breakdown_is_30_day_window_histogram_by_type(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser();
        $this->createTestMessage($user, $group, ['arrival' => $this->date.' 09:00:00', 'type' => Message::TYPE_OFFER]);
        $this->createTestMessage($user, $group, ['arrival' => '2026-03-15 09:00:00', 'type' => Message::TYPE_OFFER]);
        $this->createTestMessage($user, $group, ['arrival' => '2026-03-20 09:00:00', 'type' => Message::TYPE_WANTED]);

        $this->service->generate($group->id, $this->date);

        $row = DB::table('stats')
            ->where('date', $this->date)
            ->where('groupid', $group->id)
            ->where('type', StatsGenerationService::TYPE_MESSAGE_BREAKDOWN)
            ->first();
        $this->assertNotNull($row);
        $breakdown = json_decode($row->breakdown, true);
        $this->assertEquals(2, $breakdown[Message::TYPE_OFFER]);
        $this->assertEquals(1, $breakdown[Message::TYPE_WANTED]);
    }

    public function test_searches_count_matches_when_groupid_appears_in_csv(): void
    {
        $group = $this->createTestGroup();
        $otherGroup = $this->createTestGroup();

        DB::table('search_history')->insert([
            ['date' => $this->date.' 10:00:00', 'term' => 'sofa', 'groups' => (string) $group->id],
            ['date' => $this->date.' 11:00:00', 'term' => 'chair', 'groups' => $otherGroup->id.','.$group->id.',999'],
            ['date' => $this->date.' 12:00:00', 'term' => 'table', 'groups' => (string) $otherGroup->id],
            // Wrong date — ignored.
            ['date' => '2026-04-02 10:00:00', 'term' => 'desk', 'groups' => (string) $group->id],
        ]);

        $this->service->generate($group->id, $this->date);

        $this->assertStat($group->id, StatsGenerationService::TYPE_SEARCHES, 2);
    }

    public function test_zero_counts_are_not_written(): void
    {
        // Empty group, no activity → V1 setCount skipped 0-valued rows; preserve.
        $group = $this->createTestGroup();
        $this->service->generate($group->id, $this->date);
        $this->assertNoStat($group->id, StatsGenerationService::TYPE_APPROVED_MESSAGE_COUNT);
        $this->assertNoStat($group->id, StatsGenerationService::TYPE_OUTCOMES);
    }

    public function test_running_twice_for_same_date_replaces_not_duplicates(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser();
        $this->createTestMessage($user, $group, ['arrival' => $this->date.' 09:00:00']);

        $this->service->generate($group->id, $this->date);
        $this->service->generate($group->id, $this->date);

        $rows = DB::table('stats')
            ->where('date', $this->date)
            ->where('groupid', $group->id)
            ->where('type', StatsGenerationService::TYPE_APPROVED_MESSAGE_COUNT)
            ->count();

        $this->assertEquals(1, $rows, 'REPLACE INTO should leave exactly one row per (date,group,type)');
    }

    public function test_generate_for_all_groups_returns_counts(): void
    {
        // Just confirm the orchestrator returns the right shape.
        $group = $this->createTestGroup();
        $user = $this->createTestUser();
        $this->createTestMessage($user, $group, ['arrival' => $this->date.' 09:00:00']);

        $result = $this->service->generateForAllGroups($this->date);

        $this->assertArrayHasKey('groups', $result);
        $this->assertArrayHasKey('rows_written', $result);
        $this->assertGreaterThanOrEqual(1, $result['groups']);
        $this->assertGreaterThanOrEqual(1, $result['rows_written']);
    }

    public function test_dry_run_does_not_write_to_stats(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser();
        $this->createTestMessage($user, $group, ['arrival' => $this->date.' 09:00:00']);

        $rowsBefore = DB::table('stats')->where('date', $this->date)->where('groupid', $group->id)->count();
        $this->service->generate($group->id, $this->date, true);
        $rowsAfter = DB::table('stats')->where('date', $this->date)->where('groupid', $group->id)->count();

        $this->assertEquals($rowsBefore, $rowsAfter, 'dry-run must not write');
    }
}
