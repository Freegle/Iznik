<?php

namespace App\Services;

use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The single place a post's visibility changes because it was taken down or
 * restored. Callers are: ContentCheckService (a danger signal on arrival),
 * ReportResolutionService (a report reaches quorum), and — indirectly, once
 * built — a background_tasks TASK_TELL_POSTER handler for actions taken
 * through ModTools' PATCH /message, so the same "poster told" message goes
 * out however the takedown was decided.
 *
 * Nothing here waits for a person: a takedown is immediate and always comes
 * with a reason the poster can act on, delivered into their User2Mod room.
 * A moderator can restore at any time — that is the only human step.
 */
class TakedownService
{
    /**
     * Mark a post taken down and tell the poster why. Safe to call more than
     * once (e.g. a second, different reason arrives) — the reason is
     * appended and the poster is only notified the first time a message
     * moves from live to taken down.
     */
    public function takeDown(int $msgid, string $why, ?int $by = null): bool
    {
        $message = Message::find($msgid);

        if (! $message) {
            Log::warning("TakedownService::takeDown: message #{$msgid} not found");

            return false;
        }

        $alreadyDown = $message->deleted !== null;

        $existing = $message->contentcheck_reasons
            ? (json_decode($message->contentcheck_reasons, true) ?: [])
            : [];

        $message->deleted = now();
        $message->collection = Message::COLLECTION_REJECTED;
        $message->contentcheck_reasons = json_encode(array_values(array_unique(array_merge($existing, [$why]))));
        $message->save();

        $this->freezeReachIfNoLongerApproved($message);

        Log::info("TakedownService: took down message #{$msgid}: {$why}", ['by' => $by]);

        if (! $alreadyDown) {
            $this->tellPoster(
                $message,
                "We've taken down your post \"{$message->subject}\". {$why} "
                ."You're welcome to fix it and post again, or reply here if you think this is wrong."
            );
        }

        return true;
    }

    /**
     * Restore a taken-down post to live. Only notifies the poster if the
     * post was actually down (so calling this on a live post is a no-op
     * beyond the return value).
     */
    public function restore(int $msgid, ?int $by = null): bool
    {
        $message = Message::find($msgid);

        if (! $message) {
            Log::warning("TakedownService::restore: message #{$msgid} not found");

            return false;
        }

        $wasDown = $message->deleted !== null;

        $message->deleted = null;
        $message->collection = Message::COLLECTION_APPROVED;
        $message->save();

        Log::info("TakedownService: restored message #{$msgid}", ['by' => $by]);

        if ($wasDown) {
            $this->tellPoster(
                $message,
                "Good news — we've restored your post \"{$message->subject}\". It's live again."
            );
        }

        return true;
    }

    /**
     * Freeze this post's ripple once it is no longer live-Approved. Freezing
     * keeps the rippling_reach row but sets status='held' and
     * next_expansion_at=NULL, so: (a) it stops expanding outward, (b)
     * ExpandService's queries skip it, and (c) it is treated as reached (not
     * re-mailed) rather than un-reached if the member checks again.
     *
     * Mirrors iznik-server-go/microvolunteering FreezeReachIfOriginPending
     * exactly, including its asymmetry: restoring a post does not unfreeze
     * this — a restored post gets fresh reach through the normal approval
     * path, it does not resume a frozen one. No-op if the post is still
     * live-Approved, or there is no reach row.
     */
    private function freezeReachIfNoLongerApproved(Message $message): void
    {
        if ($message->deleted === null && $message->collection === Message::COLLECTION_APPROVED) {
            return;
        }

        DB::table('rippling_reach')
            ->where('msgid', $message->id)
            ->where('status', '<>', 'held')
            ->update(['status' => 'held', 'next_expansion_at' => null]);
    }

    /**
     * Send a system notice into the poster's User2Mod room. Pre-processed
     * (skip ChatProcessService) — this is a synthetic notice, not something
     * for a spam/hold check to gate, and it must reach the poster whatever
     * their own posting state is.
     */
    private function tellPoster(Message $message, string $text): void
    {
        $userId = (int) $message->fromuser;

        if ($userId <= 0) {
            return;
        }

        $room = ChatRoom::getOrCreateUser2Mod($userId);

        if (! $room) {
            Log::warning("TakedownService: could not open User2Mod room for user #{$userId}");

            return;
        }

        ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $userId,
            'message' => $text,
            'type' => ChatMessage::TYPE_SYSTEM,
            'refmsgid' => $message->id,
            'date' => now(),
            'platform' => 0,
            'reviewrequired' => 0,
            'processingrequired' => 0,
            'processingsuccessful' => 1,
            'replyreceived' => 0,
        ]);
    }
}
