<?php

namespace Tests\Feature\Chat;

use App\Models\ChatRoom;
use App\Models\ChatRoster;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests for chat:repair-merged-rooms.
 *
 * The fixture is the shape the old email-verify merge left: the loser account
 * soft-deleted at the merge, its rooms still pointing at it, its messages
 * already re-attributed to the survivor, and no roster rows moved.
 */
class RepairMergedRoomsCommandTest extends TestCase
{
    private const MERGED_AT = '2026-05-28 17:49:06';

    private User $loser;

    private User $survivor;

    private string $pairsFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->survivor = $this->createTestUser();
        $this->loser = $this->createTestUser();
        $this->loser->deleted = self::MERGED_AT;
        $this->loser->save();
        $this->pairsFile = tempnam(sys_get_temp_dir(), 'pairs');
        file_put_contents($this->pairsFile, "loser,survivor\n{$this->loser->id},{$this->survivor->id}\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->pairsFile);
        parent::tearDown();
    }

    private function message(ChatRoom $room, User $from, string $date, array $extra = []): int
    {
        return $this->createTestChatMessage($room, $from, ['date' => $date] + $extra)->id;
    }

    private function roster(ChatRoom $room, User $user, array $extra = []): int
    {
        return ChatRoster::create(['chatid' => $room->id, 'userid' => $user->id] + $extra)->id;
    }

    private function rosterRow(int $chatId, int $userId): ?object
    {
        return DB::table('chat_roster')->where('chatid', $chatId)->where('userid', $userId)->first();
    }

    private function repair(array $options = []): \Illuminate\Testing\PendingCommand
    {
        return $this->artisan('chat:repair-merged-rooms', ['--pairs' => $this->pairsFile] + $options);
    }

    public function test_a_room_the_survivor_does_not_have_is_handed_over(): void
    {
        $other = $this->createTestUser();
        $room = $this->createTestChatRoom($this->loser, $other);
        // The merge re-attributed the loser's own message to the survivor.
        $before = $this->message($room, $this->survivor, '2026-05-01 10:00:00');
        $after = $this->message($room, $other, '2026-06-10 10:00:00');
        $this->roster($room, $other);

        $this->repair()->assertExitCode(0);

        $room->refresh();
        $this->assertSame($this->survivor->id, (int) $room->user1);
        $this->assertSame($other->id, (int) $room->user2);
        $this->assertSame(2, DB::table('chat_messages')->where('chatid', $room->id)->count());

        // The survivor can now be emailed. What was sent before the merge counts
        // as read; the message sent to the dead account afterwards never reached
        // them, so it stays unread.
        $row = $this->rosterRow($room->id, $this->survivor->id);
        $this->assertNotNull($row);
        $this->assertSame($before, (int) $row->lastmsgseen);
        $this->assertSame($after, (int) $row->lastmsgemailed);
    }

    public function test_the_loser_roster_row_moves_to_the_survivor_keeping_its_marks(): void
    {
        $other = $this->createTestUser();
        $room = $this->createTestChatRoom($other, $this->loser);
        $msg = $this->message($room, $other, '2026-05-01 10:00:00');
        $rowId = $this->roster($room, $this->loser, ['lastmsgseen' => $msg, 'lastmsgemailed' => $msg]);

        $this->repair()->assertExitCode(0);

        $row = DB::table('chat_roster')->where('id', $rowId)->first();
        $this->assertSame($this->survivor->id, (int) $row->userid);
        $this->assertSame($msg, (int) $row->lastmsgseen);
        $this->assertSame($this->survivor->id, (int) $room->fresh()->user2);
        $this->assertNull($this->rosterRow($room->id, $this->loser->id));
    }

    public function test_a_room_the_survivor_already_has_is_consolidated_into_it(): void
    {
        $other = $this->createTestUser();
        $target = $this->createTestChatRoom($this->survivor, $other, ['latestmessage' => '2026-04-01 09:00:00']);
        $old = $this->message($target, $other, '2026-04-01 09:00:00');
        $this->roster($target, $this->survivor, ['lastmsgseen' => $old]);
        $this->roster($target, $other);

        // The loser's room with the same person, the other way round.
        $stranded = $this->createTestChatRoom($other, $this->loser, ['latestmessage' => '2026-06-10 10:00:00']);
        $m1 = $this->message($stranded, $other, '2026-05-01 10:00:00');
        $m2 = $this->message($stranded, $other, '2026-06-10 10:00:00');
        $this->roster($stranded, $other);
        $this->roster($stranded, $this->loser, ['lastmsgseen' => $m1]);

        // Something elsewhere refers to the stranded room.
        $elsewhere = $this->createTestChatRoom($this->createTestUser(), $this->createTestUser());
        $ref = $this->message($elsewhere, $other, '2026-05-02 10:00:00', ['refchatid' => $stranded->id]);

        $this->repair()->assertExitCode(0);

        $this->assertNull(ChatRoom::find($stranded->id));
        $this->assertEqualsCanonicalizing([$old, $m1, $m2], DB::table('chat_messages')->where('chatid', $target->id)->pluck('id')->all());
        $this->assertSame($target->id, (int) DB::table('chat_messages')->where('id', $ref)->value('refchatid'));
        $this->assertSame($target->id, (int) DB::table('chat_room_redirects')->where('old_id', $stranded->id)->value('new_id'));
        $this->assertSame('2026-06-10 10:00:00', (string) $target->fresh()->latestmessage);

        // One roster row each in the target, and no strays.
        $this->assertSame(2, DB::table('chat_roster')->where('chatid', $target->id)->count());
        $this->assertSame(0, DB::table('chat_roster')->where('chatid', $stranded->id)->count());

        // History from before the merge does not come back unread; the message
        // after it, which never reached them, does.
        $this->assertSame($m1, (int) $this->rosterRow($target->id, $this->survivor->id)->lastmsgseen);
    }

    public function test_the_read_mark_is_not_raised_past_an_unread_message_in_the_target(): void
    {
        $other = $this->createTestUser();
        $target = $this->createTestChatRoom($this->survivor, $other);
        $this->roster($target, $this->survivor, ['lastmsgseen' => null]);
        $this->message($target, $other, '2026-04-01 09:00:00'); // unread, and older
        $stranded = $this->createTestChatRoom($this->loser, $other);
        $this->message($stranded, $other, '2026-05-01 10:00:00');

        $this->repair()->assertExitCode(0);

        $this->assertNull($this->rosterRow($target->id, $this->survivor->id)->lastmsgseen);
    }

    public function test_rooms_between_the_two_accounts_are_deleted(): void
    {
        // The merge deletes these: it is the same person on both sides.
        $direct = $this->createTestChatRoom($this->loser, $this->survivor);
        $this->message($direct, $this->survivor, '2026-05-01 10:00:00');
        $this->roster($direct, $this->survivor);

        $this->repair()->assertExitCode(0);

        $this->assertNull(ChatRoom::find($direct->id));
        $this->assertSame(0, DB::table('chat_messages')->where('chatid', $direct->id)->count());
    }

    public function test_a_chat_with_moderators_is_handed_over(): void
    {
        $group = $this->createTestGroup();
        $room = ChatRoom::create(['chattype' => ChatRoom::TYPE_USER2MOD, 'user1' => $this->loser->id, 'groupid' => $group->id, 'created' => now()]);
        $this->message($room, $this->survivor, '2026-05-01 10:00:00');

        $this->repair()->assertExitCode(0);

        $this->assertSame($this->survivor->id, (int) $room->fresh()->user1);
        $this->assertNotNull($this->rosterRow($room->id, $this->survivor->id));
    }

    public function test_a_dry_run_changes_nothing_and_says_what_it_would_do(): void
    {
        $other = $this->createTestUser();
        $room = $this->createTestChatRoom($this->loser, $other);
        $this->message($room, $other, '2026-05-01 10:00:00');

        $this->repair(['--dry-run' => true])
            ->expectsOutputToContain('Would change: {"repaired":1,"deleted":0,"consolidated":0,"repointed":1')
            ->assertExitCode(0);

        $this->assertSame($this->loser->id, (int) $room->fresh()->user1);
        $this->assertNull($this->rosterRow($room->id, $this->survivor->id));
    }

    public function test_a_second_run_finds_nothing_left_to_do(): void
    {
        $other = $this->createTestUser();
        $room = $this->createTestChatRoom($this->loser, $other);
        $this->message($room, $other, '2026-05-01 10:00:00');

        $this->repair()->assertExitCode(0);
        $this->repair()
            ->expectsOutputToContain('Done: {"repaired":1,"deleted":0,"consolidated":0,"repointed":0,"messages_moved":0,"roster_moved":0,"roster_added":0}')
            ->assertExitCode(0);
    }

    public function test_a_loser_that_was_recovered_is_left_alone(): void
    {
        $this->loser->deleted = null;
        $this->loser->save();
        $room = $this->createTestChatRoom($this->loser, $this->createTestUser());

        $this->repair()->expectsOutputToContain('skipped: loser is not deleted')->assertExitCode(0);

        $this->assertSame($this->loser->id, (int) $room->fresh()->user1);
    }

    public function test_a_survivor_that_is_gone_is_left_alone(): void
    {
        $this->survivor->deleted = now();
        $this->survivor->save();
        $room = $this->createTestChatRoom($this->loser, $this->createTestUser());

        $this->repair()->expectsOutputToContain('skipped: survivor is not a live account')->assertExitCode(0);

        $this->assertSame($this->loser->id, (int) $room->fresh()->user1);
    }

    public function test_a_bad_pairs_file_is_refused(): void
    {
        file_put_contents($this->pairsFile, "loser,survivor\n12,abc\n");

        $this->repair()->expectsOutputToContain('Line 2 is not loser,survivor')->assertExitCode(1);
    }

    public function test_no_pairs_file_is_refused(): void
    {
        $this->artisan('chat:repair-merged-rooms')->expectsOutputToContain('Give a readable --pairs file')->assertExitCode(1);
    }
}
