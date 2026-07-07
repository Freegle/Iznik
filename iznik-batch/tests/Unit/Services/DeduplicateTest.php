<?php

namespace Tests\Unit\Services;

use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Services\PurgeService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeduplicateTest extends TestCase
{
    protected PurgeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PurgeService();
    }

    // --- Search History Deduplication ---

    public function test_deduplicate_search_history_removes_consecutive_dupes(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        // Insert 3 consecutive identical searches.
        for ($i = 0; $i < 3; $i++) {
            DB::table('search_history')->insert([
                'userid' => $user->id,
                'date' => now()->subHour(),
                'term' => 'table',
                'locationid' => null,
                'groups' => (string) $group->id,
            ]);
        }

        $deleted = $this->service->deduplicateSearchHistory(2);

        // Should delete 2 duplicates, keeping the first.
        $this->assertEquals(2, $deleted);

        $remaining = DB::table('search_history')
            ->where('userid', $user->id)
            ->where('term', 'table')
            ->count();
        $this->assertEquals(1, $remaining);
    }

    public function test_deduplicate_search_history_keeps_different_searches(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        DB::table('search_history')->insert([
            'userid' => $user->id,
            'date' => now()->subHour(),
            'term' => 'table',
            'locationid' => null,
            'groups' => (string) $group->id,
        ]);

        DB::table('search_history')->insert([
            'userid' => $user->id,
            'date' => now()->subMinutes(30),
            'term' => 'chair',
            'locationid' => null,
            'groups' => (string) $group->id,
        ]);

        $deleted = $this->service->deduplicateSearchHistory(2);

        $this->assertEquals(0, $deleted);
    }

    public function test_deduplicate_search_history_different_location_not_duplicate(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        $loc1 = \App\Models\Location::create([
            'name' => 'TestLoc1_' . uniqid(),
            'type' => 'Postcode',
            'lat' => 51.5,
            'lng' => -0.1,
        ]);
        $loc2 = \App\Models\Location::create([
            'name' => 'TestLoc2_' . uniqid(),
            'type' => 'Postcode',
            'lat' => 52.0,
            'lng' => -1.0,
        ]);

        DB::table('search_history')->insert([
            'userid' => $user->id,
            'date' => now()->subHour(),
            'term' => 'table',
            'locationid' => $loc1->id,
            'groups' => (string) $group->id,
        ]);

        DB::table('search_history')->insert([
            'userid' => $user->id,
            'date' => now()->subMinutes(30),
            'term' => 'table',
            'locationid' => $loc2->id,
            'groups' => (string) $group->id,
        ]);

        $deleted = $this->service->deduplicateSearchHistory(2);

        $this->assertEquals(0, $deleted);
    }

    public function test_deduplicate_search_history_no_recent_searches(): void
    {
        $deleted = $this->service->deduplicateSearchHistory(2);

        $this->assertEquals(0, $deleted);
    }

    public function test_deduplicate_search_history_respects_days_parameter(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        // Insert duplicates 5 days ago.
        for ($i = 0; $i < 2; $i++) {
            DB::table('search_history')->insert([
                'userid' => $user->id,
                'date' => now()->subDays(5),
                'term' => 'table',
                'locationid' => null,
                'groups' => (string) $group->id,
            ]);
        }

        // With 2-day window, these are outside the window.
        $deleted = $this->service->deduplicateSearchHistory(2);
        $this->assertEquals(0, $deleted);

        // With 7-day window, these should be found.
        $deleted = $this->service->deduplicateSearchHistory(7);
        $this->assertEquals(1, $deleted);
    }

    // --- Chat Message Deduplication ---

    public function test_deduplicate_chat_messages_removes_consecutive_dupes(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $room = $this->createTestChatRoom($user1, $user2);

        // Create duplicate consecutive messages.
        $this->createTestChatMessage($room, $user1, [
            'message' => 'Hello there',
            'date' => now()->subHour(),
        ]);
        $this->createTestChatMessage($room, $user1, [
            'message' => 'Hello there',
            'date' => now()->subMinutes(59),
        ]);

        $deleted = $this->service->deduplicateChatMessages(3);

        $this->assertEquals(1, $deleted);

        $remaining = ChatMessage::where('chatid', $room->id)
            ->where('message', 'Hello there')
            ->count();
        $this->assertEquals(1, $remaining);
    }

    public function test_deduplicate_chat_messages_keeps_different_messages(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $room = $this->createTestChatRoom($user1, $user2);

        $this->createTestChatMessage($room, $user1, [
            'message' => 'Hello',
            'date' => now()->subHour(),
        ]);
        $this->createTestChatMessage($room, $user1, [
            'message' => 'World',
            'date' => now()->subMinutes(59),
        ]);

        $deleted = $this->service->deduplicateChatMessages(3);

        $this->assertEquals(0, $deleted);
    }

    public function test_deduplicate_chat_messages_considers_refmsgid(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $group = $this->createTestGroup();

        $room = $this->createTestChatRoom($user1, $user2);
        $msg = $this->createTestMessage($user1, $group);

        // Same text but different refmsgid — NOT duplicates.
        $this->createTestChatMessage($room, $user1, [
            'message' => 'Interested',
            'refmsgid' => $msg->id,
            'date' => now()->subHour(),
        ]);
        $this->createTestChatMessage($room, $user1, [
            'message' => 'Interested',
            'refmsgid' => null,
            'date' => now()->subMinutes(59),
        ]);

        $deleted = $this->service->deduplicateChatMessages(3);

        $this->assertEquals(0, $deleted);
    }

    public function test_deduplicate_chat_messages_no_recent_messages(): void
    {
        $deleted = $this->service->deduplicateChatMessages(3);

        $this->assertEquals(0, $deleted);
    }
}
