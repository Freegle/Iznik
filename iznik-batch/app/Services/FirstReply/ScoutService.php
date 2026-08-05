<?php

namespace App\Services\FirstReply;

use App\Models\Membership;
use App\Models\Message;
use App\Services\UnifiedDigestService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Telling a few likely-interested people about a post nobody has replied to.
 *
 * Two separate things make a post silent, and only one of them is about reach.
 *
 * The first is digest frequency. Immediate mail on a rippling post goes only to
 * members with emailfrequency=IMMEDIATE; everyone on the daily digest hears
 * tomorrow, including the person living two streets away who has replied to nine
 * similar posts this year. Nothing about that is a reach problem, and no amount
 * of rippling fixes it.
 *
 * The second is that reach grows over days, so somebody who has an open WANTED
 * for exactly this item may be sitting outside today's polygon and inside next
 * Tuesday's.
 *
 * A scout mail addresses both by picking a small number of people who look
 * genuinely likely to want THIS item, anywhere inside the reach the post will
 * eventually have, and mailing them now. Small is the point: the value is in who
 * is picked, not how many. Ten well-chosen people is a different product from
 * "the digest, but sooner", and the per-member fatigue caps exist so that being
 * good at replying never turns into being punished for it.
 *
 * Three signals, strongest first:
 *
 *  wanted   - they have an open WANTED that matches. Nearly a direct hit.
 *  search   - they saved a search that matches. They asked to be told.
 *  frequent - they reply to a lot of things and they are nearby. Weakest, and
 *             deliberately scored low enough that it never crowds out the other
 *             two.
 *
 * The geographic bound differs by signal, on purpose. wanted and search start
 * from a small national candidate set (people whose WANTED or saved search
 * matches these words at all), so testing each against the post's eventual reach
 * polygon is cheap and they get the full benefit of it. frequent starts from
 * "members of the communities this post is on", the same population the reach
 * mailer already walks, because "every frequent replier in Britain" is not a set
 * worth building to then throw 99.9% of away.
 *
 * NOTE, and read this before widening anything: UnifiedDigestService's reach
 * mailer is deliberately members-only, on the rule that cold-emailing somebody
 * about a community they have not joined is not appropriate. The wanted and
 * search signals here CAN mail a member of a neighbouring community, because
 * they are not cold: that member has written down a WANTED for this item, or
 * saved a search for it. They asked to be told. The frequent signal, which
 * carries no such request, stays inside the post's own communities exactly as
 * the reach mailer does. Any new signal has to clear the same bar - an explicit
 * request from the member, or membership - and if it cannot, it belongs inside
 * the post's own groups.
 */
class ScoutService
{
    private const SRID = 3857;

    /** Signal weights. Ordering matters more than the absolute numbers. */
    private const SCORE_WANTED = 5.0;

    private const SCORE_SEARCH = 3.0;

    private const SCORE_FREQUENT = 1.0;

    /**
     * Words too common to mean anything as a match. Deliberately short: the
     * keyword list is already filtered by length, and over-pruning here turns
     * "baby bath" into no keywords at all.
     */
    private const STOPWORDS = [
        'offer', 'wanted', 'free', 'good', 'condition', 'used', 'need', 'needed', 'please',
        'available', 'collection', 'collect', 'small', 'large', 'medium', 'with', 'and', 'the',
        'for', 'some', 'must', 'from', 'have', 'this', 'that', 'your', 'about', 'would', 'like',
        'anyone', 'anybody', 'looking', 'thanks', 'items', 'item', 'stuff', 'things',
    ];

    public function __construct(
        private MaxReachService $maxReach,
        private UnifiedDigestService $digest,
        private Metrics $metrics,
    ) {
    }

    /**
     * One pass over posts that have been quiet long enough to be worth helping.
     *
     * @return array{considered:int, posts_scouted:int, mailed:int}
     */
    public function run(bool $dryRun = false): array
    {
        $stats = ['considered' => 0, 'posts_scouted' => 0, 'mailed' => 0];

        if (!config('freegle.firstreply.enabled') || !config('freegle.firstreply.scouts.enabled')) {
            return $stats;
        }

        $cfg = config('freegle.firstreply.scouts');

        foreach ($this->silentPosts($cfg) as $post) {
            $stats['considered']++;

            try {
                $sent = $this->scoutPost($post, $cfg, $dryRun);
                if ($sent > 0) {
                    $stats['posts_scouted']++;
                    $stats['mailed'] += $sent;
                }
            } catch (\Throwable $e) {
                Log::warning('firstreply: scouting failed', [
                    'msgid' => $post->msgid ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (!$dryRun && $stats['mailed'] > 0) {
            $this->metrics->record('scouts_mailed', $stats['mailed']);
        }

        return $stats;
    }

    /** Pick and mail this post's scouts. Returns how many were mailed. */
    public function scoutPost(object $post, array $cfg, bool $dryRun = false): int
    {
        $msgid = (int) $post->msgid;
        $keywords = $this->keywords((string) ($post->subject ?? ''));

        $candidates = $this->candidates($post, $keywords, $cfg);
        if (empty($candidates)) {
            return 0;
        }

        $minScore = (float) ($cfg['min_score'] ?? 1.0);
        $max = max(1, (int) ($cfg['max_per_post'] ?? 10));

        // Highest score first, so a post with three near-perfect matches mails
        // three people rather than padding the list out to the cap.
        uasort($candidates, static fn ($a, $b) => $b['score'] <=> $a['score']);

        $chosen = [];
        foreach ($candidates as $userId => $candidate) {
            if ($candidate['score'] < $minScore) {
                break;
            }
            if (count($chosen) >= $max) {
                break;
            }
            $chosen[$userId] = $candidate;
        }

        if (empty($chosen)) {
            return 0;
        }

        if ($dryRun) {
            return count($chosen);
        }

        // Claim before mailing, for the same reason the prompt engine does: the
        // ledger is what stops a second worker mailing the same people again.
        $claimed = [];
        foreach ($chosen as $userId => $candidate) {
            $inserted = DB::table('firstreply_scouts')->insertOrIgnore([
                'msgid' => $msgid,
                'userid' => $userId,
                'reason' => $candidate['reason'],
                'score' => $candidate['score'],
                'sent_at' => now(),
            ]);
            if ($inserted > 0) {
                $claimed[] = (int) $userId;
            }
        }

        if (empty($claimed)) {
            return 0;
        }

        $sent = $this->digest->mailPostToUsers($msgid, $claimed);

        // Anyone we actually mailed must not be mailed again by the reach mailer
        // when the ripple eventually reaches them - that is the same post twice.
        foreach ($claimed as $userId) {
            DB::table('rippling_reach_notified')->insertOrIgnore([
                'msgid' => $msgid,
                'userid' => $userId,
                'notified_at' => now(),
            ]);
        }

        return $sent;
    }

    /**
     * Posts that have been up long enough to have attracted a reply on their own,
     * young enough for a nudge to still help, and have no reply.
     *
     * quiet_minutes is not a formality: firing the instant a post lands would
     * spend scout mails on posts that were about to get a reply anyway, and would
     * make the whole thing look like a worse digest.
     *
     * @return \Illuminate\Support\Collection<int,object>
     */
    private function silentPosts(array $cfg)
    {
        $quiet = max(0, (int) ($cfg['quiet_minutes'] ?? 45));
        $maxAge = max(1, (int) ($cfg['max_age_hours'] ?? 24));

        return collect(DB::select(
            "SELECT ms.msgid AS msgid, ms.msgtype AS msgtype, ms.arrival AS arrival,
                    m.fromuser AS fromuser, m.subject AS subject
             FROM messages_spatial ms
             JOIN messages m ON m.id = ms.msgid
             WHERE ms.arrival <= DATE_SUB(NOW(), INTERVAL ? MINUTE)
               AND ms.arrival > DATE_SUB(NOW(), INTERVAL ? HOUR)
               AND m.deleted IS NULL
               AND NOT EXISTS (
                     SELECT 1 FROM chat_messages cm
                     WHERE cm.refmsgid = ms.msgid
                       AND cm.type = 'Interested'
                       AND cm.userid <> m.fromuser
                   )
               AND NOT EXISTS (
                     SELECT 1 FROM messages_outcomes mo WHERE mo.msgid = ms.msgid
                   )
               AND NOT EXISTS (
                     SELECT 1 FROM firstreply_scouts fs WHERE fs.msgid = ms.msgid
                   )
             ORDER BY ms.arrival ASC
             LIMIT 200",
            [$quiet, $maxAge]
        ));
    }

    /**
     * Score everyone worth considering for this post.
     *
     * @param string[] $keywords
     * @return array<int,array{score:float, reason:string}>
     */
    private function candidates(object $post, array $keywords, array $cfg): array
    {
        $msgid = (int) $post->msgid;
        $poster = (int) $post->fromuser;
        $limit = max(1, (int) ($cfg['candidate_limit'] ?? 500));

        $scores = [];

        // A WANTED only matches an OFFER and vice versa: someone else wanting the
        // same thing you want is not a lead, it is competition.
        $opposite = $post->msgtype === Message::TYPE_OFFER ? Message::TYPE_WANTED : Message::TYPE_OFFER;

        foreach ($this->matchingPosters($keywords, $opposite, $limit) as $userId) {
            $scores[$userId] = ['score' => self::SCORE_WANTED, 'reason' => 'wanted'];
        }

        foreach ($this->savedSearchers($keywords, $limit) as $userId) {
            if (isset($scores[$userId])) {
                // Both signals firing is a stronger lead than either alone.
                $scores[$userId]['score'] += self::SCORE_SEARCH;
                continue;
            }
            $scores[$userId] = ['score' => self::SCORE_SEARCH, 'reason' => 'search'];
        }

        foreach ($this->frequentRepliersOnPost($msgid, $cfg, $limit) as $userId) {
            if (isset($scores[$userId])) {
                $scores[$userId]['score'] += self::SCORE_FREQUENT;
                continue;
            }
            $scores[$userId] = ['score' => self::SCORE_FREQUENT, 'reason' => 'frequent'];
        }

        unset($scores[$poster]);
        if (empty($scores)) {
            return [];
        }

        // Everything above except the frequent-replier query is unbounded
        // geographically, so the reach test happens once here over the merged set
        // rather than three times in three queries.
        $eligible = $this->filterEligible($msgid, array_keys($scores), $cfg);

        return array_intersect_key($scores, array_flip($eligible));
    }

    /**
     * Users with an open post of the opposite type whose subject matches any of
     * these keywords. The strongest signal there is: they have written down that
     * they want this.
     *
     * @param string[] $keywords
     * @return int[]
     */
    private function matchingPosters(array $keywords, string $type, int $limit): array
    {
        if (empty($keywords)) {
            return [];
        }

        [$sql, $params] = $this->keywordLike('m.subject', $keywords);

        return array_map('intval', DB::table('messages as m')
            ->join('messages_spatial as ms', 'ms.msgid', '=', 'm.id')
            ->whereNull('m.deleted')
            ->where('ms.msgtype', $type)
            ->whereRaw("($sql)", $params)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('messages_outcomes as mo')->whereColumn('mo.msgid', 'm.id');
            })
            ->limit($limit)
            ->pluck('m.fromuser')
            ->unique()
            ->all());
    }

    /**
     * Users whose saved search matches. They explicitly asked to be told about
     * things like this, which is about as clear a signal of consent as exists.
     *
     * @param string[] $keywords
     * @return int[]
     */
    private function savedSearchers(array $keywords, int $limit): array
    {
        if (empty($keywords)) {
            return [];
        }

        [$sql, $params] = $this->keywordLike('term', $keywords);

        try {
            return array_map('intval', DB::table('users_searches')
                ->where('deleted', 0)
                ->whereRaw("($sql)", $params)
                ->limit($limit)
                ->pluck('userid')
                ->unique()
                ->all());
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Members of the communities this post is on who reply to a lot of things.
     *
     * Bounded to the post's own communities rather than the eventual reach
     * polygon: this signal says nothing about THIS item, so casting it nationally
     * would build an enormous set to discard almost all of. Even so bounded it is
     * useful, because it reaches the daily-digest majority today instead of
     * tomorrow.
     *
     * @return int[]
     */
    private function frequentRepliersOnPost(int $msgid, array $cfg, int $limit): array
    {
        $minReplies = max(1, (int) ($cfg['frequent_replier_min'] ?? 3));

        return array_map('intval', collect(DB::select(
            'SELECT cm.userid AS userid, COUNT(DISTINCT cm.refmsgid) AS replies
             FROM chat_messages cm
             WHERE cm.type = ?
               AND cm.date > DATE_SUB(NOW(), INTERVAL 90 DAY)
               AND cm.userid IN (
                     SELECT m.userid FROM memberships m
                     JOIN messages_groups mg ON mg.groupid = m.groupid
                     WHERE mg.msgid = ? AND mg.collection = ? AND mg.deleted = 0
                       AND m.collection = ?
                   )
             GROUP BY cm.userid
             HAVING replies >= ?
             ORDER BY replies DESC
             LIMIT ?',
            [
                'Interested', $msgid, 'Approved', Membership::COLLECTION_APPROVED, $minReplies, $limit,
            ]
        ))->pluck('userid')->all());
    }

    /**
     * Narrow a candidate list to people we may actually mail: alive, contactable,
     * recently active, inside the post's eventual reach, and not already
     * over-scouted.
     *
     * @param int[] $userIds
     * @return int[]
     */
    private function filterEligible(int $msgid, array $userIds, array $cfg): array
    {
        if (empty($userIds) || !$this->maxReach->available()) {
            // No max_polygon yet means no basis for reaching beyond today's reach,
            // and scouting inside today's reach only is the reach mailer's job.
            return [];
        }

        $cooldown = max(0, (int) ($cfg['user_cooldown_hours'] ?? 24));
        $weekCap = max(1, (int) ($cfg['user_max_per_week'] ?? 5));

        // The reach test and the "is this a real, mailable member" test in one
        // pass. resolved_lat/lng follow the same "mylocation else lastlocation"
        // order the reach mailer uses, so a candidate is measured from the point
        // that decides their reach membership everywhere else.
        $rows = DB::select(
            "SELECT u.id AS id
             FROM users u
             LEFT JOIN locations l ON l.id = u.lastlocation
             JOIN rippling_reach rr ON rr.msgid = ?
             WHERE u.id IN (" . implode(',', array_fill(0, count($userIds), '?')) . ")
               AND u.deleted IS NULL
               AND (u.lastaccess IS NULL OR u.lastaccess > DATE_SUB(NOW(), INTERVAL 90 DAY))
               AND EXISTS (SELECT 1 FROM users_emails ue WHERE ue.userid = u.id AND ue.preferred = 1)
               AND rr.max_polygon IS NOT NULL
               AND ST_Contains(rr.max_polygon, ST_SRID(POINT(
                     CASE WHEN JSON_EXTRACT(u.settings, '$.mylocation.lat') IS NOT NULL
                               AND JSON_EXTRACT(u.settings, '$.mylocation.lng') IS NOT NULL
                          THEN CAST(JSON_EXTRACT(u.settings, '$.mylocation.lng') AS DECIMAL(10,6))
                          ELSE l.lng END,
                     CASE WHEN JSON_EXTRACT(u.settings, '$.mylocation.lat') IS NOT NULL
                               AND JSON_EXTRACT(u.settings, '$.mylocation.lng') IS NOT NULL
                          THEN CAST(JSON_EXTRACT(u.settings, '$.mylocation.lat') AS DECIMAL(10,6))
                          ELSE l.lat END
                   ), ?)) = 1
               AND NOT EXISTS (
                     SELECT 1 FROM firstreply_scouts fs
                     WHERE fs.userid = u.id AND fs.sent_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
                   )
               AND (SELECT COUNT(*) FROM firstreply_scouts fs2
                    WHERE fs2.userid = u.id AND fs2.sent_at > DATE_SUB(NOW(), INTERVAL 7 DAY)) < ?
               AND NOT EXISTS (
                     SELECT 1 FROM rippling_reach_notified rn
                     WHERE rn.msgid = ? AND rn.userid = u.id
                   )",
            array_merge([$msgid], $userIds, [self::SRID, $cooldown, $weekCap, $msgid])
        );

        return array_map(static fn ($r) => (int) $r->id, $rows);
    }

    /**
     * Meaningful words from a post subject, for matching against other people's
     * WANTEDs and saved searches.
     *
     * Four characters minimum plus a stopword list: "OFFER: Free small table" has
     * to reduce to "table", not to a match on "free" that would pair every post
     * with every other post on the site.
     *
     * @return string[]
     */
    public function keywords(string $subject): array
    {
        $s = preg_replace('/^\s*(OFFER|WANTED|TAKEN|RECEIVED)\s*:\s*/i', '', $subject) ?? $subject;
        // Trailing "(Location POSTCODE)" is where the item is, not what it is.
        $s = preg_replace('/\s*\([^)]*\)\s*$/', '', $s) ?? $s;
        $s = mb_strtolower($s);

        $words = preg_split('/[^\p{L}\p{N}]+/u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $keywords = [];
        foreach ($words as $word) {
            if (mb_strlen($word) < 4 || in_array($word, self::STOPWORDS, true)) {
                continue;
            }
            $keywords[$word] = true;
        }

        // More than a handful stops narrowing anything and starts costing a LIKE
        // scan per word, so keep the longest few - longer words are more specific.
        $keywords = array_keys($keywords);
        usort($keywords, static fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        return array_slice($keywords, 0, 5);
    }

    /**
     * OR of LIKE conditions for a column against a keyword list.
     *
     * @param string[] $keywords
     * @return array{0:string, 1:array<int,string>}
     */
    private function keywordLike(string $column, array $keywords): array
    {
        $clauses = [];
        $params = [];
        foreach ($keywords as $word) {
            $clauses[] = "$column LIKE ?";
            $params[] = '%' . $word . '%';
        }

        return [implode(' OR ', $clauses), $params];
    }
}
