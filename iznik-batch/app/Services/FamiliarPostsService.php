<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Who to tell about a new post because they know the poster: the experiment familiar-poster.
 *
 * A member knows the poster when the two of them have a chat in which each has written within the
 * last lookback_days, and neither has rated the other down or blocked the chat. That is "talked to
 * each other recently, and it did not go badly". A rating up is not needed.
 *
 * Only members who could reply are named: an approved member of a community the post is approved on,
 * who has not already replied to it, with relevant mail allowed (the same consent as the matched-posts
 * mail). sendOurMails() and the caps are the command's.
 *
 * docs/developers/reference/familiar-posts-email.md.
 */
class FamiliarPostsService
{
    /**
     * New posts: Offers and Wanteds whose home community copy (not rippled in, never reposted) was
     * approved in [from, to), or arrived then when no approval time is recorded. Still open: not
     * deleted, no outcome, nothing promised.
     *
     * @return array<int, object{id: int, fromuser: int}>
     */
    public function newPosts(Carbon $from, Carbon $to): array
    {
        $f = $from->copy()->utc()->format('Y-m-d H:i:s');
        $t = $to->copy()->utc()->format('Y-m-d H:i:s');

        return DB::table('messages_groups as mg')
            ->join('messages as m', 'm.id', '=', 'mg.msgid')
            ->where('mg.collection', 'Approved')
            ->where('mg.deleted', 0)
            ->where('mg.rippled_in', 0)
            ->where('mg.autoreposts', 0)
            ->where(fn ($q) => $q
                ->where(fn ($a) => $a->where('mg.approvedat', '>=', $f)->where('mg.approvedat', '<', $t))
                ->orWhere(fn ($b) => $b->whereNull('mg.approvedat')->where('mg.arrival', '>=', $f)->where('mg.arrival', '<', $t)))
            ->whereIn('m.type', ['Offer', 'Wanted'])
            ->whereNull('m.deleted')
            ->whereNotNull('m.fromuser')
            ->whereNotExists(fn ($q) => $q->from('messages_outcomes as o')->whereColumn('o.msgid', 'm.id'))
            ->whereNotExists(fn ($q) => $q->from('messages_promises as p')->whereColumn('p.msgid', 'm.id'))
            ->select('m.id', 'm.fromuser')
            ->distinct()
            ->orderBy('m.id')
            ->get()
            ->map(fn ($r) => (object) ['id' => (int) $r->id, 'fromuser' => (int) $r->fromuser])
            ->all();
    }

    /**
     * The members who know the poster and could reply to the post, most recent chat first, at most
     * max_recipients_per_post.
     *
     * @return array<int, int> member ids
     */
    public function recipientsFor(int $msgid, int $poster, Carbon $now): array
    {
        $since = $now->copy()->utc()->subDays(max(1, (int) config('freegle.familiar_posts.lookback_days', 90)))->format('Y-m-d H:i:s');
        $limit = max(1, (int) config('freegle.familiar_posts.max_recipients_per_post', 20));

        // Two lookups, one per side of the chat, so each uses its own (userN, chattype, latestmessage) index.
        $rooms = DB::table('chat_rooms')
            ->where('user1', $poster)->where('chattype', 'User2User')->where('latestmessage', '>=', $since)
            ->select('id', 'user2 as other', 'latestmessage')
            ->unionAll(
                DB::table('chat_rooms')
                    ->where('user2', $poster)->where('chattype', 'User2User')->where('latestmessage', '>=', $since)
                    ->select('id', 'user1 as other', 'latestmessage')
            );

        return DB::query()->fromSub($rooms, 'cr')
            ->join('users as u', 'u.id', '=', 'cr.other')
            ->where('cr.other', '!=', $poster)
            ->whereNull('u.deleted')
            ->where('u.relevantallowed', 1)
            // Each has written in the chat within the lookback.
            ->whereExists(fn ($q) => $q->from('chat_messages as a')->whereColumn('a.chatid', 'cr.id')
                ->whereColumn('a.userid', 'cr.other')->where('a.date', '>=', $since))
            ->whereExists(fn ($q) => $q->from('chat_messages as b')->whereColumn('b.chatid', 'cr.id')
                ->where('b.userid', $poster)->where('b.date', '>=', $since))
            // Neither rated the other down, and nobody blocked the chat.
            ->whereNotExists(fn ($q) => $q->from('ratings as r')->whereColumn('r.rater', 'cr.other')
                ->where('r.ratee', $poster)->where('r.rating', 'Down'))
            ->whereNotExists(fn ($q) => $q->from('ratings as r2')->where('r2.rater', $poster)
                ->whereColumn('r2.ratee', 'cr.other')->where('r2.rating', 'Down'))
            ->whereNotExists(fn ($q) => $q->from('chat_roster as ro')->whereColumn('ro.chatid', 'cr.id')
                ->where('ro.status', 'Blocked'))
            // Could see the post and reply: an approved member of a community it is approved on.
            ->whereExists(fn ($q) => $q->from('memberships as mm')
                ->join('messages_groups as g', 'g.groupid', '=', 'mm.groupid')
                ->whereColumn('mm.userid', 'cr.other')->where('mm.collection', 'Approved')
                ->where('g.msgid', $msgid)->where('g.collection', 'Approved')->where('g.deleted', 0))
            // Has not replied to it already.
            ->whereNotExists(fn ($q) => $q->from('chat_messages as cm')->whereColumn('cm.userid', 'cr.other')
                ->where('cm.refmsgid', $msgid))
            ->orderByDesc('cr.latestmessage')
            ->limit($limit)
            ->pluck('cr.other')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
