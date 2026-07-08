<?php

namespace App\Services\Ripple;

use App\Models\ChatMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Held external (email / TrashNothing) replies (#3 / PR C).
 *
 * In-app replies are gated by reply-eligibility (#2) so they never arrive early.
 * Email/TN bypass that gate, so a reply from a location the post hasn't yet
 * rippled to is HELD by recording a `rippling_held_replies` row (status='held').
 *
 * IMPORTANT (per adversarial review): this does NOT touch chat_messages.reviewrequired.
 * That bit is shared with the spam/mod-review hold — clearing it on release could free
 * a genuine mod/spam hold, and rippling-held messages would otherwise pollute the mod
 * review queue and be auto-rejected after 7 days. Instead the rippling hold is enforced
 * by GATING DELIVERY on the rippling_held_replies row: the poster-notification queries
 * skip a chat message while it has a non-'released' rippling row (see deliveryGateSql()).
 * Releasing simply sets status='released', after which the normal notification pipeline
 * delivers it (no reviewrequired / processingsuccessful changes needed).
 *
 * Inert until the reach engine is live: shouldHold returns false while a post has no
 * reach row (hasReach fails open if rippling_reach is absent), so until then no reply is
 * ever held, rippling_held_replies stays empty, and the delivery gate is always true.
 */
class RippleReplyService
{
    public function __construct(private ReachQueryService $reach)
    {
    }

    /**
     * SQL gate for poster-notification queries: a chat message must NOT be delivered
     * while it has a non-'released' rippling row. Add `AND <this>` to the WHERE of any
     * query that decides whether to notify the poster of a chat message.
     *
     * @param string $cmAlias the chat_messages alias/column to match on (e.g. 'chat_messages.id')
     */
    public static function deliveryGateSql(string $cmAlias = 'chat_messages.id'): string
    {
        return "NOT EXISTS (SELECT 1 FROM rippling_held_replies cmr
                WHERE cmr.chatmsgid = {$cmAlias} AND cmr.status <> 'released')";
    }

    /** Is this chat message currently held by rippling (delivery blocked)? */
    public function isDeliveryHeld(int $chatmsgid): bool
    {
        return DB::table('rippling_held_replies')
            ->where('chatmsgid', $chatmsgid)
            ->where('status', '<>', 'released')
            ->exists();
    }

    /**
     * Should an external reply to post $msgid from (lat,lng) be held? Only when the
     * post is actively rippling (has a reach row) AND the replier is outside the
     * current reach. No reach row → not rippling → deliver normally. Unknown
     * location → cannot test → deliver normally.
     */
    public function shouldHold(int $msgid, ?float $lat, ?float $lng): bool
    {
        if ($lat === null || $lng === null) {
            return false;
        }
        if (!$this->hasReach($msgid)) {
            return false;
        }

        return !$this->reach->isWithinReach($msgid, $lat, $lng);
    }

    /**
     * Record a hold (delivery is blocked via deliveryGateSql, NOT reviewrequired).
     * Returns the new rippling_held_replies row id.
     */
    public function hold(int $chatid, int $chatmsgid, int $msgid, int $replieruserid, float $lat, float $lng, string $source = 'email'): int
    {
        $id = (int) DB::table('rippling_held_replies')->insertGetId([
            'chatid' => $chatid,
            'chatmsgid' => $chatmsgid,
            'msgid' => $msgid,
            'replieruserid' => $replieruserid,
            'source' => $source,
            'lat' => $lat,
            'lng' => $lng,
            'status' => 'held',
            'created_at' => now(),
        ]);
        $this->recordEvent('held');

        return $id;
    }

    /**
     * Bump the per-day counter for a held-reply state transition (#3 / §15 instrumentation),
     * surfaced read-only in sysadmin. Best-effort: errors are swallowed so instrumentation
     * never blocks the hold/release path.
     */
    private function recordEvent(string $event): void
    {
        try {
            DB::statement(
                'INSERT INTO rippling_event_metrics (day, event, count) VALUES (CURDATE(), ?, 1) '
                . 'ON DUPLICATE KEY UPDATE count = count + 1',
                [$event]
            );
        } catch (\Throwable $e) {
            Log::warning("ripple: recordEvent({$event}) failed: {$e->getMessage()}");
        }
    }

    /**
     * Release every held reply for $msgid whose replier location is now inside the
     * post's reach (status→released; the notification pipeline then delivers it).
     * Returns the number released.
     */
    public function releaseCovered(int $msgid): int
    {
        $held = DB::table('rippling_held_replies')
            ->where('msgid', $msgid)
            ->where('status', 'held')
            ->get();

        $released = 0;
        foreach ($held as $row) {
            if ($row->lat === null || $row->lng === null) {
                continue;
            }
            if (!$this->reach->isWithinReach($msgid, (float) $row->lat, (float) $row->lng)) {
                continue;
            }
            $this->release($row->id);
            $released++;
        }

        if ($released > 0) {
            Log::info('ripple:released-replies', ['msgid' => $msgid, 'count' => $released]);
        }

        return $released;
    }

    /**
     * Release all still-held replies for $msgid regardless of coverage — used when
     * the reach has maxed out without covering everyone (don't strand genuine
     * interest). Returns the number released.
     */
    public function releaseAll(int $msgid): int
    {
        $held = DB::table('rippling_held_replies')
            ->where('msgid', $msgid)
            ->where('status', 'held')
            ->get();

        foreach ($held as $row) {
            $this->release($row->id);
        }

        return $held->count();
    }

    /**
     * The post was taken/withdrawn before coverage: mark remaining held replies
     * 'taken-gone' (NOT delivered — the delivery gate still blocks them; the replier
     * is told separately it's gone). Returns the number affected.
     */
    public function markGone(int $msgid): int
    {
        $held = DB::table('rippling_held_replies')
            ->where('msgid', $msgid)
            ->where('status', 'held')
            ->get();

        foreach ($held as $row) {
            DB::table('rippling_held_replies')->where('id', $row->id)->update([
                'status' => 'taken-gone',
                'releasedat' => now(),
            ]);
            $this->recordEvent('taken_gone');
            $this->notifyReplierGone($row);
        }

        return $held->count();
    }

    /**
     * Tell the replier their held reply can't be delivered because the post has been
     * taken/withdrawn. Posts a System message into their chat, authored by the poster (the
     * other party in the DM) so the chat-notification path notifies the REPLIER. The message
     * is inserted pre-processed (processingrequired=0, processingsuccessful=1) to skip
     * ChatProcessService, so a spammer/banned poster or a chain-hold cannot suppress it.
     * Best-effort: a missing chat or poster is skipped rather than aborting the batch.
     */
    private function notifyReplierGone(object $row): void
    {
        try {
            $chat = DB::table('chat_rooms')->where('id', $row->chatid)->first(['user1', 'user2']);
            if ($chat === null) {
                return;
            }
            $posterId = ((int) $chat->user1 === (int) $row->replieruserid)
                ? (int) $chat->user2
                : (int) $chat->user1;
            if ($posterId <= 0) {
                return;
            }

            ChatMessage::create([
                'chatid' => (int) $row->chatid,
                'userid' => $posterId,
                'message' => "Sorry — the item you replied about has now been taken, so it's no longer available.",
                'type' => ChatMessage::TYPE_SYSTEM,
                'refmsgid' => (int) $row->msgid,
                'date' => now(),
                'platform' => 0,
                'reviewrequired' => 0,
                // Insert pre-processed (skip ChatProcessService) so a spammer/banned poster, or
                // a chain-hold on a preceding message, can't suppress this notice — it is a
                // synthetic system message with no user text to spam-check, and reaches the
                // replier via the normal chat-notification path (no rippling row → gate open).
                'processingrequired' => 0,
                'processingsuccessful' => 1,
                'replyreceived' => 0,
            ]);
        } catch (\Throwable $e) {
            Log::warning("ripple: failed to tell replier post is gone (rippling row {$row->id}): {$e->getMessage()}");
        }
    }

    private function release(int $ripplingRowId): void
    {
        DB::table('rippling_held_replies')->where('id', $ripplingRowId)->update([
            'status' => 'released',
            'releasedat' => now(),
        ]);
        $this->recordEvent('released');
    }

    private function hasReach(int $msgid): bool
    {
        try {
            return DB::table('rippling_reach')->where('msgid', $msgid)->exists();
        } catch (\Throwable $e) {
            // rippling_reach is created by the reach engine (PR A). Until that's
            // deployed the table may not exist — fail open ("not rippling") so an
            // external reply is delivered normally rather than crashing incoming-mail
            // processing. This is what keeps held replies inert before the engine is live.
            Log::warning('ripple:hasReach query failed (rippling_reach missing?)', [
                'msgid' => $msgid,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
