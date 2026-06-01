<?php

namespace App\Services;

use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Chase up users who are expected to reply in a User2User chat but haven't.
 *
 * Mirrors iznik-server ChatRoom::chaseupExpected()
 * (include/chat/ChatRoom.php:2381-2524), driven by cron/chat_chaseup_expected.php.
 *
 * For each (expectee, chat) where a message is flagged replyexpected=1 /
 * replyreceived=0, is at least EXPECTED_CHASEUP_DAYS old, the expectee has been
 * idle for at least EXPECTED_GRACE_MINUTES (TIMESTAMPDIFF against lastaccess),
 * the chat is User2User and not blocked, we send a "WAITING FOR REPLY:" chat
 * notification to the expectee.
 */
class ChatChaseupExpectedService
{
    /** Messages older than this many days are eligible — V1 EXPECTED_CHASEUP = 5. */
    public const EXPECTED_CHASEUP_DAYS = 5;

    /** Minutes of expectee inactivity before chasing — V1 EXPECTED_GRACE = 24 * 60. */
    public const EXPECTED_GRACE_MINUTES = 24 * 60;

    public function __construct(
        private readonly ChatNotificationService $chatNotificationService
    ) {
    }

    /**
     * @return int Number of chase-up emails sent.
     */
    public function chaseupExpected(bool $dryRun = false): int
    {
        // V1: strtotime("Midnight 5 days ago") — midnight of the day 5 days back.
        $oldest = Carbon::today()->subDays(self::EXPECTED_CHASEUP_DAYS)->toDateTimeString();

        $rows = DB::table('users_expected')
            ->join('users', 'users.id', '=', 'users_expected.expectee')
            ->join('chat_messages', 'chat_messages.id', '=', 'users_expected.chatmsgid')
            ->join('chat_rooms', 'chat_rooms.id', '=', 'chat_messages.chatid')
            ->leftJoin('chat_roster', function ($join) {
                $join->on('chat_roster.userid', '=', 'users_expected.expectee')
                    ->on('chat_roster.chatid', '=', 'chat_messages.chatid');
            })
            ->where('chat_messages.date', '>=', $oldest)
            ->where('chat_messages.replyexpected', 1)
            ->where('chat_messages.replyreceived', 0)
            // V1 parity: `chat_roster.status != 'Blocked'`. In SQL this also
            // excludes rows with no roster status (NULL), matching iznik-server.
            ->where('chat_roster.status', '!=', ChatRoom::STATUS_BLOCKED)
            ->whereRaw('TIMESTAMPDIFF(MINUTE, chat_messages.date, users.lastaccess) >= ?', [self::EXPECTED_GRACE_MINUTES])
            ->where('chat_rooms.chattype', ChatRoom::TYPE_USER2USER)
            ->orderBy('chat_messages.id', 'desc')
            ->get([
                'chat_messages.id as chatmsgid',
                'chat_messages.chatid',
                'users_expected.expectee',
            ]);

        // V1 groups by (expectee, chatid) so each waiting user gets at most one
        // email per chat. We keep the most recent qualifying message per pair.
        $seen = [];
        $chased = 0;

        foreach ($rows as $row) {
            $key = $row->expectee . ':' . $row->chatid;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $recipient = User::find($row->expectee);
            if (! $recipient || ! $recipient->email_preferred) {
                continue;
            }

            // V1: mail only if the user has email notifications on, or is a
            // TrashNothing user (who are always mailed).
            if (! ($recipient->notifsOn(User::NOTIFS_EMAIL) || $recipient->isTN())) {
                continue;
            }

            $chatRoom = ChatRoom::find($row->chatid);
            if (! $chatRoom) {
                continue;
            }

            $message = ChatMessage::with(['user', 'refMessage'])->find($row->chatmsgid);
            if (! $message) {
                continue;
            }

            // The expected message comes from the other party; if it is the
            // recipient's own message there is nothing to chase (V1 $justmine).
            if ((int) $message->userid === (int) $recipient->id) {
                continue;
            }

            $otherUserId = (int) $chatRoom->user1 === (int) $recipient->id
                ? $chatRoom->user2
                : $chatRoom->user1;
            $sender = User::find($otherUserId);

            if (! $dryRun) {
                $this->chatNotificationService->sendChaseupExpected($recipient, $sender, $chatRoom, $message);
            }

            $chased++;
        }

        return $chased;
    }
}
