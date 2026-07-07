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

        // Separate inserts because bulk insert builds column list from first row;
        // mixing null/non-null user2 across rows causes a column count mismatch.
        DB::table('chat_rooms')->insert(['chattype' => ChatRoom::TYPE_USER2MOD, 'user1' => $u1->id, 'groupid' => $group->id, 'created' => $this->date.' 10:00:00']);
        DB::table('chat_rooms')->insert(['chattype' => ChatRoom::TYPE_USER2MOD, 'user1' => $u2->id, 'groupid' => $group->id, 'created' => $this->date.' 11:00:00']);
        // Wrong chattype - should not be counted.
        DB::table('chat_rooms')->insert(['chattype' => ChatRoom::TYPE_USER2USER, 'user1' => $u1->id, 'user2' => $u2->id, 'groupid' => $group->id, 'created' => $this->date.' 12:00:00']);

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
        // A third, unrelated group id for the "other ids share the CSV" decoy. It must be a
        // real, distinct id: a hardcoded literal (previously 999) collides whenever the groups
        // auto-increment happens to assign $group that exact id, which makes the decoy token
        // equal the group under test and double-counts it (Searches tally 3 instead of 2). The
        // failing id depends only on how many groups earlier tests created, so it surfaced as a
        // flake when the suite grew.
        $unrelatedGroup = $this->createTestGroup();

        DB::table('search_history')->insert([
            ['date' => $this->date.' 10:00:00', 'term' => 'sofa', 'groups' => (string) $group->id],
            ['date' => $this->date.' 11:00:00', 'term' => 'chair', 'groups' => $otherGroup->id.','.$group->id.','.$unrelatedGroup->id],
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

    // ── Rippling-out: rippled-in copies must not inflate a group's stats ──────
    //
    // Rippling inserts an extra messages_groups row (rippled_in=1, collection
    // 'Approved') for each group a post is spread into. Those copies are not
    // native activity for the receiving group, and when the dashboard SUMs the
    // per-group rows for a systemwide figure one post is otherwise counted once
    // per group it reached (avg fan-out ~7). Every count/breakdown that joins
    // messages_groups must therefore exclude rippled_in=1 rows.

    /**
     * Add a rippled-in messages_groups copy of an existing message to a group.
     */
    private function rippleMessageInto(int $msgId, int $groupId, string $arrival): void
    {
        DB::table('messages_groups')->insert([
            'msgid' => $msgId,
            'groupid' => $groupId,
            'collection' => Membership::COLLECTION_APPROVED,
            'arrival' => $arrival,
            'rippled_in' => 1,
        ]);
    }

    public function test_approved_message_count_excludes_rippled_in_copies(): void
    {
        $groupA = $this->createTestGroup();
        $groupB = $this->createTestGroup();
        $user = $this->createTestUser();

        // One native post on B (counts) plus a post native on A rippled into B (must not count).
        $this->createTestMessage($user, $groupB, ['arrival' => $this->date.' 09:00:00']);
        $rippled = $this->createTestMessage($user, $groupA, ['arrival' => $this->date.' 10:00:00']);
        $this->rippleMessageInto($rippled->id, $groupB->id, $this->date.' 10:00:00');

        $this->service->generate($groupB->id, $this->date);

        $this->assertStat($groupB->id, StatsGenerationService::TYPE_APPROVED_MESSAGE_COUNT, 1);
    }

    public function test_outcomes_excludes_rippled_in_copies(): void
    {
        $groupA = $this->createTestGroup();
        $groupB = $this->createTestGroup();
        $user = $this->createTestUser();

        $native = $this->createTestMessage($user, $groupB);
        $rippled = $this->createTestMessage($user, $groupA);
        $this->rippleMessageInto($rippled->id, $groupB->id, $this->date.' 09:00:00');

        DB::table('messages_outcomes')->insert([
            ['msgid' => $native->id, 'userid' => $user->id, 'outcome' => Message::OUTCOME_TAKEN, 'timestamp' => $this->date.' 10:00:00'],
            ['msgid' => $rippled->id, 'userid' => $user->id, 'outcome' => Message::OUTCOME_TAKEN, 'timestamp' => $this->date.' 11:00:00'],
        ]);

        $this->service->generate($groupB->id, $this->date);

        $this->assertStat($groupB->id, StatsGenerationService::TYPE_OUTCOMES, 1);
    }

    public function test_replies_excludes_rippled_in_copies(): void
    {
        $groupA = $this->createTestGroup();
        $groupB = $this->createTestGroup();
        $u1 = $this->createTestUser();
        $u2 = $this->createTestUser();

        $native = $this->createTestMessage($u1, $groupB);
        $rippled = $this->createTestMessage($u1, $groupA);
        $this->rippleMessageInto($rippled->id, $groupB->id, $this->date.' 09:00:00');

        $room = $this->createTestChatRoom($u1, $u2);
        $this->createTestChatMessage($room, $u2, [
            'type' => ChatMessage::TYPE_INTERESTED,
            'refmsgid' => $native->id,
            'date' => $this->date.' 10:00:00',
        ]);
        $this->createTestChatMessage($room, $u2, [
            'type' => ChatMessage::TYPE_INTERESTED,
            'refmsgid' => $rippled->id,
            'date' => $this->date.' 11:00:00',
        ]);

        $this->service->generate($groupB->id, $this->date);

        $this->assertStat($groupB->id, StatsGenerationService::TYPE_REPLIES, 1);
    }

    public function test_feedback_happiness_excludes_rippled_in_copies(): void
    {
        $groupA = $this->createTestGroup();
        $groupB = $this->createTestGroup();
        $user = $this->createTestUser();

        // Only a rippled-in copy with happy feedback — B has no native feedback.
        $rippled = $this->createTestMessage($user, $groupA);
        $this->rippleMessageInto($rippled->id, $groupB->id, $this->date.' 09:00:00');
        DB::table('messages_outcomes')->insert([
            ['msgid' => $rippled->id, 'userid' => $user->id, 'outcome' => Message::OUTCOME_TAKEN, 'happiness' => 'Happy', 'timestamp' => $this->date.' 10:00:00'],
        ]);

        $this->service->generate($groupB->id, $this->date);

        $this->assertNoStat($groupB->id, StatsGenerationService::TYPE_FEEDBACK_HAPPY);
    }

    public function test_weight_excludes_rippled_in_copies(): void
    {
        $groupA = $this->createTestGroup();
        $groupB = $this->createTestGroup();
        $user = $this->createTestUser();

        // A taken item native on A, rippled into B. Its weight must not be attributed to B.
        $rippled = $this->createTestMessage($user, $groupA);
        $this->rippleMessageInto($rippled->id, $groupB->id, $this->date.' 09:00:00');
        $itemId = DB::table('items')->insertGetId(['name' => 'TestItem_'.uniqid(), 'weight' => 25.0, 'popularity' => 1.0]);
        DB::table('messages_items')->insert(['msgid' => $rippled->id, 'itemid' => $itemId]);
        DB::table('messages_outcomes')->insert([
            ['msgid' => $rippled->id, 'userid' => $user->id, 'outcome' => Message::OUTCOME_TAKEN, 'timestamp' => $this->date.' 10:00:00'],
        ]);

        $this->service->generate($groupB->id, $this->date);

        $this->assertNoStat($groupB->id, StatsGenerationService::TYPE_WEIGHT);
    }

    public function test_message_breakdown_excludes_rippled_in_copies(): void
    {
        $groupA = $this->createTestGroup();
        $groupB = $this->createTestGroup();
        $user = $this->createTestUser();

        $rippled = $this->createTestMessage($user, $groupA, ['arrival' => $this->date.' 09:00:00', 'type' => Message::TYPE_OFFER]);
        $this->rippleMessageInto($rippled->id, $groupB->id, $this->date.' 09:00:00');

        $this->service->generate($groupB->id, $this->date);

        // The rippled-in copy must not appear in B's type histogram.
        $this->assertBreakdown($groupB->id, StatsGenerationService::TYPE_MESSAGE_BREAKDOWN, []);
    }

    public function test_post_method_breakdown_excludes_rippled_in_copies(): void
    {
        $groupA = $this->createTestGroup();
        $groupB = $this->createTestGroup();
        $user = $this->createTestUser();

        $rippled = $this->createTestMessage($user, $groupA, ['arrival' => $this->date.' 09:00:00', 'sourceheader' => 'Web']);
        $this->rippleMessageInto($rippled->id, $groupB->id, $this->date.' 09:00:00');

        $this->service->generate($groupB->id, $this->date);

        $this->assertBreakdown($groupB->id, StatsGenerationService::TYPE_POST_METHOD_BREAKDOWN, []);
    }

    // ── Bulk-offer per-item counting ──────────────────────────────────────────
    //
    // A "bulk offer" message has rows in messages_bulk_items. Each stat type must
    // count by item quantity rather than by message count:
    //
    //   ApprovedMessageCount — SUM(quantity) over all bulk items (not 1 per message)
    //   Outcomes             — SUM(quantity) for items flipped available=0 on $date
    //   Weight               — SUM(weight * quantity) matched by items.name
    //   Replies              — interest rows + free-text Interested with no interest row
    //
    // A normal (non-bulk) control message must be unaffected.

    public function test_bulk_offer_per_item_counting(): void
    {
        $group = $this->createTestGroup();
        $owner = $this->createTestUser();
        $replier1 = $this->createTestUser();
        $freeTextReplier = $this->createTestUser();

        // ── Control: one normal message with one Interested reply ──────────────
        $control = $this->createTestMessage($owner, $group, ['arrival' => $this->date.' 08:00:00']);
        $controlRoom = $this->createTestChatRoom($owner, $replier1);
        $this->createTestChatMessage($controlRoom, $replier1, [
            'type' => ChatMessage::TYPE_INTERESTED,
            'refmsgid' => $control->id,
            'date' => $this->date.' 08:30:00',
        ]);

        // ── Bulk offer message: availableinitially=6 (item1 qty=3 + item2 qty=3). ──────
        // 1 unit of item1 was collected in-app (quantity decremented to 2), then
        // the offerer flipped the remainder to available=0 on $date. Item2 is still
        // available.
        $bulk = $this->createTestMessage($owner, $group, [
            'arrival' => $this->date.' 10:00:00',
            'availableinitially' => 6,
        ]);

        // Item 1 (qty=2 remaining after 1 collected): flipped available=0 on $date;
        // matched by name in the items table with weight=10.
        $item1Name = 'BulkItemA_'.uniqid();
        $item1Id = DB::table('messages_bulk_items')->insertGetId([
            'msgid' => $bulk->id,
            'name' => $item1Name,
            'quantity' => 2,
            'available' => 0,
            'updated_at' => $this->date.' 11:00:00',
            'created_at' => $this->date.' 09:00:00',
        ]);

        // Item 2 (qty=3): still available; no items-table row.
        $item2Id = DB::table('messages_bulk_items')->insertGetId([
            'msgid' => $bulk->id,
            'name' => 'BulkItemB_'.uniqid(),
            'quantity' => 3,
            'available' => 1,
            'updated_at' => $this->date.' 09:00:00',
            'created_at' => $this->date.' 09:00:00',
        ]);

        // Items-table row for item1 with known weight=10.
        DB::table('items')->insert(['name' => $item1Name, 'weight' => 10.0, 'popularity' => 1.0]);

        // ── Collected interest row for item1: 1 unit collected in-app on $date ──
        // state=Collected, updated_at in day, qty=1 (the 1 unit that was collected).
        // created_at in day so it also counts in the Replies part-1 arm.
        DB::table('messages_bulk_items_interest')->insert([
            'bulkitemid' => $item1Id,
            'msgid' => $bulk->id,
            'userid' => $replier1->id,
            'quantity' => 1,
            'state' => 'Collected',
            'created_at' => $this->date.' 10:30:00',
            'updated_at' => $this->date.' 10:30:00',
        ]);

        // ── Structured interest for item2 (Interested, not yet collected) ────
        DB::table('messages_bulk_items_interest')->insert([
            'bulkitemid' => $item2Id,
            'msgid' => $bulk->id,
            'userid' => $replier1->id,
            'quantity' => 1,
            'state' => 'Interested',
            'created_at' => $this->date.' 10:30:00',
            'updated_at' => $this->date.' 10:30:00',
        ]);

        // ── Free-text reply: freeTextReplier sends an Interested chat message
        //    but has no interest row for the bulk message ──────────────────────
        $bulkRoom = $this->createTestChatRoom($owner, $freeTextReplier);
        $this->createTestChatMessage($bulkRoom, $freeTextReplier, [
            'type' => ChatMessage::TYPE_INTERESTED,
            'refmsgid' => $bulk->id,
            'date' => $this->date.' 10:45:00',
        ]);

        $this->service->generate($group->id, $this->date);

        // ApprovedMessageCount uses availableinitially (not current quantity):
        //   base = 2 (control + bulk message), top-up = availableinitially(6) - 1 = 5
        //   total = 2 + 5 = 7.
        $this->assertStat($group->id, StatsGenerationService::TYPE_APPROVED_MESSAGE_COUNT, 7);

        // Outcomes = 2 (flip arm: item1 qty=2 remaining) + 1 (collected arm: interest qty=1) = 3.
        $this->assertStat($group->id, StatsGenerationService::TYPE_OUTCOMES, 3);

        // Weight = 10*2 (flip arm) + 10*1 (collected arm) = 30.
        $this->assertStat($group->id, StatsGenerationService::TYPE_WEIGHT, 30);

        // Replies = 1 (control) + 2 (interest rows: item1 Collected + item2 Interested,
        //   both counted by created_at) + 1 (free-text bulk) = 4.
        $this->assertStat($group->id, StatsGenerationService::TYPE_REPLIES, 4);

        // Activity = approvedMessages + replies = 7 + 4 = 11.
        $this->assertStat($group->id, StatsGenerationService::TYPE_ACTIVITY, 11);
    }
}
