<?php

namespace Tests\Feature\Chat;

use App\Models\ChatMessage;
use App\Models\ChatRoom;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MergeDuplicateChatRoomsCommandTest extends TestCase
{
    public function test_no_duplicates_found(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        // Single room, no duplicate
        $this->createTestChatRoom($user1, $user2);

        $this->artisan('chat:merge-duplicates', ['--user' => $user1->id])
            ->expectsOutputToContain('Found 0 duplicate pair(s)')
            ->assertExitCode(0);
    }

    public function test_dry_run_does_not_change_data(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        // Create duplicate pair (swapped user1/user2)
        $canonical = $this->createTestChatRoom($user1, $user2);
        $this->createTestChatMessage($canonical, $user1, ['message' => 'old msg']);

        $duplicate = $this->createTestChatRoom($user2, $user1);
        $this->createTestChatMessage($duplicate, $user2, ['message' => 'new msg']);

        $this->artisan('chat:merge-duplicates', ['--dry-run' => true, '--user' => $user1->id])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('Found 1 duplicate pair(s)')
            ->assertExitCode(0);

        // Both rooms still exist
        $this->assertDatabaseHas('chat_rooms', ['id' => $canonical->id]);
        $this->assertDatabaseHas('chat_rooms', ['id' => $duplicate->id]);

        // Messages not moved
        $this->assertDatabaseHas('chat_messages', ['chatid' => $duplicate->id, 'message' => 'new msg']);
    }

    public function test_merges_duplicate_rooms(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        // Create canonical (older) room with messages
        $canonical = $this->createTestChatRoom($user1, $user2);
        $this->createTestChatMessage($canonical, $user1, ['message' => 'hello']);
        $this->createTestChatMessage($canonical, $user2, ['message' => 'hi back']);

        // Create roster for canonical
        DB::table('chat_roster')->insertOrIgnore([
            ['chatid' => $canonical->id, 'userid' => $user1->id],
            ['chatid' => $canonical->id, 'userid' => $user2->id],
        ]);

        // Create duplicate (newer) room with swapped users
        $duplicate = $this->createTestChatRoom($user2, $user1);
        $this->createTestChatMessage($duplicate, $user1, ['message' => 'interested in your item']);

        // Create roster for duplicate
        DB::table('chat_roster')->insertOrIgnore([
            ['chatid' => $duplicate->id, 'userid' => $user1->id],
            ['chatid' => $duplicate->id, 'userid' => $user2->id],
        ]);

        $this->artisan('chat:merge-duplicates', ['--user' => $user1->id])
            ->expectsOutputToContain('Merged successfully')
            ->assertExitCode(0);

        // Duplicate room deleted
        $this->assertDatabaseMissing('chat_rooms', ['id' => $duplicate->id]);

        // Canonical room still exists
        $this->assertDatabaseHas('chat_rooms', ['id' => $canonical->id]);

        // All messages now in canonical room
        $this->assertEquals(0, ChatMessage::where('chatid', $duplicate->id)->count());
        $this->assertEquals(3, ChatMessage::where('chatid', $canonical->id)->count());

        // Redirect created
        $this->assertDatabaseHas('chat_room_redirects', [
            'old_id' => $duplicate->id,
            'new_id' => $canonical->id,
        ]);
    }

    public function test_user_filter_only_processes_matching_user(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $user3 = $this->createTestUser();
        $user4 = $this->createTestUser();

        // Dup pair for user1+user2
        $this->createTestChatRoom($user1, $user2);
        $this->createTestChatRoom($user2, $user1);

        // Dup pair for user3+user4 (should not be touched)
        $room3 = $this->createTestChatRoom($user3, $user4);
        $room4 = $this->createTestChatRoom($user4, $user3);

        $this->artisan('chat:merge-duplicates', ['--user' => $user1->id])
            ->expectsOutputToContain('Found 1 duplicate pair(s)')
            ->assertExitCode(0);

        // user3+user4 dup still exists
        $this->assertDatabaseHas('chat_rooms', ['id' => $room3->id]);
        $this->assertDatabaseHas('chat_rooms', ['id' => $room4->id]);
    }

    public function test_limit_option(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $user3 = $this->createTestUser();

        // Two dup pairs for user1
        $this->createTestChatRoom($user1, $user2);
        $this->createTestChatRoom($user2, $user1);
        $this->createTestChatRoom($user1, $user3);
        $this->createTestChatRoom($user3, $user1);

        $this->artisan('chat:merge-duplicates', ['--user' => $user1->id, '--limit' => 1])
            ->expectsOutputToContain('Done: 1 merged, 0 errors')
            ->assertExitCode(0);
    }
}
