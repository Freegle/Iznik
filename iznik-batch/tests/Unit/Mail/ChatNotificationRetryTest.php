<?php

namespace Tests\Unit\Mail;

use App\Mail\Chat\ChatNotification;
use App\Mail\Contracts\RetryableMailable;
use App\Models\ChatRoom;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Verifies ChatNotification's RetryableMailable contract.
 */
class ChatNotificationRetryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_is_a_retryable_mailable(): void
    {
        $this->assertInstanceOf(RetryableMailable::class, $this->makeNotificationWithContext()[0]);
    }

    public function test_descriptor_captures_participants_chat_and_messages(): void
    {
        [$mailable, $recipient, $sender, $room, $message, $previous] = $this->makeNotificationWithContext();

        $descriptor = $mailable->mailDescriptor();

        $this->assertSame($recipient->id, $descriptor['recipient']);
        $this->assertSame($sender->id, $descriptor['sender']);
        $this->assertSame($room->id, $descriptor['chatid']);
        $this->assertSame($message->id, $descriptor['messageid']);
        $this->assertSame(ChatRoom::TYPE_USER2USER, $descriptor['chattype']);
        $this->assertSame([$previous->id], $descriptor['previous']);
    }

    public function test_rebuild_from_descriptor_reconstructs_equivalent_notification(): void
    {
        [$mailable, $recipient, $sender, $room, $message] = $this->makeNotificationWithContext();

        $rebuilt = ChatNotification::rebuildFromDescriptor($mailable->mailDescriptor());

        $this->assertInstanceOf(ChatNotification::class, $rebuilt);
        $this->assertSame($recipient->id, $rebuilt->recipient->id);
        $this->assertSame($sender->id, $rebuilt->sender->id);
        $this->assertSame($room->id, $rebuilt->chatRoom->id);
        $this->assertSame($message->id, $rebuilt->message->id);
    }

    public function test_rebuild_returns_null_for_unknown_recipient(): void
    {
        $rebuilt = ChatNotification::rebuildFromDescriptor([
            'recipient' => 999999999,
            'sender' => null,
            'chatid' => 1,
            'messageid' => 1,
            'chattype' => ChatRoom::TYPE_USER2USER,
            'previous' => [],
        ]);

        $this->assertNull($rebuilt);
    }

    public function test_rebuild_returns_null_when_message_missing(): void
    {
        $recipient = $this->createTestUser();

        $rebuilt = ChatNotification::rebuildFromDescriptor([
            'recipient' => $recipient->id,
            'sender' => null,
            'chatid' => 999999999,
            'messageid' => 999999999,
            'chattype' => ChatRoom::TYPE_USER2USER,
            'previous' => [],
        ]);

        $this->assertNull($rebuilt);
    }

    /**
     * @return array{0: ChatNotification, 1: \App\Models\User, 2: \App\Models\User, 3: ChatRoom, 4: \App\Models\ChatMessage, 5: \App\Models\ChatMessage}
     */
    private function makeNotificationWithContext(): array
    {
        $recipient = $this->createTestUser();
        $sender = $this->createTestUser();
        $room = $this->createTestChatRoom($sender, $recipient);
        $previous = $this->createTestChatMessage($room, $sender, ['message' => 'Earlier message']);
        $message = $this->createTestChatMessage($room, $sender, ['message' => 'Latest message']);

        $mailable = new ChatNotification(
            $recipient,
            $sender,
            $room,
            $message,
            ChatRoom::TYPE_USER2USER,
            collect([$previous])
        );

        return [$mailable, $recipient, $sender, $room, $message, $previous];
    }
}
