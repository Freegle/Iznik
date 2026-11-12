<?php

namespace Tests\Unit\Services;

use App\Mail\Chat\ChatNotification;
use App\Models\ChatRoom;
use App\Models\ChatRoster;
use App\Services\ChatNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * A reply that was held and is then released is mailed to the poster, even when a later message in the chat was
 * mailed while it was held. The roster keeps one number, the newest message mailed, so a message below it needs more
 * than the look-back to be picked up.
 */
class ChatNotificationReleasedBehindWatermarkTest extends TestCase
{
    protected ChatNotificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ChatNotificationService();
        Mail::fake();
    }

    public function test_a_reply_released_after_a_later_message_was_mailed_is_mailed(): void
    {
        $replier = $this->createTestUser();
        $poster = $this->createTestUser();
        $group = $this->createTestGroup();
        $post = $this->createTestMessage($poster, $group);
        $room = $this->createTestChatRoom($replier, $poster, ['latestmessage' => now()]);

        ChatRoster::create(['chatid' => $room->id, 'userid' => $replier->id, 'lastmsgemailed' => null]);
        ChatRoster::create(['chatid' => $room->id, 'userid' => $poster->id, 'lastmsgemailed' => null]);

        // The first reply is held by rippling, and a later message in the chat passes and is mailed.
        $held = $this->createTestChatMessage($room, $replier, ['message' => 'First reply', 'date' => now()->subDays(3)]);
        $holdId = (int) DB::table('rippling_held_replies')->insertGetId([
            'chatid' => $room->id,
            'chatmsgid' => $held->id,
            'msgid' => $post->id,
            'replieruserid' => $replier->id,
            'lat' => 51.5,
            'lng' => -0.1,
            'status' => 'held',
            'created_at' => now()->subDays(3),
        ]);
        $this->createTestChatMessage($room, $replier, ['message' => 'A later note', 'date' => now()->subMinutes(5)]);

        $toPoster = fn () => Mail::sent(ChatNotification::class)->filter(
            fn (ChatNotification $mail) => $mail->hasTo($poster->email_preferred)
        )->count();

        $this->service->notifyByEmail(ChatRoom::TYPE_USER2USER, $room->id);
        $this->assertSame(1, $toPoster(), 'the later message is mailed while the first is held');

        // The hold is released.
        DB::table('rippling_held_replies')->where('id', $holdId)->update(['status' => 'released', 'releasedat' => now()]);

        $this->service->notifyByEmail(ChatRoom::TYPE_USER2USER, $room->id);
        $this->assertSame(2, $toPoster(), 'the released reply is mailed too, though a later message was mailed first');
    }
}
