<?php

namespace App\Services\Ripple;

use App\Models\ChatMessage;
use App\Services\FirstReply\MaxReachService;
use App\Services\FirstReply\Metrics;
use App\Services\FirstReply\Rollout;
use App\Support\GreatCircle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

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
    /** Memoized rippling_held_replies.dueat column check, so a pre-migration deploy is safe. */
    private static ?bool $dueAtColumn = null;

    private ?MaxReachService $maxReach;

    /**
     * $maxReach is optional so the many places that construct this by hand (tests
     * included) keep working; it is resolved from the container when omitted.
     */
    public function __construct(private ReachQueryService $reach, ?MaxReachService $maxReach = null)
    {
        $this->maxReach = $maxReach;
    }

    private function maxReach(): MaxReachService
    {
        return $this->maxReach ??= app(MaxReachService::class);
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
     *
     * One exception, the first-reply passthrough: a post that has no replies at all
     * yet does not hold its first one, provided the replier is inside the reach the
     * post will EVENTUALLY have. Nothing is given away by that - the reply was
     * always going to be allowed once the ripple got there, so the hold only
     * changes when the poster hears, not whether. On a post with replies already
     * that delay is a fair price for local-first ordering; on a post with none it
     * is charged against the posts that can least afford it, because a poster
     * cannot tell a delayed first reply from no interest at all.
     */
    public function shouldHold(int $msgid, ?float $lat, ?float $lng, ?string $band = null): bool
    {
        if ($lat === null || $lng === null) {
            return false;
        }
        if (!$this->hasReach($msgid)) {
            return false;
        }
        if ($this->reach->isWithinReach($msgid, $lat, $lng, $band)) {
            return false;
        }

        return !$this->qualifiesForFirstReplyPassthrough($msgid, $lat, $lng);
    }

    /**
     * Is this the reply the passthrough exists for: a post with (almost) no
     * repliers, and a replier the post's reach will eventually cover?
     *
     * Both switches have to be on and the max-reach geometry has to be populated;
     * any of those missing means the normal hold applies, so this can be deployed
     * ahead of the backfill without changing behaviour.
     */
    public function qualifiesForFirstReplyPassthrough(int $msgid, float $lat, float $lng): bool
    {
        if (!config('freegle.firstreply.enabled') || !config('freegle.firstreply.passthrough.enabled')) {
            return false;
        }

        // Trial arm: a post outside the rollout behaves exactly as it did before
        // any of this existed, which is what makes it a usable control.
        if (!Rollout::includes($msgid)) {
            return false;
        }

        $maxRepliers = (int) config('freegle.firstreply.passthrough.max_existing_repliers', 1);
        if ($this->distinctReplierCount($msgid) >= $maxRepliers) {
            return false;
        }

        if (!$this->maxReach()->isWithinMaxReach($msgid, $lat, $lng)) {
            return false;
        }

        // Counted separately from the web path (which records passthrough_web in
        // the Go API), because the two doors have different volumes and a change
        // in one should not be read as a change in the other.
        app(Metrics::class)->record('passthrough_email');

        // Also record it individually, with where the replier was, so the sweep
        // can work out how long this particular reply would have waited. The
        // counter says the lever fired; only this says what firing bought.
        try {
            DB::table('firstreply_passthroughs')->insert([
                'msgid' => $msgid,
                'source' => 'email',
                'lat' => $lat,
                'lng' => $lng,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Instrumentation must never decide whether a reply gets through.
            Log::warning("firstreply: could not record passthrough for {$msgid}: {$e->getMessage()}");
        }

        return true;
    }

    /**
     * How many distinct people have replied to this post, not counting the poster
     * talking on their own post. Held replies count: the poster has an answer
     * coming, so the post is not silent in the sense the passthrough is about.
     */
    private function distinctReplierCount(int $msgid): int
    {
        try {
            return DB::table('chat_messages as cm')
                ->join('messages as m', 'm.id', '=', 'cm.refmsgid')
                ->where('cm.refmsgid', $msgid)
                ->where('cm.type', ChatMessage::TYPE_INTERESTED)
                ->whereColumn('cm.userid', '<>', 'm.fromuser')
                ->distinct()
                ->count('cm.userid');
        } catch (\Throwable $e) {
            Log::warning("ripple: distinctReplierCount failed for {$msgid}: {$e->getMessage()}");

            // Cannot tell how many replies there are, so do not spend the
            // passthrough on a post that might already have plenty.
            return PHP_INT_MAX;
        }
    }

    /**
     * Record a hold (delivery is blocked via deliveryGateSql, NOT reviewrequired).
     * Returns the new rippling_held_replies row id.
     */
    public function hold(int $chatid, int $chatmsgid, int $msgid, int $replieruserid, float $lat, float $lng, string $source = 'email'): int
    {
        $now = now();
        $row = [
            'chatid' => $chatid,
            'chatmsgid' => $chatmsgid,
            'msgid' => $msgid,
            'replieruserid' => $replieruserid,
            'source' => $source,
            'lat' => $lat,
            'lng' => $lng,
            'status' => 'held',
            'created_at' => $now,
        ];

        // A hold is a delay, so it is stamped with when it comes off. Best-effort: an
        // older schema (pre-migration) has no column to stamp, and that must not stop
        // the hold - the sweep computes the due time from created_at either way.
        $due = $this->dueAt($msgid, $now, $lat, $lng);
        if ($due !== null && $this->dueAtAvailable()) {
            $row['dueat'] = $due;
        }

        $id = (int) DB::table('rippling_held_replies')->insertGetId($row);
        $this->recordEvent('held');

        return $id;
    }

    /**
     * How long a reply from $miles OUTSIDE THE REACH BOUNDARY waits before delivery.
     *
     * The hold exists so people near the item get first go, and a bounded delay is
     * the whole of it. Coverage cannot be the only exit: three in four held repliers
     * live somewhere the reach never covers even fully grown, so an exit that waits
     * for coverage strands them until the max-reach backstop days later, by which
     * time a quarter to a third of items have gone.
     *
     * The distance is from the nearest point on the reach isochrone, NOT from the item.
     * What this models is a buffer band hugging the boundary: a reply from just outside
     * the line is, in practice, one of the locals, because the boundary is a modelled
     * drive-time contour and not a fact about who can collect. "How far past the edge
     * are you" is the question that separates a near-miss from someone genuinely distant.
     *
     * Measuring from the item instead - which this did until now - ranks held repliers
     * consistently at a single instant, but is the wrong input for a timer, because the
     * reach moves and the distance from the item does not. It also scales with something
     * irrelevant: on live rows, a replier 0.36 miles outside the boundary was charged 55
     * minutes because the item happened to be 13.2 miles away, and another 1.47 miles
     * outside was charged 42 for an item 8.9 miles off. Both are near-misses; the wait
     * was decided by the size of the isochrone rather than by them.
     *
     * Measured over 566 held replies across two days on live, this takes the mean wait
     * from 50.8 to 29.2 minutes. The 269 of them sitting within two miles of the boundary
     * - very nearly half - go from 37.5 minutes to 17.1, which is about the base wait,
     * which is the intent. Five get marginally longer: their reach polygon does not
     * contain its own origin, so the edge is a shade further than the centre.
     */
    public function delayMinutesForMiles(float $miles): float
    {
        $base = (float) config('freegle.ripple.reply_delay.base_minutes', 15);
        $perMile = (float) config('freegle.ripple.reply_delay.per_mile_minutes', 3);
        $max = (float) config('freegle.ripple.reply_delay.max_minutes', 180);

        return min($max, $base + $perMile * max(0.0, $miles));
    }

    /**
     * When a reply held at $heldAt from ($lat,$lng) is due, or null when the post has
     * no reach row to measure the distance from (it is not rippling, so the ordinary
     * paths deal with it).
     */
    private function dueAt(int $msgid, Carbon $heldAt, ?float $lat, ?float $lng): ?Carbon
    {
        $origin = $this->reachOrigin($msgid);

        return $origin === null ? null : $this->dueFrom($msgid, $origin, $heldAt, $lat, $lng);
    }

    /**
     * As dueAt, with the reach origin already in hand - the sweep looks it up once per
     * post rather than once per held reply. The origin is only the fallback measure now;
     * the real one is the distance past the reach boundary.
     *
     * @param array{lat:float,lng:float} $origin
     */
    private function dueFrom(int $msgid, array $origin, Carbon $heldAt, ?float $lat, ?float $lng): Carbon
    {
        // Unknown replier location: the distance term is unmeasurable, so they get the
        // base delay. That is the safe end - it never holds someone longer for being
        // unlocatable, and coverage cannot release them either (that test needs a point).
        if ($lat === null || $lng === null) {
            return $heldAt->copy()->addMinutes($this->delayMinutesForMiles(0.0));
        }

        $miles = $this->milesOutsideReach($msgid, $lat, $lng);

        if ($miles === null) {
            // No usable reach geometry (unreadable or invalid polygon). Fall back to the
            // old measure rather than dropping the stamp: a hold with no due time is the
            // failure mode the delay exists to prevent, so a worse number beats none.
            Log::warning("ripple: falling back to origin distance for {$msgid}");
            $miles = GreatCircle::distanceMiles($origin['lat'], $origin['lng'], $lat, $lng);
        }

        return $heldAt->copy()->addMinutes($this->delayMinutesForMiles($miles));
    }

    /**
     * How far ($lat,$lng) lies beyond the post's current reach boundary, in miles, or
     * null when it cannot be measured. Zero for a point inside the reach.
     *
     * Deliberately re-tagged, not trusted. rippling_reach.polygon is DECLARED SRID 3857
     * but stores raw lng/lat degrees, a site-wide mislabel. ST_Distance on it as stored
     * therefore returns coordinate degrees, which are anisotropic - the same number means
     * a different distance north-south than east-west - and cannot be turned into miles.
     * Re-tagging to 4326 makes it a genuine geographic distance in metres.
     *
     * NO ST_SwapXY, despite 4326 being nominally latitude-first. Measured on this server,
     * ST_Distance under 4326 reads X as longitude and Y as latitude, which is exactly the
     * order the rows already store: London to Birmingham comes out at 101.2 miles
     * untouched and 138.7 with the axes swapped, against a true 101.6. Swapping "to be
     * correct" silently reinterprets UK coordinates as sitting near the equator, and the
     * error is plausible enough in size to pass unnoticed.
     *
     * This is a per-row query against a polygon that averages about a megabyte, measured
     * at ~12ms each on live. That is affordable here because it runs once when a reply is
     * held and once per sweep for rows still waiting, and it is worth paying: recomputing
     * as the reach grows is the point, since the distance past the edge shrinks as the
     * isochrone advances on the replier.
     */
    private function milesOutsideReach(int $msgid, float $lat, float $lng): ?float
    {
        // The stored label answers whether the replier is inside the current
        // reach: inside means zero miles outside it. Beyond that the label
        // gives seconds, not miles, so the caller's documented origin-distance
        // measure takes over (null here). No grid; routing unreachable is
        // also null, and the caller's fallback keeps the delay stamped.
        try {
            $verdicts = app(ReachService::class)->labelVerdicts($lat, $lng, [$msgid]);
            if (($verdicts[$msgid] ?? '') === 'in') {
                return 0.0;
            }
        } catch (\Throwable $e) {
            Log::warning("ripple: milesOutsideReach label check failed for {$msgid}: {$e->getMessage()}");
        }

        return null;
    }

    /** @return array{lat:float,lng:float}|null */
    private function reachOrigin(int $msgid): ?array
    {
        try {
            $row = DB::table('rippling_reach')->where('msgid', $msgid)->first(['lat', 'lng']);
        } catch (\Throwable $e) {
            Log::warning("ripple: reachOrigin failed for {$msgid}: {$e->getMessage()}");

            return null;
        }

        if ($row === null || $row->lat === null || $row->lng === null) {
            return null;
        }

        return ['lat' => (float) $row->lat, 'lng' => (float) $row->lng];
    }

    /** Has the dueat migration run? Without it the sweep still works, off created_at. */
    private function dueAtAvailable(): bool
    {
        if (self::$dueAtColumn === null) {
            try {
                self::$dueAtColumn = Schema::hasColumn('rippling_held_replies', 'dueat');
            } catch (\Throwable) {
                self::$dueAtColumn = false;
            }
        }

        return self::$dueAtColumn;
    }

    /** Test-only: forget the memoized column check. */
    public static function forgetDueAtAvailability(): void
    {
        self::$dueAtColumn = null;
    }

    /**
     * Release every held reply for $msgid whose delay has run out, and stamp the due
     * time on any row that has not got one yet (the Go/web hold path does not compute
     * it, so the policy lives here and only here).
     *
     * This is the exit the reach cannot provide: most held repliers are somewhere the
     * reach never covers, so coverage alone leaves them waiting on the max-reach
     * backstop days later. Returns the number released.
     */
    public function releaseDue(int $msgid): int
    {
        if (!config('freegle.ripple.reply_delay.enabled', true)) {
            return 0;
        }

        $held = DB::table('rippling_held_replies')
            ->where('msgid', $msgid)
            ->where('status', 'held')
            ->get();

        if ($held->isEmpty()) {
            return 0;
        }

        $origin = $this->reachOrigin($msgid);
        if ($origin === null) {
            // No reach row to measure from. The post is either not rippling or
            // transiently missing its reach; either way the caller's other branches
            // decide, and inventing a distance here would be guesswork.
            return 0;
        }

        $now = now();
        $canStamp = $this->dueAtAvailable();
        $released = 0;

        foreach ($held as $row) {
            $due = $this->dueFrom(
                $msgid,
                $origin,
                Carbon::parse($row->created_at),
                $row->lat === null ? null : (float) $row->lat,
                $row->lng === null ? null : (float) $row->lng
            );

            // Keep the stamp in step with the policy, so changing the config re-dates
            // rows that have not come off hold rather than leaving a stale promise.
            if ($canStamp) {
                $stamped = $row->dueat === null ? null : Carbon::parse($row->dueat);
                if ($stamped === null || !$stamped->equalTo($due)) {
                    DB::table('rippling_held_replies')->where('id', $row->id)
                        ->update(['dueat' => $due]);
                }
            }

            if ($now->lt($due)) {
                continue;
            }

            $this->release((int) $row->id, 'delayed');
            $released++;
        }

        if ($released > 0) {
            Log::info('ripple:released-delayed-replies', ['msgid' => $msgid, 'count' => $released]);
        }

        return $released;
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
        // The replier's density band comes along for the ride, because a reply held from
        // someone the rural-access ring covers should be released by that ring too - the
        // hold is meant to be temporary, and a hold nothing can ever release is just a
        // silent refusal.
        $held = DB::table('rippling_held_replies as h')
            ->leftJoin('users as u', 'u.id', '=', 'h.replieruserid')
            ->where('h.msgid', $msgid)
            ->where('h.status', 'held')
            // keep-raw: JSON_UNQUOTE(JSON_EXTRACT(...)) has no query-builder equivalent.
            ->selectRaw("h.*, JSON_UNQUOTE(JSON_EXTRACT(u.settings, '$.browseDensityBand')) AS density_band")
            ->get();

        $released = 0;
        foreach ($held as $row) {
            if ($row->lat === null || $row->lng === null) {
                continue;
            }
            if (!$this->reach->isWithinReach($msgid, (float) $row->lat, (float) $row->lng, $row->density_band ?? null)) {
                continue;
            }
            $this->release($row->id, 'covered');
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
    public function releaseAll(int $msgid, string $reason = 'maxed'): int
    {
        $held = DB::table('rippling_held_replies')
            ->where('msgid', $msgid)
            ->where('status', 'held')
            ->get();

        foreach ($held as $row) {
            $this->release($row->id, $reason);
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

    /**
     * $reason says which exit from the hold this was: 'covered' (the ripple arrived),
     * 'delayed' (the delay ran out - the exit most held repliers only ever get),
     * 'maxed' (the reach finished without covering them) or 'backfill' (a manual
     * sweep). Counted separately as well as together, because "how many replies did
     * the delay deliver that coverage never would have" is the question this change
     * exists to answer.
     */
    private function release(int $ripplingRowId, string $reason = 'covered'): void
    {
        DB::table('rippling_held_replies')->where('id', $ripplingRowId)->update([
            'status' => 'released',
            'releasedat' => now(),
        ]);

        // Surface the room in the poster's chat list now the held reply is deliverable.
        // Same dormant-room trap as ChatProcessService: ListForUser filters
        // latestmessage >= now - 31d, and a held reply's chat_messages.date is the
        // (old) reply time, which for a post rippling over hours/days can already be
        // outside the window - so the released reply's email fires (keyed on releasedat)
        // but the chat never appears in the list until the hourly recompute. Bump on
        // release, keyed off the message's own date via GREATEST so we never move
        // latestmessage backwards.
        DB::update(
            'UPDATE chat_rooms cr ' .
            'JOIN rippling_held_replies rhr ON rhr.id = ? ' .
            'JOIN chat_messages cm ON cm.id = rhr.chatmsgid ' .
            'SET cr.latestmessage = GREATEST(COALESCE(cr.latestmessage, cm.date), cm.date) ' .
            'WHERE cr.id = rhr.chatid',
            [$ripplingRowId]
        );

        $this->recordEvent('released');
        $this->recordEvent("released_{$reason}");
    }

    /**
     * Release a single still-held reply by its rippling_held_replies id (row-level, as
     * opposed to the per-post releaseAll/releaseCovered). Used by the scoped backfill in
     * ripple:release-replies --release-open --since-hours. No-op if the row is not 'held'.
     * Returns 1 if released, 0 otherwise.
     */
    public function releaseHeldRow(int $ripplingRowId): int
    {
        $isHeld = DB::table('rippling_held_replies')
            ->where('id', $ripplingRowId)
            ->where('status', 'held')
            ->exists();

        if (! $isHeld) {
            return 0;
        }

        $this->release($ripplingRowId, 'backfill');

        return 1;
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
