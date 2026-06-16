<?php

namespace App\Services\Ripple;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Held external (email / TrashNothing) replies (#3 / PR C).
 *
 * In-app replies are gated by reply-eligibility (#2) so they never arrive early.
 * Email/TN bypass that gate, so a reply from a location the post hasn't yet
 * rippled to is HELD: the chat message's delivery is blocked via the existing
 * `reviewrequired` hold (subsequent messages inherit the hold, as today) and a
 * `chat_messages_rippling` row records WHY (distinct from a moderator's manual
 * hold). The ripple engine releases the reply once reach covers the replier.
 *
 * Dark until wired in: the incoming-reply call site is gated behind
 * config('freegle.ripple.hold_replies').
 */
class RippleReplyService
{
    public function __construct(private ReachQueryService $reach)
    {
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
     * Record a hold: block the chat message's delivery (reviewrequired=1) and log a
     * chat_messages_rippling row as the reason. Returns the new row id.
     */
    public function hold(int $chatid, int $chatmsgid, int $msgid, int $replieruserid, float $lat, float $lng): int
    {
        DB::table('chat_messages')->where('id', $chatmsgid)->update(['reviewrequired' => 1]);

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
     * post's reach: mark released and clear the chat message's rippling hold so it
     * is delivered. Returns the number released.
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
            $this->deliver($row->id, (int) $row->chatmsgid);
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
            $this->deliver($row->id, (int) $row->chatmsgid);
        }

        return $held->count();
    }

    /**
     * The post was taken/withdrawn before coverage: mark remaining held replies
     * 'taken-gone' (NOT delivered — the replier is told separately it's gone).
     * Returns the number affected.
     */
    public function markGone(int $msgid): int
    {
        return DB::table('chat_messages_rippling')
            ->where('msgid', $msgid)
            ->where('status', 'held')
            ->update(['status' => 'taken-gone', 'releasedat' => now()]);
    }

    private function deliver(int $ripplingRowId, int $chatmsgid): void
    {
        DB::table('chat_messages')->where('id', $chatmsgid)->update(['reviewrequired' => 0]);
        DB::table('chat_messages_rippling')->where('id', $ripplingRowId)->update([
            'status' => 'released',
            'releasedat' => now(),
        ]);
    }

    private function hasReach(int $msgid): bool
    {
        return DB::table('messages_reach')->where('msgid', $msgid)->exists();
    }
}
