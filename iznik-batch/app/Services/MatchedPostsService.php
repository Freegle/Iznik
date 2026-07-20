<?php

namespace App\Services;

use App\Models\Message;
use App\Models\User;
use App\Support\SubjectParser;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds the matched-posts notifications: for each recently-arrived Offer/Wanted,
 * ask apiv2's vector store for the opposite-type posts near it, then fan those
 * matches out to the two people who care — the fresh post's owner ("here are
 * offers/wanteds for what you just posted") and each matched post's owner ("a new
 * post matches your open offer/wanted"). Applies every dedup/eligibility guard so
 * the command can just render and send what comes back.
 *
 * Vector similarity itself lives in apiv2 (in-memory store); this service only
 * orchestrates and resolves the surrounding data from our own DB.
 */
class MatchedPostsService
{
    public function __construct(private FreegleApiClient $api) {}

    /**
     * @return array<int, array{user: User, items: array<int, array{message: Message, reason: Message, score: float}>}>
     *         One entry per eligible recipient, each with the matched posts to show them.
     */
    public function buildNotifications(?Carbon $now = null): array
    {
        $now = $now ?? Carbon::now();

        if (! config('freegle.matched.enabled', true)) {
            return [];
        }

        $window = (int) config('freegle.matched.fresh_window_minutes', 20);
        $perPost = (int) config('freegle.matched.match_limit_per_post', 10);

        $fresh = $this->freshPosts($now->copy()->subMinutes($window));
        if ($fresh->isEmpty()) {
            return [];
        }

        // Ask apiv2 for each fresh post's opposite-type matches. Collect the raw
        // (freshPost, matchedPost) links plus every message id involved.
        // $matchCache[postId] = [matched msgids] — apiv2 reach-filters each result
        // for THAT post's owner, so it doubles as the per-recipient reach oracle
        // in verifyReach() below.
        // Relevance floor: apiv2 returns the nearest vector neighbours regardless
        // of how weak the similarity is, so without a threshold poor matches (e.g.
        // "bicycle pump" ~ "pot pourri") surface. Only links at/above min_score are
        // shown; matchCache still carries the full set so reach verification below
        // is unaffected (reach is geography, not similarity).
        $minScore = (float) config('freegle.matched.match_min_score', 0.0);
        $links = [];   // list of ['F'=>int, 'Fowner'=>int, 'R'=>int, 'score'=>float]
        $ids = [];     // set of message ids to resolve
        $matchCache = [];
        foreach ($fresh as $f) {
            $matches = $this->api->matchesForPost((int) $f->msgid, $perPost);
            $matchCache[(int) $f->msgid] = array_values(array_filter(array_map(fn ($m) => (int) ($m['id'] ?? 0), $matches)));
            foreach ($matches as $m) {
                $rid = (int) ($m['id'] ?? 0);
                if ($rid <= 0) {
                    continue;
                }
                if ((float) ($m['score'] ?? 0) < $minScore) {
                    continue; // below the relevance floor — don't show it
                }
                $links[] = ['F' => (int) $f->msgid, 'Fowner' => (int) $f->fromuser, 'R' => $rid, 'score' => (float) ($m['score'] ?? 0)];
                $ids[(int) $f->msgid] = true;
                $ids[$rid] = true;
            }
        }
        if (! $links) {
            return [];
        }

        // Resolve the involved posts — only those still open (approved, not
        // deleted, not taken). A post that closed between the vector snapshot and
        // now simply drops out here.
        $messages = $this->loadOpenMessages(array_keys($ids));

        // Fan out into (recipient => matchedMsgId => [matched message, reason post, score]).
        $byRecipient = [];   // [recipientId => [matchedMsgId => ['message'=>Message,'reason'=>Message,'score'=>float]]]
        foreach ($links as $l) {
            $f = $messages->get($l['F']);
            $r = $messages->get($l['R']);
            if (! $f || ! $r) {
                continue;
            }
            // Direction (i): the fresh post's owner sees the matched post R,
            // because it matches their post F.
            $this->addPair($byRecipient, $l['Fowner'], $r, $f, $l['score']);
            // Direction (ii): the matched post's owner sees the fresh post F,
            // because it matches their post R.
            $this->addPair($byRecipient, (int) $r->fromuser, $f, $r, $l['score']);
        }
        if (! $byRecipient) {
            return [];
        }

        $notifications = $this->applyEligibility($byRecipient, $now);

        return $this->verifyReach($notifications, $matchCache, $perPost);
    }

    /**
     * Make every shown match reach-exact for its recipient. A recipient U is only
     * shown a match M if M is in apiv2's matches for U's OWN post that it matched
     * (`matchesForPost` reach-filters against that post's owner). Direction (i)
     * items pass for free — their reason post is a fresh post already in
     * $matchCache. Direction (ii) items (the recipient's existing post ← a new
     * arrival) previously relied on bbox proximity; here they are checked against
     * a fresh `matchesForPost` of the recipient's own post, so a match that hasn't
     * rippled out to the recipient is dropped.
     *
     * @param  array<int, array{user: User, items: array}>  $notifications
     * @param  array<int, array<int, int>>  $matchCache  postId => matched msgids
     * @return array<int, array{user: User, items: array}>
     */
    private function verifyReach(array $notifications, array $matchCache, int $perPost): array
    {
        $out = [];
        foreach ($notifications as $n) {
            $kept = [];
            foreach ($n['items'] as $item) {
                $reasonId = (int) $item['reason']->id;
                if (! array_key_exists($reasonId, $matchCache)) {
                    // Resolve (and cache) the recipient's own post's matches — this
                    // is the extra apiv2 call, made only for surviving items.
                    $res = $this->api->matchesForPost($reasonId, $perPost);
                    $matchCache[$reasonId] = array_values(array_filter(array_map(fn ($m) => (int) ($m['id'] ?? 0), $res)));
                }
                if (in_array((int) $item['message']->id, $matchCache[$reasonId], true)) {
                    $kept[] = $item;
                }
            }
            if ($kept) {
                $out[] = ['user' => $n['user'], 'items' => $kept];
            }
        }

        return $out;
    }

    /**
     * Recently-arrived, still-open, embedded Offer/Wanted posts — the drivers for
     * this run. Bounded by the arrival index; joining messages_embeddings skips
     * posts that can't be vector-searched yet (no wasted apiv2 call).
     */
    private function freshPosts(Carbon $since): Collection
    {
        return collect(DB::select(
            'SELECT m.id AS msgid, m.fromuser, m.type
               FROM messages_groups mg
               INNER JOIN messages m ON m.id = mg.msgid
               INNER JOIN messages_spatial ms ON ms.msgid = m.id
               INNER JOIN messages_embeddings me ON me.msgid = m.id
              WHERE mg.collection = ? AND mg.deleted = 0 AND mg.rippled_in = 0
                AND m.type IN (?, ?)
                AND mg.arrival > ?
                AND m.deleted IS NULL
                AND ms.successful = 0 AND ms.promised = 0
              GROUP BY m.id, m.fromuser, m.type',
            ['Approved', 'Offer', 'Wanted', $since->toDateTimeString()]
        ));
    }

    /**
     * Load the given posts as Message models, keyed by id, restricted to those
     * still live (approved, not deleted, no Taken/Received outcome).
     */
    private function loadOpenMessages(array $ids): Collection
    {
        if (! $ids) {
            return collect();
        }

        return Message::query()
            ->whereIn('id', $ids)
            ->approved()
            ->notDeleted()
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('messages_outcomes')
                    ->whereColumn('messages_outcomes.msgid', 'messages.id')
                    ->whereIn('messages_outcomes.outcome', ['Taken', 'Received']);
            })
            ->with(['attachments', 'fromUser', 'groups'])
            ->get()
            ->keyBy('id');
    }

    /**
     * Record that matched post $matched should be shown to $recipientId because it
     * matches their own post $reason. Dedupes on the matched post (keeps the
     * highest-scoring reason) and never shows a user their own post.
     */
    private function addPair(array &$byRecipient, int $recipientId, Message $matched, Message $reason, float $score): void
    {
        if ($recipientId <= 0) {
            return;
        }
        if ((int) $matched->fromuser === $recipientId) {
            return; // never show someone their own post
        }
        $mid = (int) $matched->id;
        $existing = $byRecipient[$recipientId][$mid] ?? null;
        if ($existing && $existing['score'] >= $score) {
            return;
        }
        $byRecipient[$recipientId][$mid] = ['message' => $matched, 'reason' => $reason, 'score' => $score];
    }

    /**
     * Drop matched posts the recipient has already clicked to view or already been
     * mailed, drop ineligible recipients (opted out / dormant / within cooldown),
     * cap and order the per-recipient list, and attach the User model.
     */
    private function applyEligibility(array $byRecipient, Carbon $now): array
    {
        $recipientIds = array_keys($byRecipient);

        // Eligible recipients: opted in, active recently, outside the cooldown.
        $eligible = $this->eligibleRecipients($recipientIds, $now);
        if ($eligible->isEmpty()) {
            return [];
        }

        // (userid, msgid) pairs to test against "already viewed" and "already mailed".
        $pairs = [];
        foreach ($byRecipient as $uid => $items) {
            if (! $eligible->has($uid)) {
                continue;
            }
            foreach (array_keys($items) as $mid) {
                $pairs[] = [$uid, $mid];
            }
        }
        $viewed = $this->pairSet('messages_likes', $pairs, "type = 'View' AND pageview = 1");
        $mailed = $this->pairSet('messages_matched_notified', $pairs);

        $cap = (int) config('freegle.matched.max_items_per_email', 10);

        $out = [];
        foreach ($byRecipient as $uid => $items) {
            if (! $eligible->has($uid)) {
                continue;
            }
            $kept = [];
            foreach ($items as $mid => $item) {
                if (isset($viewed["$uid:$mid"]) || isset($mailed["$uid:$mid"])) {
                    continue;
                }
                $kept[] = $item;
            }
            if (! $kept) {
                continue;
            }
            // Collapse crossposts: the same item posted to several groups is stored
            // as distinct messages (same poster + subject, different msgids/groups),
            // so without this the email shows one item as several near-identical
            // cards. Keep the single best-scoring copy per (poster, subject).
            $kept = $this->dedupeCrossposts($kept);
            usort($kept, fn ($a, $b) => $b['score'] <=> $a['score']);
            $out[] = [
                'user' => $eligible->get($uid),
                'items' => array_slice($kept, 0, $cap),
            ];
        }

        return $out;
    }

    /**
     * Collapse crossposts of one item to a single card. A crosspost is the same
     * poster's identical subject appearing under more than one group as separate
     * messages (distinct msgids); keep the highest-scoring copy.
     *
     * @param  array<int, array{message: Message, reason: Message, score: float}>  $items
     * @return array<int, array{message: Message, reason: Message, score: float}>
     */
    private function dedupeCrossposts(array $items): array
    {
        $best = [];
        foreach ($items as $item) {
            $msg = $item['message'];
            $key = (int) $msg->fromuser . '|' . trim((string) $msg->subject);
            if (! isset($best[$key]) || $item['score'] > $best[$key]['score']) {
                $best[$key] = $item;
            }
        }

        return array_values($best);
    }

    private function eligibleRecipients(array $ids, Carbon $now): Collection
    {
        if (! $ids) {
            return collect();
        }
        $dormantDays = (int) config('freegle.matched.min_lastaccess_days', 90);
        $cooldownHours = (int) config('freegle.matched.cooldown_hours', 4);

        return User::query()
            ->whereIn('id', $ids)
            ->where('relevantallowed', 1)
            ->where('lastaccess', '>', $now->copy()->subDays($dormantDays)->toDateTimeString())
            ->where(function ($q) use ($now, $cooldownHours) {
                $q->whereNull('lastrelevantcheck')
                    ->orWhere('lastrelevantcheck', '<', $now->copy()->subHours($cooldownHours)->toDateTimeString());
            })
            ->get()
            ->keyBy('id');
    }

    /**
     * Return a set ("userid:msgid" => true) of the given (userid, msgid) pairs
     * that exist in $table (optionally further constrained by $extra).
     *
     * @param  array<int, array{0:int,1:int}>  $pairs
     */
    private function pairSet(string $table, array $pairs, string $extra = ''): array
    {
        if (! $pairs) {
            return [];
        }

        $userIds = array_values(array_unique(array_map(fn ($p) => $p[0], $pairs)));
        $msgIds = array_values(array_unique(array_map(fn ($p) => $p[1], $pairs)));

        $q = DB::table($table)
            ->select('userid', 'msgid')
            ->whereIn('userid', $userIds)
            ->whereIn('msgid', $msgIds);
        if ($extra !== '') {
            $q->whereRaw($extra);
        }

        // Prune to the exact pairs we asked about (the IN×IN cross-product can
        // include pairs we don't care about).
        $wanted = [];
        foreach ($pairs as $p) {
            $wanted["{$p[0]}:{$p[1]}"] = true;
        }

        $set = [];
        foreach ($q->get() as $row) {
            $key = "{$row->userid}:{$row->msgid}";
            if (isset($wanted[$key])) {
                $set[$key] = true;
            }
        }

        return $set;
    }

    /** Item name from a message subject ("OFFER: Bike (London)" → "Bike"). */
    public static function itemName(Message $m): string
    {
        $subject = html_entity_decode($m->subject ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        [, $item] = SubjectParser::parse($subject);
        if ($item) {
            return $item;
        }
        // Fallback: strip a leading TYPE: prefix and any trailing (location).
        $name = preg_replace('/^(OFFER|WANTED)\s*:\s*/i', '', $subject);
        $name = preg_replace('/\s*\([^)]+\)\s*$/', '', $name);

        return trim($name) ?: $subject;
    }
}
