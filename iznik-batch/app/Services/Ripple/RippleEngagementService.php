<?php

namespace App\Services\Ripple;

use Illuminate\Support\Facades\DB;

/**
 * Makes a ripple-created membership ordinary once the member has done something with the
 * community, so its moderators can write to them (Discourse 10239).
 *
 * Rippling joins a poster to every community their post reaches and marks the membership
 * memberships.rippled = 1. That is a record of where a post travelled, so the community's
 * moderators may not start a chat, send mail or send standard messages to the member
 * (Discourse 10102). Once the member has dealt with the community, that stops being true:
 *
 *  - they posted on it;
 *  - they replied to a post that was posted on it;
 *  - they wrote back, in a chat about their rippled post, to one of its ordinary members.
 *
 * Clearing is the same change iznik-server-go makes when the member joins the community or
 * moves into its area (rippling.ClearRippledMembership): the flag goes, and a Group/Joined
 * log row says why. memberships_history keeps its rippled flag, which records how the
 * membership was created.
 *
 * One pass covers every route a reply or a post arrives by - the API, email and
 * TrashNothing - because it reads what was stored rather than hooking each route.
 */
class RippleEngagementService
{
    public const LAST_CHAT_MESSAGE_KEY = 'ripple_engaged_last_chat_message';

    public const LAST_POST_ARRIVAL_KEY = 'ripple_engaged_last_post_arrival';

    public const REASON_POSTED = 'posted on this community';

    public const REASON_REPLIED = 'replied to a post on this community';

    public const REASON_WROTE_BACK = 'wrote back to a member about their post';

    /** Prefix of the log text every clear writes, here and in iznik-server-go. */
    public const LOG_PREFIX = 'Ripple-created membership became ordinary: ';

    private const CHAT_CHUNK = 5000;

    /**
     * Posts are found by their arrival, which approval sets to the time of approval. Each
     * pass looks back this far past the saved position, so a post approved while the
     * previous pass was running is not missed. Clearing is idempotent.
     */
    private const POST_OVERLAP_MINUTES = 10;

    /**
     * @param  int  $days  how far back to look on a first run, when nothing is saved
     * @return array{posted:int,replied:int,wrote_back:int}
     */
    public function run(int $days): array
    {
        $stats = ['posted' => 0, 'replied' => 0, 'wrote_back' => 0];

        $this->clearForPosts($days, $stats);
        $this->clearForChats($days, $stats);

        return $stats;
    }

    /**
     * One-off catch-up over everything since $since, for engagement from before the
     * scheduled run existed or older than its first look-back. Runs the same three checks,
     * but leaves the scheduled run's saved positions alone, so it can be run at any time
     * without making the scheduled run skip or repeat anything.
     *
     * @return array{posted:int,replied:int,wrote_back:int}
     */
    public function backfill(\Carbon\CarbonInterface $since): array
    {
        $stats = ['posted' => 0, 'replied' => 0, 'wrote_back' => 0];

        $this->clearForPostsSince($since, $stats);

        $from = DB::table('chat_messages')->where('date', '>=', $since)->min('id');
        $last = (int) (DB::table('chat_messages')->max('id') ?? 0);
        if ($from !== null) {
            for ($start = (int) $from; $start <= $last; $start += self::CHAT_CHUNK) {
                $end = min($start + self::CHAT_CHUNK - 1, $last);
                $this->clearForReplies($start, $end, $stats);
                $this->clearForWriteBacks($start, $end, $stats);
            }
        }

        return $stats;
    }

    /**
     * Makes the member's ripple-created membership of the group ordinary, if it is one.
     * Returns whether anything changed.
     *
     * memberships.implicit is cleared alongside rippled, as rippling.ClearRippledMembership
     * does in iznik-server-go. A row with implicit = 1 alone is a TrashNothing poster placed on
     * a group they never chose; GroupPostIngestionService clears it here once they consent.
     * Every pass in this class picks its rows on rippled = 1, so none of them reaches one.
     */
    public function clear(int $userid, int $groupid, string $reason): bool
    {
        $changed = DB::table('memberships')
            ->where('userid', $userid)
            ->where('groupid', $groupid)
            ->where(fn ($q) => $q->where('rippled', 1)->orWhere('implicit', 1))
            ->update(['rippled' => 0, 'implicit' => 0]);

        if ($changed < 1) {
            return false;
        }

        DB::table('logs')->insert([
            'timestamp' => now(),
            'type' => 'Group',
            'subtype' => 'Joined',
            'groupid' => $groupid,
            'user' => $userid,
            'byuser' => $userid,
            'text' => self::LOG_PREFIX.$reason,
        ]);

        return true;
    }

    /**
     * Posted on the community: an approved post whose home copy is on it. A TrashNothing
     * post placed on a community its poster never chose (mod_messaging_allowed = 0) is not
     * the member's choice and does not count.
     */
    private function clearForPosts(int $days, array &$stats): void
    {
        $passStart = now();
        $saved = DB::table('config')->where('key', self::LAST_POST_ARRIVAL_KEY)->value('value');
        $since = $saved
            ? \Carbon\Carbon::parse($saved)->subMinutes(self::POST_OVERLAP_MINUTES)
            : now()->subDays($days);

        $this->clearForPostsSince($since, $stats);

        $this->save(self::LAST_POST_ARRIVAL_KEY, $passStart->toDateTimeString());
    }

    private function clearForPostsSince(\Carbon\CarbonInterface $since, array &$stats): void
    {
        $pairs = DB::table('messages_groups as mg')
            ->join('messages as msg', 'msg.id', '=', 'mg.msgid')
            ->join('memberships as m', function ($j) {
                $j->on('m.userid', '=', 'msg.fromuser')
                    ->on('m.groupid', '=', 'mg.groupid')
                    ->where('m.rippled', 1);
            })
            ->where('mg.arrival', '>=', $since)
            ->where('mg.rippled_in', 0)
            ->where('mg.collection', 'Approved')
            ->where('mg.mod_messaging_allowed', 1)
            ->distinct()
            ->get(['m.userid', 'm.groupid']);

        foreach ($pairs as $p) {
            if ($this->clear((int) $p->userid, (int) $p->groupid, self::REASON_POSTED)) {
                $stats['posted']++;
            }
        }
    }

    /**
     * Replies and write-backs, read in chunks of chat messages after the saved position.
     * A message still under review is not counted yet, and the saved position stops short
     * of it so it is read again once a moderator has passed it.
     */
    private function clearForChats(int $days, array &$stats): void
    {
        $saved = DB::table('config')->where('key', self::LAST_CHAT_MESSAGE_KEY)->value('value');
        $from = $saved !== null
            ? (int) $saved + 1
            : (int) (DB::table('chat_messages')->where('date', '>=', now()->subDays($days))->min('id') ?? 0);
        $last = (int) (DB::table('chat_messages')->max('id') ?? 0);

        if ($from === 0 || $from > $last) {
            if ($saved === null) {
                $this->save(self::LAST_CHAT_MESSAGE_KEY, (string) $last);
            }

            return;
        }

        $done = $from - 1;
        for ($start = $from; $start <= $last; $start += self::CHAT_CHUNK) {
            $end = min($start + self::CHAT_CHUNK - 1, $last);

            $this->clearForReplies($start, $end, $stats);
            $this->clearForWriteBacks($start, $end, $stats);

            $underReview = DB::table('chat_messages')
                ->whereBetween('id', [$start, $end])
                ->where('reviewrequired', 1)
                ->where('reviewrejected', 0)
                ->min('id');

            if ($underReview !== null) {
                $done = (int) $underReview - 1;
                break;
            }

            $done = $end;
        }

        $this->save(self::LAST_CHAT_MESSAGE_KEY, (string) $done);
    }

    /** Replied to a post whose home copy is on the community. */
    private function clearForReplies(int $start, int $end, array &$stats): void
    {
        $pairs = DB::table('chat_messages as cm')
            ->join('messages_groups as mg', function ($j) {
                $j->on('mg.msgid', '=', 'cm.refmsgid')->where('mg.rippled_in', 0);
            })
            ->join('memberships as m', function ($j) {
                $j->on('m.userid', '=', 'cm.userid')
                    ->on('m.groupid', '=', 'mg.groupid')
                    ->where('m.rippled', 1);
            })
            ->whereBetween('cm.id', [$start, $end])
            ->where('cm.type', 'Interested')
            ->where('cm.reviewrequired', 0)
            ->where('cm.reviewrejected', 0)
            ->where('cm.deleted', 0)
            ->distinct()
            ->get(['m.userid', 'm.groupid']);

        foreach ($pairs as $p) {
            if ($this->clear((int) $p->userid, (int) $p->groupid, self::REASON_REPLIED)) {
                $stats['replied']++;
            }
        }
    }

    /**
     * Wrote back about their rippled post: the poster sent a message in a chat where
     * someone else had replied to one of their posts, on a community the post rippled into
     * and where that someone is an ordinary member. A reply the poster never answered does
     * not count - a stranger's message is not something the poster did.
     */
    private function clearForWriteBacks(int $start, int $end, array &$stats): void
    {
        $pairs = DB::table('chat_messages as cm')
            ->join('chat_messages as r', function ($j) {
                $j->on('r.chatid', '=', 'cm.chatid')
                    ->on('r.userid', '!=', 'cm.userid')
                    ->on('r.id', '<', 'cm.id')
                    ->whereNotNull('r.refmsgid');
            })
            ->join('messages as msg', function ($j) {
                $j->on('msg.id', '=', 'r.refmsgid')->on('msg.fromuser', '=', 'cm.userid');
            })
            ->join('messages_groups as mg', function ($j) {
                $j->on('mg.msgid', '=', 'msg.id')->where('mg.rippled_in', 1);
            })
            ->join('memberships as mr', function ($j) {
                // A TrashNothing poster placed on the community (implicit = 1) is no more its
                // member than a ripple join is. rippled goes when Phase 5 moves this to implicit.
                $j->on('mr.userid', '=', 'r.userid')
                    ->on('mr.groupid', '=', 'mg.groupid')
                    ->where('mr.rippled', 0)
                    ->where('mr.implicit', 0)
                    ->where('mr.collection', 'Approved');
            })
            ->join('memberships as mp', function ($j) {
                $j->on('mp.userid', '=', 'cm.userid')
                    ->on('mp.groupid', '=', 'mg.groupid')
                    ->where('mp.rippled', 1);
            })
            ->whereBetween('cm.id', [$start, $end])
            ->where('cm.reviewrequired', 0)
            ->where('cm.reviewrejected', 0)
            ->where('cm.deleted', 0)
            ->distinct()
            ->get(['mp.userid', 'mp.groupid']);

        foreach ($pairs as $p) {
            if ($this->clear((int) $p->userid, (int) $p->groupid, self::REASON_WROTE_BACK)) {
                $stats['wrote_back']++;
            }
        }
    }

    private function save(string $key, string $value): void
    {
        DB::table('config')->upsert(['key' => $key, 'value' => $value], ['key'], ['value']);
    }
}
