<?php

namespace Tests\Feature\Chat;

use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\ChatRoster;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NotifyChatCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    protected function createChatRoomWithLatest(User $user1, User $user2, string $type = ChatRoom::TYPE_USER2USER): ChatRoom
    {
        return parent::createTestChatRoom($user1, $user2, [
            'chattype' => $type,
            'latestmessage' => now(),
        ]);
    }

    protected function createChatMessageWithDate(ChatRoom $room, User $sender): ChatMessage
    {
        return parent::createTestChatMessage($room, $sender, [
            'date' => now()->subMinutes(5),
        ]);
    }

    protected function createRosterEntries(ChatRoom $room, User $user1, User $user2): void
    {
        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $user1->id,
            'lastmsgemailed' => null,
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $user2->id,
            'lastmsgemailed' => null,
        ]);
    }

    public function test_user2user_command_runs_successfully(): void
    {
        // Use a non-existent chat ID to avoid scanning all chats.
        $this->artisan('mail:chat:user2user', ['--chat' => -1, '--max-iterations' => 1])
            ->assertExitCode(0);
    }

    public function test_user2mod_command_runs_successfully(): void
    {
        // Use a non-existent chat ID to avoid scanning all chats.
        $this->artisan('mail:chat:user2mod', ['--chat' => -1, '--max-iterations' => 1])
            ->assertExitCode(0);
    }

    public function test_user2user_command_processes_specific_chat(): void
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();

        $room = $this->createChatRoomWithLatest($sender, $recipient);
        $this->createRosterEntries($room, $sender, $recipient);
        $this->createChatMessageWithDate($room, $sender);

        $this->artisan('mail:chat:user2user', [
            '--chat' => $room->id,
            '--max-iterations' => 1,
        ])->assertExitCode(0);
    }

    public function test_command_accepts_delay_option(): void
    {
        // Use a non-existent chat ID to avoid scanning all chats.
        $this->artisan('mail:chat:user2user', [
            '--chat' => -1,
            '--delay' => 60,
            '--max-iterations' => 1,
        ])->assertExitCode(0);
    }

    public function test_command_accepts_since_option(): void
    {
        // Use a non-existent chat ID to avoid scanning all chats.
        $this->artisan('mail:chat:user2user', [
            '--chat' => -1,
            '--since' => 48,
            '--max-iterations' => 1,
        ])->assertExitCode(0);
    }

    public function test_command_accepts_force_option(): void
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();

        $room = $this->createChatRoomWithLatest($sender, $recipient);
        $this->createRosterEntries($room, $sender, $recipient);

        // Create already-mailed message.
        ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $sender->id,
            'message' => 'Already mailed',
            'type' => ChatMessage::TYPE_DEFAULT,
            'date' => now()->subMinutes(5),
            'reviewrequired' => 0,
            'processingrequired' => 0,
            'processingsuccessful' => 1,
            'mailedtoall' => 1,
            'seenbyall' => 1,
            'reviewrejected' => 0,
            'platform' => 1,
        ]);

        $this->artisan('mail:chat:user2user', [
            '--chat' => $room->id,
            '--force' => true,
            '--max-iterations' => 1,
        ])->assertExitCode(0);
    }

    public function test_command_displays_completion_message(): void
    {
        // Use a non-existent chat ID to avoid scanning all chats.
        $this->artisan('mail:chat:user2user', ['--chat' => -1, '--max-iterations' => 1])
            ->expectsOutputToContain('User2User notification complete')
            ->assertExitCode(0);
    }

    public function test_user2mod_command_displays_completion_message(): void
    {
        // Use a non-existent chat ID to avoid scanning all chats.
        $this->artisan('mail:chat:user2mod', ['--chat' => -1, '--max-iterations' => 1])
            ->expectsOutputToContain('User2Mod notification complete')
            ->assertExitCode(0);
    }
}
