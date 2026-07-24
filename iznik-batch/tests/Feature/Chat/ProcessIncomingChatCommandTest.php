<?php

namespace Tests\Feature\Chat;

use App\Models\ChatMessage;
use App\Services\ChatProcessService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ProcessIncomingChatCommandTest extends TestCase
{
    use DatabaseTransactions;

    /** @test */
    public function test_dry_run_reports_pending_count(): void
    {
        $this->artisan('chats:process-incoming', ['--dry-run' => true])
            ->expectsOutputToContain('[DRY RUN]')
            ->assertExitCode(0);
    }

    /** @test */
    public function test_command_runs_with_mocked_service(): void
    {
        $mock = $this->createMock(ChatProcessService::class);
        $mock->method('processIncoming')->willReturn(3);
        $this->app->instance(ChatProcessService::class, $mock);

        $this->artisan('chats:process-incoming')
            ->expectsOutputToContain('3 message(s) processed')
            ->assertExitCode(0);
    }

    /**
     * A deliverable reply into a room dormant beyond the 31-day ListForUser window must
     * bump chat_rooms.latestmessage on processing, so the room resurfaces in the
     * recipient's chat list immediately - matching when the notification email fires -
     * rather than waiting up to an hour for the chats:update-counts recompute.
     *
     * @test
     */
    public function test_deliverable_reply_bumps_latestmessage_for_dormant_room(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $dormant = now()->subDays(400);
        $room = $this->createTestChatRoom($user1, $user2, [
            'latestmessage' => $dormant,
        ]);

        // A new, still-pending reply exactly as CreateChatMessage writes it.
        $this->createTestChatMessage($room, $user2, [
            'message' => 'Hi, is this still available?',
            'type' => ChatMessage::TYPE_INTERESTED,
            'date' => now(),
            'processingrequired' => 1,
            'processingsuccessful' => 0,
        ]);

        app(ChatProcessService::class)->processIncoming();

        $room->refresh();
        $this->assertTrue(
            $room->latestmessage->greaterThan(now()->subMinutes(5)),
            'latestmessage should be bumped to the reply time when the message becomes deliverable'
        );
    }

    /**
     * A message held for review must NOT bump latestmessage: the dormant room must stay
     * out of the recipient's list until a moderator approves it (which bumps latestmessage
     * via updateMessageCounts). Otherwise the room surfaces with a gated, unreadable message.
     *
     * @test
     */
    public function test_held_reply_does_not_bump_latestmessage(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $dormant = now()->subDays(400);
        $room = $this->createTestChatRoom($user1, $user2, [
            'latestmessage' => $dormant,
        ]);

        // Previous message already held for review -> the process chain holds this one too
        // (REVIEW_LAST), independent of any content-check keyword seeding.
        $this->createTestChatMessage($room, $user2, [
            'date' => now()->subMinutes(2),
            'reviewrequired' => 1,
        ]);
        $this->createTestChatMessage($room, $user2, [
            'message' => 'Following up',
            'type' => ChatMessage::TYPE_INTERESTED,
            'date' => now(),
            'processingrequired' => 1,
            'processingsuccessful' => 0,
        ]);

        app(ChatProcessService::class)->processIncoming();

        $room->refresh();
        $this->assertEquals(
            $dormant->format('Y-m-d H:i:s'),
            $room->latestmessage->format('Y-m-d H:i:s'),
            'a held-for-review message must not surface the dormant room'
        );
    }
}
