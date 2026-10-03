<?php

namespace Tests\Unit\Services;

use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\Message;
use App\Services\StatsGenerationService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Covers the per-type SQL ported from V1 Stats::generate(), now national.
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

    private function assertStat(string $type, int $expected): void
    {
        $row = DB::table('stats')
            ->where('date', $this->date)
            ->where('type', $type)
            ->first();

        $this->assertNotNull($row, "Expected `{$type}` stat row for date {$this->date}");
        $this->assertEquals($expected, $row->count, "`{$type}` count mismatch");
    }

    private function assertNoStat(string $type): void
    {
        $row = DB::table('stats')
            ->where('date', $this->date)
            ->where('type', $type)
            ->first();
        $this->assertNull($row, "Did not expect a `{$type}` row to be written");
    }

    private function assertBreakdown(string $type, array $expected): void
    {
        $row = DB::table('stats')
            ->where('date', $this->date)
            ->where('type', $type)
            ->first();
        $this->assertNotNull($row, "Expected `{$type}` breakdown row");
        $this->assertEquals($expected, json_decode($row->breakdown, true), "`{$type}` breakdown mismatch");
    }

    public function test_outcomes_counts_distinct_msgids_with_taken_or_received_outcome(): void
    {
        $user = $this->createTestUser();
        $msg1 = $this->createTestMessage($user);
        $msg2 = $this->createTestMessage($user);

        // Two outcomes for msg1 (only counted once due to DISTINCT) and one for msg2.
        DB::table('messages_outcomes')->insert([
            ['msgid' => $msg1->id, 'userid' => $user->id, 'outcome' => Message::OUTCOME_TAKEN, 'timestamp' => $this->date.' 10:00:00'],
            ['msgid' => $msg1->id, 'userid' => $user->id, 'outcome' => Message::OUTCOME_RECEIVED, 'timestamp' => $this->date.' 11:00:00'],
            ['msgid' => $msg2->id, 'userid' => $user->id, 'outcome' => Message::OUTCOME_TAKEN, 'timestamp' => $this->date.' 12:00:00'],
        ]);

        $this->service->generate($this->date);

        $this->assertStat(StatsGenerationService::TYPE_OUTCOMES, 2);
    }

    public function test_approved_message_count_uses_arrival_date_and_collection(): void
    {
        $user = $this->createTestUser();

        // Two messages arriving on $date, one the day before.
        $this->createTestMessage($user, ['arrival' => $this->date.' 09:00:00']);
        $this->createTestMessage($user, ['arrival' => $this->date.' 15:30:00']);
        $this->createTestMessage($user, ['arrival' => '2026-03-31 23:59:59']);

        $this->service->generate($this->date);

        $this->assertStat(StatsGenerationService::TYPE_APPROVED_MESSAGE_COUNT, 2);
    }

    public function test_approved_member_count_is_cumulative_as_of_date(): void
    {
        // The count is national, so push any fixture members out of the way.
        DB::table('users')->update(['added' => '2030-01-01 00:00:00']);

        // Three members added before $date, one after.
        $u1 = $this->createTestUser();
        $u2 = $this->createTestUser();
        $u3 = $this->createTestUser();
        $u4 = $this->createTestUser();

        DB::table('users')->where('id', $u1->id)->update(['added' => '2026-01-01 10:00:00']);
        DB::table('users')->where('id', $u2->id)->update(['added' => '2026-02-01 10:00:00']);
        DB::table('users')->where('id', $u3->id)->update(['added' => $this->date.' 10:00:00']);
        // Joined after — not counted.
        DB::table('users')->where('id', $u4->id)->update(['added' => '2026-04-02 10:00:00']);

        $this->service->generate($this->date);

        $this->assertStat(StatsGenerationService::TYPE_APPROVED_MEMBER_COUNT, 3);
    }

    public function test_spam_message_count_picks_up_classified_spam_logs(): void
    {
        $user = $this->createTestUser();

        DB::table('logs')->insert([
            ['user' => $user->id, 'type' => 'Message', 'subtype' => 'ClassifiedSpam', 'timestamp' => $this->date.' 10:00:00'],
            ['user' => $user->id, 'type' => 'Message', 'subtype' => 'ClassifiedSpam', 'timestamp' => $this->date.' 11:00:00'],
            // Wrong subtype — ignored.
            ['user' => $user->id, 'type' => 'Message', 'subtype' => 'Approved', 'timestamp' => $this->date.' 11:00:00'],
            // Wrong day — ignored.
            ['user' => $user->id, 'type' => 'Message', 'subtype' => 'ClassifiedSpam', 'timestamp' => '2026-04-02 10:00:00'],
        ]);

        $this->service->generate($this->date);

        $this->assertStat(StatsGenerationService::TYPE_SPAM_MESSAGE_COUNT, 2);
    }

    public function test_support_queries_counts_user2mod_chat_rooms_created_on_date(): void
    {
        $u1 = $this->createTestUser();
        $u2 = $this->createTestUser();

        // Separate inserts because bulk insert builds column list from first row;
        // mixing null/non-null user2 across rows causes a column count mismatch.
        DB::table('chat_rooms')->insert(['chattype' => ChatRoom::TYPE_USER2MOD, 'user1' => $u1->id, 'created' => $this->date.' 10:00:00']);
        DB::table('chat_rooms')->insert(['chattype' => ChatRoom::TYPE_USER2MOD, 'user1' => $u2->id, 'created' => $this->date.' 11:00:00']);
        // Wrong chattype - should not be counted.
        DB::table('chat_rooms')->insert(['chattype' => ChatRoom::TYPE_USER2USER, 'user1' => $u1->id, 'user2' => $u2->id, 'created' => $this->date.' 12:00:00']);

        $this->service->generate($this->date);

        $this->assertStat(StatsGenerationService::TYPE_SUPPORTQUERIES_COUNT, 2);
    }

    public function test_feedback_happiness_counts_per_label(): void
    {
        $user = $this->createTestUser();
        $msgHappy1 = $this->createTestMessage($user);
        $msgHappy2 = $this->createTestMessage($user);
        $msgFine = $this->createTestMessage($user);
        $msgUnhappy = $this->createTestMessage($user);

        DB::table('messages_outcomes')->insert([
            ['msgid' => $msgHappy1->id, 'userid' => $user->id, 'outcome' => Message::OUTCOME_TAKEN, 'happiness' => 'Happy', 'timestamp' => $this->date.' 10:00:00'],
            ['msgid' => $msgHappy2->id, 'userid' => $user->id, 'outcome' => Message::OUTCOME_TAKEN, 'happiness' => 'Happy', 'timestamp' => $this->date.' 11:00:00'],
            ['msgid' => $msgFine->id, 'userid' => $user->id, 'outcome' => Message::OUTCOME_TAKEN, 'happiness' => 'Fine', 'timestamp' => $this->date.' 12:00:00'],
            ['msgid' => $msgUnhappy->id, 'userid' => $user->id, 'outcome' => Message::OUTCOME_TAKEN, 'happiness' => 'Unhappy', 'timestamp' => $this->date.' 13:00:00'],
        ]);

        $this->service->generate($this->date);

        $this->assertStat(StatsGenerationService::TYPE_FEEDBACK_HAPPY, 2);
        $this->assertStat(StatsGenerationService::TYPE_FEEDBACK_FINE, 1);
        $this->assertStat(StatsGenerationService::TYPE_FEEDBACK_UNHAPPY, 1);
    }

    public function test_replies_counts_interested_chat_messages_for_the_posts(): void
    {
        $u1 = $this->createTestUser();
        $u2 = $this->createTestUser();
        $msg = $this->createTestMessage($u1);
        $room = $this->createTestChatRoom($u1, $u2);

        // Two interested replies on $date for the post; one non-Interested ignored.
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

        $this->service->generate($this->date);

        $this->assertStat(StatsGenerationService::TYPE_REPLIES, 2);
    }

    public function test_replies_excludes_senders_on_the_spammer_list(): void
    {
        // On 2026-09-06 one throwaway account sent 2,155 blank replies in twenty minutes;
        // every one was rejected and the account listed, and the day's Replies stat still
        // more than doubled. The listing is the verdict the stat honours.
        $poster = $this->createTestUser();
        $genuine = $this->createTestUser();
        $spammer = $this->createTestUser();
        // Arrives on $date so Activity (approved messages + replies) has both terms.
        $msg = $this->createTestMessage($poster, ['arrival' => $this->date.' 09:00:00']);

        $genuineRoom = $this->createTestChatRoom($poster, $genuine);
        $spamRoom = $this->createTestChatRoom($poster, $spammer);
        $this->createTestChatMessage($genuineRoom, $genuine, [
            'type' => ChatMessage::TYPE_INTERESTED,
            'refmsgid' => $msg->id,
            'date' => $this->date.' 10:00:00',
        ]);
        foreach (['10:20:00', '10:21:00', '10:22:00'] as $t) {
            $this->createTestChatMessage($spamRoom, $spammer, [
                'type' => ChatMessage::TYPE_INTERESTED,
                'refmsgid' => $msg->id,
                'date' => $this->date.' '.$t,
            ]);
        }
        DB::table('spam_users')->insert([
            'userid' => $spammer->id,
            'byuserid' => $poster->id,
            'collection' => 'Spammer',
            'reason' => 'Spam messages in multiple chats',
        ]);

        $this->service->generate($this->date);

        $this->assertStat(StatsGenerationService::TYPE_REPLIES, 1);
        // Activity is approved messages + replies, so it must not carry the spam either.
        $this->assertStat(StatsGenerationService::TYPE_ACTIVITY, 2);
    }

    public function test_replies_still_counts_a_whitelisted_or_pending_listing(): void
    {
        // Only the Spammer collection is a verdict; Whitelisted and the pending states
        // are not, and a reply from such a member counts as it always did.
        $poster = $this->createTestUser();
        $replier = $this->createTestUser();
        $msg = $this->createTestMessage($poster);
        $room = $this->createTestChatRoom($poster, $replier);
        $this->createTestChatMessage($room, $replier, [
            'type' => ChatMessage::TYPE_INTERESTED,
            'refmsgid' => $msg->id,
            'date' => $this->date.' 10:00:00',
        ]);
        DB::table('spam_users')->insert([
            'userid' => $replier->id,
            'byuserid' => $poster->id,
            'collection' => 'Whitelisted',
            'reason' => 'Trusted',
        ]);

        $this->service->generate($this->date);

        $this->assertStat(StatsGenerationService::TYPE_REPLIES, 1);
    }

    public function test_activity_is_approved_message_count_plus_replies(): void
    {
        $u1 = $this->createTestUser();
        $u2 = $this->createTestUser();
        // 3 approved + 1 reply -> activity = 4.
        $this->createTestMessage($u1, ['arrival' => $this->date.' 09:00:00']);
        $this->createTestMessage($u1, ['arrival' => $this->date.' 10:00:00']);
        $msg = $this->createTestMessage($u1, ['arrival' => $this->date.' 11:00:00']);
        $room = $this->createTestChatRoom($u1, $u2);
        $this->createTestChatMessage($room, $u2, [
            'type' => ChatMessage::TYPE_INTERESTED,
            'refmsgid' => $msg->id,
            'date' => $this->date.' 12:00:00',
        ]);

        $this->service->generate($this->date);

        $this->assertStat(StatsGenerationService::TYPE_ACTIVITY, 4);
    }

    public function test_post_method_breakdown_is_30_day_window_histogram(): void
    {
        $user = $this->createTestUser();

        // Three within the 30-day window ending tomorrow-of-$date:
        $this->createTestMessage($user, ['arrival' => $this->date.' 09:00:00', 'sourceheader' => 'Web']);
        $this->createTestMessage($user, ['arrival' => '2026-03-15 09:00:00', 'sourceheader' => 'Web']);
        $this->createTestMessage($user, ['arrival' => '2026-03-20 09:00:00', 'sourceheader' => 'Platform']);
        // Outside the 30-day window — not counted.
        $this->createTestMessage($user, ['arrival' => '2026-02-15 09:00:00', 'sourceheader' => 'Email']);

        $this->service->generate($this->date);

        $this->assertBreakdown(StatsGenerationService::TYPE_POST_METHOD_BREAKDOWN, [
            'Platform' => 1,
            'Web' => 2,
        ]);
    }

    public function test_message_breakdown_is_30_day_window_histogram_by_type(): void
    {
        $user = $this->createTestUser();
        $this->createTestMessage($user, ['arrival' => $this->date.' 09:00:00', 'type' => Message::TYPE_OFFER]);
        $this->createTestMessage($user, ['arrival' => '2026-03-15 09:00:00', 'type' => Message::TYPE_OFFER]);
        $this->createTestMessage($user, ['arrival' => '2026-03-20 09:00:00', 'type' => Message::TYPE_WANTED]);

        $this->service->generate($this->date);

        $row = DB::table('stats')
            ->where('date', $this->date)
            ->where('type', StatsGenerationService::TYPE_MESSAGE_BREAKDOWN)
            ->first();
        $this->assertNotNull($row);
        $breakdown = json_decode($row->breakdown, true);
        $this->assertEquals(2, $breakdown[Message::TYPE_OFFER]);
        $this->assertEquals(1, $breakdown[Message::TYPE_WANTED]);
    }

    public function test_searches_count_tallies_rows_for_the_day(): void
    {
        DB::table('search_history')->insert([
            ['date' => $this->date.' 10:00:00', 'term' => 'sofa'],
            ['date' => $this->date.' 11:00:00', 'term' => 'chair'],
            ['date' => $this->date.' 12:00:00', 'term' => 'table'],
            // Wrong date — ignored.
            ['date' => '2026-04-02 10:00:00', 'term' => 'desk'],
        ]);

        $this->service->generate($this->date);

        $this->assertStat(StatsGenerationService::TYPE_SEARCHES, 3);
    }

    public function test_zero_counts_are_not_written(): void
    {
        // No activity → V1 setCount skipped 0-valued rows; preserve.
        $this->service->generate($this->date);
        $this->assertNoStat(StatsGenerationService::TYPE_APPROVED_MESSAGE_COUNT);
        $this->assertNoStat(StatsGenerationService::TYPE_OUTCOMES);
    }

    public function test_running_twice_for_same_date_replaces_not_duplicates(): void
    {
        $user = $this->createTestUser();
        $this->createTestMessage($user, ['arrival' => $this->date.' 09:00:00']);

        $this->service->generate($this->date);
        $this->service->generate($this->date);

        $rows = DB::table('stats')
            ->where('date', $this->date)
            ->where('type', StatsGenerationService::TYPE_APPROVED_MESSAGE_COUNT)
            ->count();

        $this->assertEquals(1, $rows, 'REPLACE INTO should leave exactly one row per (date,type)');
    }

    public function test_regeneration_removes_a_row_whose_count_fell_to_zero(): void
    {
        // A re-run that brings a count down to nothing must take the old row with
        // it: after the 2026-09-06 spam wave was excluded, 117 communities whose only
        // "replies" had been the bot's kept their inflated Replies rows through
        // the regeneration, because a zero count was skipped rather than written.
        $poster = $this->createTestUser();
        $replier = $this->createTestUser();
        $msg = $this->createTestMessage($poster, ['arrival' => '2026-03-20 09:00:00']);
        $room = $this->createTestChatRoom($poster, $replier);
        $this->createTestChatMessage($room, $replier, [
            'type' => ChatMessage::TYPE_INTERESTED,
            'refmsgid' => $msg->id,
            'date' => $this->date.' 10:00:00',
        ]);

        $this->service->generate($this->date);
        $this->assertStat(StatsGenerationService::TYPE_REPLIES, 1);

        DB::table('spam_users')->insert([
            'userid' => $replier->id,
            'byuserid' => $poster->id,
            'collection' => 'Spammer',
            'reason' => 'Spam messages in multiple chats',
        ]);

        // A dry run reports what it would do and touches nothing, stale row included.
        $this->service->generate($this->date, true);
        $this->assertStat(StatsGenerationService::TYPE_REPLIES, 1);

        $this->service->generate($this->date);
        $this->assertNoStat(StatsGenerationService::TYPE_REPLIES);
        // Activity is approved messages + replies; with no post on $date it is zero too.
        $this->assertNoStat(StatsGenerationService::TYPE_ACTIVITY);
    }

    public function test_dry_run_does_not_write_to_stats(): void
    {
        $user = $this->createTestUser();
        $this->createTestMessage($user, ['arrival' => $this->date.' 09:00:00']);

        $rowsBefore = DB::table('stats')->where('date', $this->date)->count();
        $this->service->generate($this->date, true);
        $rowsAfter = DB::table('stats')->where('date', $this->date)->count();

        $this->assertEquals($rowsBefore, $rowsAfter, 'dry-run must not write');
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
        $owner = $this->createTestUser();
        $replier1 = $this->createTestUser();
        $freeTextReplier = $this->createTestUser();

        // ── Control: one normal message with one Interested reply ──────────────
        $control = $this->createTestMessage($owner, ['arrival' => $this->date.' 08:00:00']);
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
        $bulk = $this->createTestMessage($owner, [
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

        $this->service->generate($this->date);

        // ApprovedMessageCount uses availableinitially (not current quantity):
        //   base = 2 (control + bulk message), top-up = availableinitially(6) - 1 = 5
        //   total = 2 + 5 = 7.
        $this->assertStat(StatsGenerationService::TYPE_APPROVED_MESSAGE_COUNT, 7);

        // Outcomes = 2 (flip arm: item1 qty=2 remaining) + 1 (collected arm: interest qty=1) = 3.
        $this->assertStat(StatsGenerationService::TYPE_OUTCOMES, 3);

        // Weight = 10*2 (flip arm) + 10*1 (collected arm) = 30.
        $this->assertStat(StatsGenerationService::TYPE_WEIGHT, 30);

        // Replies = 1 (control) + 2 (interest rows: item1 Collected + item2 Interested,
        //   both counted by created_at) + 1 (free-text bulk) = 4.
        $this->assertStat(StatsGenerationService::TYPE_REPLIES, 4);

        // Activity = approvedMessages + replies = 7 + 4 = 11.
        $this->assertStat(StatsGenerationService::TYPE_ACTIVITY, 11);
    }

    // ── Collation guard ───────────────────────────────────────────────────────
    //
    // items.name is utf8mb4_unicode_ci but messages_bulk_items was created on
    // production with the MySQL 8 server default (utf8mb4_0900_ai_ci). The bulk
    // weight queries join `items i ON i.name = bi.name`, so without an explicit
    // COLLATE that join throws SQLSTATE[HY000] 1267 "Illegal mix of collations"
    // and stats generation aborts for the whole day.
    //
    // This can't be reproduced by ALTERing the test table: DatabaseTransactions
    // wraps each test in a transaction, and an ALTER forces an implicit commit
    // that breaks isolation. Instead we assert the guard is present in the emitted
    // SQL for BOTH code paths (buildDailyContext and regenerateWeightForRange),
    // which is exactly what a regression here would remove.

    /**
     * Capture every SQL statement a callback runs, then return only those that
     * join the items table by name (the collation-sensitive join).
     *
     * @return list<string>
     */
    private function captureItemNameJoins(callable $fn): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $fn();
        } finally {
            $log = DB::getQueryLog();
            DB::disableQueryLog();
        }

        return array_values(array_filter(
            array_map(fn ($q) => $q['query'], $log),
            fn ($sql) => str_contains($sql, 'items i ON i.name = bi.name')
        ));
    }

    public function test_bulk_item_name_join_forces_unicode_collation_in_daily_context(): void
    {
        $owner = $this->createTestUser();
        $bulk = $this->createTestMessage($owner, ['arrival' => $this->date.' 10:00:00']);
        $itemName = 'CollationItem_'.uniqid();
        DB::table('messages_bulk_items')->insert([
            'msgid' => $bulk->id,
            'name' => $itemName,
            'quantity' => 1,
            'available' => 0,
            'updated_at' => $this->date.' 11:00:00',
            'created_at' => $this->date.' 09:00:00',
        ]);
        DB::table('items')->insert(['name' => $itemName, 'weight' => 10.0, 'popularity' => 1.0]);

        $joins = $this->captureItemNameJoins(fn () => $this->service->generateForDate($this->date));

        $this->assertNotEmpty($joins, 'Expected the daily context to run the items-by-name join');
        foreach ($joins as $sql) {
            $this->assertStringContainsString(
                'i.name = bi.name COLLATE utf8mb4_unicode_ci',
                $sql,
                'items-by-name join must force utf8mb4_unicode_ci to match items.name'
            );
        }
    }

    public function test_bulk_item_name_join_forces_unicode_collation_in_weight_regen(): void
    {
        $owner = $this->createTestUser();
        $bulk = $this->createTestMessage($owner, ['arrival' => $this->date.' 10:00:00']);
        $itemName = 'CollationItem_'.uniqid();
        DB::table('messages_bulk_items')->insert([
            'msgid' => $bulk->id,
            'name' => $itemName,
            'quantity' => 1,
            'available' => 0,
            'updated_at' => $this->date.' 11:00:00',
            'created_at' => $this->date.' 09:00:00',
        ]);
        DB::table('items')->insert(['name' => $itemName, 'weight' => 10.0, 'popularity' => 1.0]);

        $joins = $this->captureItemNameJoins(
            fn () => $this->service->regenerateWeightForRange($this->date, $this->date)
        );

        $this->assertNotEmpty($joins, 'Expected weight regeneration to run the items-by-name join');
        foreach ($joins as $sql) {
            $this->assertStringContainsString(
                'i.name = bi.name COLLATE utf8mb4_unicode_ci',
                $sql,
                'items-by-name join must force utf8mb4_unicode_ci to match items.name'
            );
        }
    }
}
