<?php

namespace Tests\Unit\Support;

use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\ChatRoster;
use App\Models\User;
use App\Support\EloquentUtils;
use Tests\TestCase;

/**
 * Coverage for App\Support\EloquentUtils, the Eloquent-based row re-parenter
 * used during user merge (see User::merge). Exercises both methods, the
 * dry-run branch of each, and the unique-constraint suppression in
 * reparentRowIgnore.
 */
class EloquentUtilsTest extends TestCase
{
    private function makeRoom(User $u1, User $u2): ChatRoom
    {
        return ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $u1->id,
            'user2' => $u2->id,
            'created' => now(),
        ]);
    }

    private function makeRoster(int $chatid, int $userid): ChatRoster
    {
        return ChatRoster::create([
            'chatid' => $chatid,
            'userid' => $userid,
            'status' => ChatRoster::STATUS_ONLINE,
            'date' => now(),
        ]);
    }

    public function test_reparent_row_moves_rows_via_eloquent(): void
    {
        $from = $this->createTestUser();
        $to = $this->createTestUser();
        $room = $this->makeRoom($from, $to);

        foreach (['first', 'second'] as $body) {
            ChatMessage::create([
                'chatid' => $room->id,
                'userid' => $from->id,
                'message' => $body,
                'type' => ChatMessage::TYPE_DEFAULT,
                'date' => now(),
                'processingrequired' => 0,
            ]);
        }

        EloquentUtils::reparentRow(ChatMessage::class, 'userid', $from->id, $to->id);

        $this->assertSame(0, ChatMessage::where('userid', $from->id)->count());
        $this->assertSame(2, ChatMessage::where('userid', $to->id)->count());
    }

    public function test_reparent_row_dry_run_leaves_rows_untouched(): void
    {
        $from = $this->createTestUser();
        $to = $this->createTestUser();
        $room = $this->makeRoom($from, $to);

        ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $from->id,
            'message' => 'unchanged',
            'type' => ChatMessage::TYPE_DEFAULT,
            'date' => now(),
            'processingrequired' => 0,
        ]);

        EloquentUtils::reparentRow(ChatMessage::class, 'userid', $from->id, $to->id, dryRun: true);

        $this->assertSame(1, ChatMessage::where('userid', $from->id)->count());
        $this->assertSame(0, ChatMessage::where('userid', $to->id)->count());
    }

    public function test_reparent_row_ignore_moves_rows_when_no_conflict(): void
    {
        $from = $this->createTestUser();
        $to = $this->createTestUser();
        $room = $this->makeRoom($from, $to);

        $this->makeRoster($room->id, $from->id);

        EloquentUtils::reparentRowIgnore(ChatRoster::class, 'userid', $from->id, $to->id);

        $this->assertSame(0, ChatRoster::where('chatid', $room->id)->where('userid', $from->id)->count());
        $this->assertSame(1, ChatRoster::where('chatid', $room->id)->where('userid', $to->id)->count());
    }

    public function test_reparent_row_ignore_suppresses_unique_conflict(): void
    {
        $from = $this->createTestUser();
        $to = $this->createTestUser();
        $room = $this->makeRoom($from, $to);

        // (chatid, userid) is unique. Both users already have a roster row in the
        // same chat, so reparenting $from -> $to collides with $to's existing row.
        $this->makeRoster($room->id, $from->id);
        $this->makeRoster($room->id, $to->id);

        // Must NOT throw — the unique-constraint conflict is caught and logged.
        EloquentUtils::reparentRowIgnore(ChatRoster::class, 'userid', $from->id, $to->id);

        // $to keeps its row; $from's row could not move and is left in place.
        $this->assertSame(1, ChatRoster::where('chatid', $room->id)->where('userid', $to->id)->count());
        $this->assertSame(1, ChatRoster::where('chatid', $room->id)->where('userid', $from->id)->count());
    }

    public function test_reparent_row_ignore_dry_run_leaves_rows_untouched(): void
    {
        $from = $this->createTestUser();
        $to = $this->createTestUser();
        $room = $this->makeRoom($from, $to);

        $this->makeRoster($room->id, $from->id);

        EloquentUtils::reparentRowIgnore(ChatRoster::class, 'userid', $from->id, $to->id, dryRun: true);

        $this->assertSame(1, ChatRoster::where('chatid', $room->id)->where('userid', $from->id)->count());
        $this->assertSame(0, ChatRoster::where('chatid', $room->id)->where('userid', $to->id)->count());
    }
}
