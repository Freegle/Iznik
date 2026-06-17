<?php

namespace App\Services\Ripple;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Held external (email / TrashNothing) replies (#3 / PR C).
 *
 * In-app replies are gated by reply-eligibility (#2) so they never arrive early.
 * Email/TN bypass that gate, so a reply from a location the post hasn't yet
 * rippled to is HELD by recording a `chat_messages_rippling` row (status='held').
 *
 * IMPORTANT (per adversarial review): this does NOT touch chat_messages.reviewrequired.
 * That bit is shared with the spam/mod-review hold — clearing it on release could free
 * a genuine mod/spam hold, and rippling-held messages would otherwise pollute the mod
 * review queue and be auto-rejected after 7 days. Instead the rippling hold is enforced
 * by GATING DELIVERY on the chat_messages_rippling row: the poster-notification queries
 * skip a chat message while it has a non-'released' rippling row (see deliveryGateSql()).
 * Releasing simply sets status='released', after which the normal notification pipeline
 * delivers it (no reviewrequired / processingsuccessful changes needed).
 *
 * Dark until wired in: BOTH the incoming-reply hold call site AND the delivery-gate
 * clauses are added behind config('freegle.ripple.hold_replies'), so with the flag off
 * chat_messages_rippling is always empty and nothing changes.
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
        return "NOT EXISTS (SELECT 1 FROM chat_messages_rippling cmr
                WHERE cmr.chatmsgid = {$cmAlias} AND cmr.status <> 'released')";
    }

    /** Is this chat message currently held by rippling (delivery blocked)? */
    public function isDeliveryHeld(int $chatmsgid): bool
    {
        return DB::table('chat_messages_rippling')
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
     * Returns the new chat_messages_rippling row id.
     */
    public function hold(int $chatid, int $chatmsgid, int $msgid, int $replieruserid, float $lat, float $lng): int
    {
        return (int) DB::table('chat_messages_rippling')->insertGetId([
            'chatid' => $chatid,
            'chatmsgid' => $chatmsgid,
            'msgid' => $msgid,
            'replieruserid' => $replieruserid,
            'lat' => $lat,
            'lng' => $lng,
            'status' => 'held',
            'created_at' => now(),
        ]);
    }

    /**
     * Release every held reply for $msgid whose replier location is now inside the
     * post's reach (status→released; the notification pipeline then delivers it).
     * Returns the number released.
     */
    public function releaseCovered(int $msgid): int
    {
        $held = DB::table('chat_messages_rippling')
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
        $held = DB::table('chat_messages_rippling')
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
        return DB::table('chat_messages_rippling')
            ->where('msgid', $msgid)
            ->where('status', 'held')
            ->update(['status' => 'taken-gone', 'releasedat' => now()]);
    }

    private function release(int $ripplingRowId): void
    {
        DB::table('chat_messages_rippling')->where('id', $ripplingRowId)->update([
            'status' => 'released',
            'releasedat' => now(),
        ]);
    }

    private function hasReach(int $msgid): bool
    {
        try {
            return DB::table('messages_reach')->where('msgid', $msgid)->exists();
        } catch (\Throwable $e) {
            // messages_reach is created by the reach engine (PR A). Until that's
            // deployed the table may not exist — fail open ("not rippling") so an
            // external reply is delivered normally rather than crashing incoming-mail
            // processing. With the hold_replies flag off this never runs anyway.
            Log::warning('ripple:hasReach query failed (messages_reach missing?)', [
                'msgid' => $msgid,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
