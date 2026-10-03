<?php

namespace Tests\Unit\Services;

use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\Message;
use App\Services\TakedownService;
use Illuminate\Support\Facades\DB;
use Tests\Support\SeedsReachCells;
use Tests\TestCase;

class TakedownServiceTest extends TestCase
{
    use SeedsReachCells;

    protected TakedownService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TakedownService();
    }

    public function test_takes_down_a_message_and_tells_poster(): void
    {
        $user = $this->createTestUser();
        $message = $this->createTestMessage($user);

        $result = $this->service->takeDown($message->id, 'This looks like spam.');

        $this->assertTrue($result);

        $message->refresh();
        $this->assertNotNull($message->deleted);
        $this->assertEquals(Message::COLLECTION_REJECTED, $message->collection);
        $reasons = json_decode($message->contentcheck_reasons, true);
        $this->assertContains('This looks like spam.', $reasons);

        $room = ChatRoom::where('user1', $user->id)
            ->where('chattype', ChatRoom::TYPE_USER2MOD)
            ->first();
        $this->assertNotNull($room);

        $chatMessage = ChatMessage::where('chatid', $room->id)
            ->where('refmsgid', $message->id)
            ->first();
        $this->assertNotNull($chatMessage);
        $this->assertEquals(ChatMessage::TYPE_SYSTEM, $chatMessage->type);
        $this->assertStringContainsString('This looks like spam.', $chatMessage->message);
    }

    public function test_takedown_returns_false_for_missing_message(): void
    {
        $result = $this->service->takeDown(999999999, 'gone');

        $this->assertFalse($result);
    }

    public function test_repeat_takedown_appends_reason_but_does_not_double_notify(): void
    {
        $user = $this->createTestUser();
        $message = $this->createTestMessage($user);

        $this->service->takeDown($message->id, 'First reason.');
        $this->service->takeDown($message->id, 'Second reason.');

        $message->refresh();
        $reasons = json_decode($message->contentcheck_reasons, true);
        $this->assertContains('First reason.', $reasons);
        $this->assertContains('Second reason.', $reasons);

        $room = ChatRoom::where('user1', $user->id)
            ->where('chattype', ChatRoom::TYPE_USER2MOD)
            ->first();
        $count = ChatMessage::where('chatid', $room->id)
            ->where('refmsgid', $message->id)
            ->count();
        $this->assertEquals(1, $count);
    }

    public function test_restore_sets_approved_and_clears_deleted(): void
    {
        $user = $this->createTestUser();
        $message = $this->createTestMessage($user);
        $this->service->takeDown($message->id, 'Spam.');

        $result = $this->service->restore($message->id);

        $this->assertTrue($result);
        $message->refresh();
        $this->assertNull($message->deleted);
        $this->assertEquals(Message::COLLECTION_APPROVED, $message->collection);
    }

    public function test_restore_tells_poster(): void
    {
        $user = $this->createTestUser();
        $message = $this->createTestMessage($user);
        $this->service->takeDown($message->id, 'Spam.');

        $this->service->restore($message->id);

        $room = ChatRoom::where('user1', $user->id)
            ->where('chattype', ChatRoom::TYPE_USER2MOD)
            ->first();
        $chatMessages = ChatMessage::where('chatid', $room->id)
            ->where('refmsgid', $message->id)
            ->orderBy('id')
            ->get();

        // One for the takedown, one for the restore.
        $this->assertEquals(2, $chatMessages->count());
        $this->assertEquals(ChatMessage::TYPE_SYSTEM, $chatMessages->last()->type);
        $this->assertStringContainsString('restored', strtolower($chatMessages->last()->message));
    }

    public function test_restore_on_message_never_taken_down_does_not_notify(): void
    {
        $user = $this->createTestUser();
        $message = $this->createTestMessage($user);

        $this->service->restore($message->id);

        $room = ChatRoom::where('user1', $user->id)
            ->where('chattype', ChatRoom::TYPE_USER2MOD)
            ->first();
        $this->assertNull($room);
    }

    public function test_restore_returns_false_for_missing_message(): void
    {
        $result = $this->service->restore(999999999);

        $this->assertFalse($result);
    }

    private const REACH_WKT = 'POLYGON((-0.1 51.5, -0.099 51.5, -0.099 51.501, -0.1 51.501, -0.1 51.5))';

    private function seedReachRow(int $msgid, string $status = 'expanding'): void
    {
        DB::insert(
            'INSERT INTO rippling_reach (msgid, lat, lng, polygon_cells, outer_bound, status, next_expansion_at, created_at, updated_at)
             VALUES (?, 51.5074, -0.1278, ?, ST_Envelope(ST_GeomFromText(?, 3857)), ?, ?, NOW(), NOW())',
            [$msgid, $this->reachCellsFor(self::REACH_WKT), self::REACH_WKT, $status, now()->addHour()]
        );
    }

    public function test_takedown_freezes_an_expanding_reach_row(): void
    {
        $user = $this->createTestUser();
        $message = $this->createTestMessage($user);
        $this->seedReachRow($message->id);

        $this->service->takeDown($message->id, 'Spam.');

        $row = DB::table('rippling_reach')->where('msgid', $message->id)->first();
        $this->assertSame('held', $row->status);
        $this->assertNull($row->next_expansion_at);
    }

    public function test_takedown_with_no_reach_row_does_not_error(): void
    {
        $user = $this->createTestUser();
        $message = $this->createTestMessage($user);

        $result = $this->service->takeDown($message->id, 'Spam.');

        $this->assertTrue($result);
        $this->assertNull(DB::table('rippling_reach')->where('msgid', $message->id)->first());
    }

    public function test_restore_does_not_unfreeze_a_held_reach_row(): void
    {
        $user = $this->createTestUser();
        $message = $this->createTestMessage($user);
        $this->seedReachRow($message->id, 'held');

        $this->service->takeDown($message->id, 'Spam.');
        $this->service->restore($message->id);

        $row = DB::table('rippling_reach')->where('msgid', $message->id)->first();
        $this->assertSame('held', $row->status, 'a restored post gets fresh reach, not a resumed frozen one');
    }
}
