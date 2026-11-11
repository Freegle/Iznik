<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Finishes the chat side of an account merge that left rooms behind.
 *
 * Before July 2026 the email-verify merge moved a member's chat rooms to the
 * surviving account with one UPDATE per column. When the survivor already had
 * a room with the same person, the unique key (user1, user2, chattype) refused
 * the UPDATE, so every room in that column stayed on the loser, which was then
 * soft-deleted. The member lost sight of those conversations, and replies to
 * them went to an account nobody reads. Roster rows were never moved at all.
 *
 * This does for one (loser, survivor) pair what user/merge.go
 * mergeChatRooms now does at merge time, in the same order:
 *
 * 1. Rooms between the two accounts, or of the loser with itself, are deleted.
 * 2. A loser room whose counterparty and type the survivor already has is
 *    consolidated into the survivor's room: messages, references to the room,
 *    roster rows, the latest-message time, then the room is deleted. A
 *    redirect is recorded so mail addressed to the old room still arrives.
 * 3. The remaining loser rooms are handed to the survivor.
 * 4. The loser's roster rows go to the survivor, and the survivor gets a row
 *    in every room it now holds, so the chat mailer can reach them.
 *
 * Unlike the merge, which runs inside a request's transaction, this runs on the
 * live cluster, so every write touches one row by primary key and the steps
 * re-read the state they act on. A run that stops part-way is finished by
 * running it again.
 */
class ChatMergedRoomRepairService
{
    /** Pause between writes, so other writers get a turn on a busy table. */
    private const WRITE_PAUSE_US = 2000;

    /** Tables that point at a room and must follow its history. */
    private const ROOM_REFERENCES = [
        ['helper_repliers', 'chatid'],
        ['helper_sent_messages', 'chatid'],
        ['messages_bulk_items_interest', 'chatid'],
    ];

    private bool $dryRun = false;

    /**
     * @return array{status: string, deleted: int, consolidated: int, repointed: int, messages_moved: int, roster_moved: int, roster_added: int}
     */
    public function repairPair(int $loser, int $survivor, bool $dryRun = false): array
    {
        $this->dryRun = $dryRun;
        $stats = ['status' => 'repaired', 'deleted' => 0, 'consolidated' => 0, 'repointed' => 0,
            'messages_moved' => 0, 'roster_moved' => 0, 'roster_added' => 0];

        $skip = $this->refuse($loser, $survivor);
        if ($skip !== null) {
            $stats['status'] = $skip;

            return $stats;
        }

        $loserDeleted = DB::table('users')->where('id', $loser)->value('deleted');
        $touched = [];

        foreach ($this->loserRooms($loser) as $room) {
            $other = (int) $room->user1 === $loser ? $room->user2 : $room->user1;

            if ($other !== null && ((int) $other === $survivor || (int) $other === $loser)) {
                $this->deleteRoom($room->id);
                $stats['deleted']++;

                continue;
            }

            $target = $other === null ? null : $this->survivorRoom($survivor, (int) $other, $room->chattype);

            if ($target !== null) {
                $stats['messages_moved'] += $this->consolidate($room, $target, $loser, $survivor, $loserDeleted);
                $stats['consolidated']++;
                $touched[$target] = true;
            } else {
                $column = (int) $room->user1 === $loser ? 'user1' : 'user2';
                $this->write(fn () => DB::table('chat_rooms')->where('id', $room->id)->where($column, $loser)->update([$column => $survivor]));
                $stats['repointed']++;
                $touched[$room->id] = true;
            }
        }

        // The loser's remaining roster rows, wherever they are.
        foreach (DB::table('chat_roster')->where('userid', $loser)->pluck('id', 'chatid') as $chatId => $rowId) {
            if (DB::table('chat_roster')->where('chatid', $chatId)->where('userid', $survivor)->exists()) {
                $this->write(fn () => DB::table('chat_roster')->where('id', $rowId)->delete());
            } else {
                $this->write(fn () => DB::table('chat_roster')->where('id', $rowId)->update(['userid' => $survivor]));
                $stats['roster_moved']++;
            }
        }

        // A room the survivor now holds without a roster row would repeat the
        // never-emailed fault this repair exists to end.
        foreach (array_keys($touched) as $chatId) {
            // In a dry run nothing moved, so the loser's row still stands in
            // for the survivor's.
            $has = DB::table('chat_roster')->where('chatid', $chatId)
                ->whereIn('userid', $this->dryRun ? [$survivor, $loser] : [$survivor])->exists();
            if ($has) {
                continue;
            }

            $this->write(fn () => DB::table('chat_roster')->insertOrIgnore([
                'chatid' => $chatId,
                'userid' => $survivor,
                // What the member could have read before the merge counts as
                // read; anything sent to the deleted account after it did not
                // reach them and stays unread.
                'lastmsgseen' => DB::table('chat_messages')->where('chatid', $chatId)->where('date', '<=', $loserDeleted)->max('id'),
                // Never mail old history: the mailer's look-back would skip it
                // anyway, and this keeps it so if that ever widens.
                'lastmsgemailed' => DB::table('chat_messages')->where('chatid', $chatId)->max('id'),
            ]));
            $stats['roster_added']++;
        }

        if (! $this->dryRun) {
            Log::info('chat:repair-merged-rooms pair', ['loser' => $loser, 'survivor' => $survivor] + $stats);
        }

        return $stats;
    }

    /** Why this pair must not be touched, or null if it may be. */
    private function refuse(int $loser, int $survivor): ?string
    {
        if ($loser === $survivor) {
            return 'skipped: same account';
        }

        $l = DB::table('users')->where('id', $loser)->first(['deleted']);
        $s = DB::table('users')->where('id', $survivor)->first(['deleted']);

        if (! $l) {
            return 'skipped: loser no longer exists';
        }
        // A live loser was recovered after the merge, so its rooms are its own.
        if ($l->deleted === null) {
            return 'skipped: loser is not deleted';
        }
        if (! $s || $s->deleted !== null) {
            return 'skipped: survivor is not a live account';
        }

        return null;
    }

    private function loserRooms(int $loser)
    {
        return DB::table('chat_rooms')
            ->where(fn ($q) => $q->where('user1', $loser)->orWhere('user2', $loser))
            ->orderBy('id')
            ->get(['id', 'chattype', 'user1', 'user2', 'latestmessage']);
    }

    /**
     * The survivor's room with this counterparty and type, in either column
     * order. The lowest id if somehow there are two, as the merge does.
     */
    private function survivorRoom(int $survivor, int $other, string $chattype): ?int
    {
        $id = DB::table('chat_rooms')
            ->where('chattype', $chattype)
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('user1', $survivor)->where('user2', $other))
                ->orWhere(fn ($q) => $q->where('user1', $other)->where('user2', $survivor)))
            ->min('id');

        return $id === null ? null : (int) $id;
    }

    /** Moves everything in $room into $target and deletes $room. Returns messages moved. */
    private function consolidate(object $room, int $target, int $loser, int $survivor, string $loserDeleted): int
    {
        $from = $room->id;

        // History the member read as the old account should not come back as
        // unread once it lands in the target. Decided before anything moves:
        // the survivor's read mark rises to the last message sent before the
        // merge, unless that would pass one of the target's own messages from
        // someone else that they have not read yet.
        $seen = DB::table('chat_roster')->where('chatid', $target)->where('userid', $survivor)->first(['id', 'lastmsgseen']);
        $readUpTo = DB::table('chat_messages')->where('chatid', $from)->where('date', '<=', $loserDeleted)->max('id');
        $raiseTo = null;
        if ($seen && $readUpTo !== null && ($seen->lastmsgseen ?? 0) < $readUpTo) {
            $passesUnread = DB::table('chat_messages')
                ->where('chatid', $target)
                ->where('id', '>', $seen->lastmsgseen ?? 0)
                ->where('id', '<=', $readUpTo)
                ->where('userid', '!=', $survivor)
                ->exists();
            $raiseTo = $passesUnread ? null : $readUpTo;
        }

        $moved = 0;
        foreach (DB::table('chat_messages')->where('chatid', $from)->orderBy('id')->pluck('id') as $id) {
            $this->write(fn () => DB::table('chat_messages')->where('id', $id)->where('chatid', $from)->update(['chatid' => $target]));
            $moved++;
        }

        foreach (DB::table('chat_messages')->where('refchatid', $from)->pluck('id') as $id) {
            $this->write(fn () => DB::table('chat_messages')->where('id', $id)->update(['refchatid' => $target]));
        }

        foreach (self::ROOM_REFERENCES as [$table, $column]) {
            foreach (DB::table($table)->where($column, $from)->pluck('id') as $id) {
                $this->write(fn () => DB::table($table)->where('id', $id)->update([$column => $target]));
            }
        }

        // Roster rows: the loser's becomes the survivor's. Where the target
        // already has a row for that person, theirs is kept.
        foreach (DB::table('chat_roster')->where('chatid', $from)->get(['id', 'userid']) as $row) {
            $userid = (int) $row->userid === $loser ? $survivor : (int) $row->userid;
            if (DB::table('chat_roster')->where('chatid', $target)->where('userid', $userid)->exists()) {
                $this->write(fn () => DB::table('chat_roster')->where('id', $row->id)->delete());
            } else {
                $this->write(fn () => DB::table('chat_roster')->where('id', $row->id)->update(['chatid' => $target, 'userid' => $userid]));
            }
        }

        if ($raiseTo !== null) {
            $this->write(fn () => DB::table('chat_roster')->where('id', $seen->id)->update(['lastmsgseen' => $raiseTo]));
        }

        if ($room->latestmessage !== null) {
            $this->write(fn () => DB::table('chat_rooms')
                ->where('id', $target)
                ->where(fn ($q) => $q->whereNull('latestmessage')->orWhere('latestmessage', '<', $room->latestmessage))
                ->update(['latestmessage' => $room->latestmessage]));
        }

        $this->write(fn () => DB::table('chat_room_redirects')->insertOrIgnore(['old_id' => $from, 'new_id' => $target]));
        $this->write(fn () => DB::table('chat_rooms')->where('id', $from)->delete());

        return $moved;
    }

    /** Deletes a room and what is in it, a row at a time. */
    private function deleteRoom(int $roomId): void
    {
        foreach (DB::table('chat_messages')->where('chatid', $roomId)->pluck('id') as $id) {
            $this->write(fn () => DB::table('chat_messages')->where('id', $id)->delete());
        }
        foreach (DB::table('chat_roster')->where('chatid', $roomId)->pluck('id') as $id) {
            $this->write(fn () => DB::table('chat_roster')->where('id', $id)->delete());
        }
        $this->write(fn () => DB::table('chat_rooms')->where('id', $roomId)->delete());
    }

    private function write(callable $statement): void
    {
        if ($this->dryRun) {
            return;
        }

        $statement();
        usleep(self::WRITE_PAUSE_US);
    }
}
