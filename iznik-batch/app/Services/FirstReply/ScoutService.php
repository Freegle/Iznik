<?php

namespace App\Services\FirstReply;

use App\Models\Membership;
use App\Services\UnifiedDigestService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

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
 * **What justifies the mail decides whether it may be an extra one.**
 *
 * A match on an outstanding post or a saved search is something the member asked
 * for, item by item, so it may be an extra mail. It is gated on
 * users.relevantallowed instead - the existing "Suggested posts for you" consent,
 * which is precisely what this is, and which the engagement mails and
 * non-essential admin mails already honour.
 *
 * "Replies to a lot of things" says nothing about THIS item, so it may only ever
 * be that member's daily digest arriving early, never an extra mail: skipped if
 * today's digest has already gone, and skipped if they take no post email
 * anywhere, because then there is no digest to bring forward. Dropped candidates
 * do not leave a hole - filtering happens before the top-N cap, so the next-best
 * candidate takes the slot and the post still gets its full complement.
 *
 * One residual overlap on that weaker path, deliberately left: a post arriving
 * before the daily digest cron has run can scout somebody whose digest then also
 * goes out later the same morning. Closing it would mean suppressing their
 * digest, trading a whole day's posts for one.
 *
 * **The mail itself is byte-for-byte an ordinary immediate digest** for that one
 * post (UnifiedDigestService::mailPostToUsers). No scout-specific subject,
 * preamble or footer, and nothing in it says how the recipient was chosen. A
 * member should not be able to tell a scouted post from one the ripple reached
 * normally, and nor should anyone they forward it to.
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
 * Membership is NOT required. UnifiedDigestService's reach mailer is
 * members-only, and scouting deliberately is not: anyone inside the post's
 * eventual reach may be told about it, whether or not they have joined the
 * community it was posted to. Replying joins them - the in-app path calls
 * AddMembership as part of creating the reply, and an emailed reply is joined on
 * its way in - so the membership follows the interest instead of gating it.
 * (Product decision, Edward, 2026-08-05.)
 *
 * The frequent signal is still drawn from members of the post's own communities,
 * but that is a COST bound rather than a permission one: "every frequent replier
 * in Britain" is not a set worth building in order to discard 99.9% of it. It
 * widens on its own as the post ripples into more communities.
 */
class ScoutService
{
    private const SRID = 3857;

    /** `config` key overriding how many propensity scouts a post may mail. See scoutConfig(). */
    public const CONFIG_MAX_PER_POST = 'firstreply_scouts_max_per_post';

    /** `config` key overriding the safety ceiling on wanted/search scouts. */
    public const CONFIG_MAX_STRONG_PER_POST = 'firstreply_scouts_max_strong_per_post';

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
        private \App\Services\FreegleApiClient $api,
    ) {
    }

    /**
     * One pass over live posts with no reply.
     *
     * @return array{considered:int, posts_scouted:int, mailed:int}
     */
    public function run(bool $dryRun = false): array
    {
        $stats = ['considered' => 0, 'posts_scouted' => 0, 'mailed' => 0];

        if (!config('freegle.firstreply.enabled') || !config('freegle.firstreply.scouts.enabled')) {
            return $stats;
        }

        $cfg = $this->scoutConfig();

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

    /**
     * Scout config, with both per-post caps overridable at runtime.
     *
     * The env vars set the defaults, but env only changes on a deploy, and these
     * are the numbers worth turning down NOW - between them they are the entire
     * mail bill of the feature, and the reason to move one is usually that it is
     * mailing too many people, which is the worst time to be waiting for a
     * release. `config` is the same key/value table the rest of the batch code
     * uses for state that has to outlive a container.
     *
     * An absent key means "no opinion", so the env default stands and an empty
     * table behaves exactly as it did before.
     *
     * Zero is meaningful for both, and means different things:
     * - max_per_post = 0 stops propensity scouts, leaving people who actually
     *   asked for the item still being told.
     * - max_strong_per_post = 0 stops those too, which together is the whole
     *   lever off without touching the enabled flags.
     */
    private function scoutConfig(): array
    {
        $cfg = config('freegle.firstreply.scouts');

        foreach ([
            self::CONFIG_MAX_PER_POST => 'max_per_post',
            self::CONFIG_MAX_STRONG_PER_POST => 'max_strong_per_post',
        ] as $key => $field) {
            $override = DB::table('config')->where('key', $key)->value('value');

            if ($override !== null && is_numeric($override)) {
                $cfg[$field] = max(0, (int) $override);
            }
        }

        return $cfg;
    }

    /**
     * Stamp firstreply_scouts.replied_at for scouts who have since replied to the
     * post we told them about.
     *
     * This is the only thing that answers "does scouting work?", and it has to be
     * a sweep rather than a hook on the reply path: the reply arrives through
     * several doors (web, app, email, TrashNothing) and none of them knows or
     * should know that the replier was scouted.
     *
     * Attribution is deliberately weak - they replied after we mailed them, which
     * is correlation, not proof. That is why the score is read alongside the
     * unscouted reply rate rather than on its own.
     *
     * @return int how many were newly attributed
     */
    public function attributeReplies(int $lookbackDays = 7): int
    {
        try {
            $since = now()->subDays(max(1, $lookbackDays));

            // Who is about to be attributed, captured BEFORE the update, because
            // afterwards replied_at is set and they no longer match.
            $newlyReplied = DB::table('firstreply_scouts as fs')
                ->join('chat_messages as cm', function ($join) {
                    $join->on('cm.refmsgid', '=', 'fs.msgid')
                        ->on('cm.userid', '=', 'fs.userid')
                        ->where('cm.type', 'Interested')
                        ->whereColumn('cm.date', '>=', 'fs.sent_at');
                })
                ->whereNull('fs.replied_at')
                ->where('fs.sent_at', '>', $since)
                ->select('fs.msgid', 'fs.userid')
                ->distinct()
                ->get();

            $attributed = DB::table('firstreply_scouts as fs')
                ->join('chat_messages as cm', function ($join) {
                    $join->on('cm.refmsgid', '=', 'fs.msgid')
                        ->on('cm.userid', '=', 'fs.userid')
                        ->where('cm.type', 'Interested')
                        ->whereColumn('cm.date', '>=', 'fs.sent_at');
                })
                ->whereNull('fs.replied_at')
                ->where('fs.sent_at', '>', $since)
                ->update(['fs.replied_at' => DB::raw('cm.date')]);

            foreach ($newlyReplied as $reply) {
                $this->pullReachOutTo((int) $reply->msgid, (int) $reply->userid);
            }

            return $attributed;
        } catch (\Throwable $e) {
            Log::warning('firstreply: scout attribution failed', ['error' => $e->getMessage()]);

            return 0;
        }
    }

    /**
     * Where a member is, resolved the same "mylocation else lastlocation" way the
     * reach query and the digest mailer resolve it - so a scout is measured from
     * the point that decided their reach membership in the first place.
     *
     * @return array{lat:float,lng:float}|null
     */
    private function userPoint(int $userId): ?array
    {
        $row = DB::table('users as u')
            ->leftJoin('locations as l', 'l.id', '=', 'u.lastlocation')
            ->where('u.id', $userId)
            ->selectRaw(
                "CASE WHEN JSON_EXTRACT(u.settings, '$.mylocation.lat') IS NOT NULL
                           AND JSON_EXTRACT(u.settings, '$.mylocation.lng') IS NOT NULL
                      THEN CAST(JSON_EXTRACT(u.settings, '$.mylocation.lat') AS DECIMAL(10,6))
                      ELSE l.lat END AS lat,
                 CASE WHEN JSON_EXTRACT(u.settings, '$.mylocation.lat') IS NOT NULL
                           AND JSON_EXTRACT(u.settings, '$.mylocation.lng') IS NOT NULL
                      THEN CAST(JSON_EXTRACT(u.settings, '$.mylocation.lng') AS DECIMAL(10,6))
                      ELSE l.lng END AS lng"
            )
            ->first();

        if ($row === null || $row->lat === null || $row->lng === null) {
            return null;
        }

        return ['lat' => (float) $row->lat, 'lng' => (float) $row->lng];
    }

    /**
     * A scout replied, so bring the post's reach out far enough to include them.
     *
     * Scouts are mailed to people OUTSIDE the current reach - that is the whole
     * point of them - so a reply is evidence the item is wanted at a distance the
     * ripple has not got to yet. Leaving the reach where it is would mean the one
     * person we hand-picked can reply while their neighbours, who are just as
     * close to it, keep waiting on the clock. So the tick that covers the scout
     * becomes a floor, and the post expands to it on the next pass.
     *
     * Deliberately a floor rather than a polygon write: advancing reach means
     * resolving tick geometry, unioning the origin group's area, deriving bounds
     * and re-applying rejected-group clips, all of which ExpandService already
     * does. Doing it here as well would be the same geometry in two places.
     *
     * Best-effort throughout: a post whose schedule cannot answer simply keeps
     * expanding on time, which is what it did before any of this existed.
     */
    private function pullReachOutTo(int $msgid, int $userId): void
    {
        try {
            $point = $this->userPoint($userId);
            if ($point === null) {
                return;
            }

            $tick = $this->maxReach->tickCovering($msgid, $point['lat'], $point['lng']);
            if ($tick === null) {
                return;
            }

            // Only ever forwards, and only while the post is still expanding: a
            // stopped or completed post has left the ripple deliberately.
            $affected = DB::table('rippling_reach')
                ->where('msgid', $msgid)
                ->where('status', 'expanding')
                ->where(fn ($q) => $q->whereNull('min_tick')->orWhere('min_tick', '<', $tick))
                ->where('tick', '<', $tick)
                ->update([
                    'min_tick' => $tick,
                    'next_expansion_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($affected > 0) {
                $this->metrics->record('scout_reply_expanded_reach');
            }
        } catch (\Throwable $e) {
            Log::warning('firstreply: could not pull reach out to a scout who replied', [
                'msgid' => $msgid, 'userid' => $userId, 'error' => $e->getMessage(),
            ]);
        }
    }

    /** Pick and mail this post's scouts. Returns how many were mailed. */
    public function scoutPost(object $post, array $cfg, bool $dryRun = false): int
    {
        $msgid = (int) $post->msgid;

        // Scouting fires as soon as a post is seen, which can be a beat before the
        // background pass has worked out the post's eventual reach - and without
        // that nobody is eligible. Ask for it directly rather than making the post
        // wait a minute for a different cron. Schedule-only, so it costs a JSON
        // decode and an UPDATE; posts that need a routing call are left to the
        // background pass and simply get no scouts this time round.
        $this->maxReach->populateForPost($msgid);

        $candidates = $this->candidates($post, $cfg);
        if (empty($candidates)) {
            return 0;
        }

        $minScore = (float) ($cfg['min_score'] ?? 1.0);

        // The two caps are separate because the two signals are not the same kind
        // of thing.
        //
        // A `wanted` or `search` hit is somebody who ASKED for this - they have an
        // open post for it, or a saved search that matches. There is no good
        // reason to tell the first ten and not the eleventh, so the small cap
        // does not apply to them. `frequent` is only propensity ("you reply to a
        // lot of things"), which is a guess, and a guess is exactly what should
        // be rationed.
        //
        // Strong still has a ceiling, but it is a backstop against something
        // pathological, NOT a rationing of the signal - it should essentially
        // never bind. Sized from live:
        //
        //   - a rippled post reaches ~3,600 freeglers on average (max ~19,000),
        //     which is 0.14% of the 2.5M-member network;
        //   - a common term is held network-wide by single-digit thousands
        //     (~0.03% of the 27M live rows in users_searches hold "sofa");
        //   - so the in-reach population for a common OFFER is of the order of
        //     TEN people, before the not-yet-reached band, the cooldown, the
        //     weekly cap, post-email consent and the 0.85 threshold cut it
        //     further.
        //
        // An earlier version of this comment justified the ceiling with "358
        // members hold Sofa". That number was network-wide, with no geography
        // applied at all, and so said nothing about what one post would send -
        // filterEligible() bounds every candidate to the reach band. Quoting it
        // here made a non-problem look like a mailbomb.
        //
        // The ceiling stays because it costs nothing when it never fires, and it
        // is counted when it does (below), so a pathological post shows up
        // instead of quietly mailing everybody.
        $maxFrequent = max(0, (int) ($cfg['max_per_post'] ?? 10));
        $maxStrong = max(0, (int) ($cfg['max_strong_per_post'] ?? 50));

        // Nearest to the current reach edge first, score as the tiebreak. Every
        // candidate is outside today's polygon by construction (inside it the
        // ordinary ripple already tells them), but the reach will have grown by
        // the time a scout reads their mail - so the slots should go to the
        // people standing just past the edge, whom the reach is about to cover,
        // not to the strongest signal ten miles out. Strong signals lose nothing
        // by this: their cap is a never-binding backstop, so ordering only
        // decides who gets the rationed `frequent` slots.
        uasort($candidates, static function ($a, $b) {
            $cmp = ($a['dist'] ?? INF) <=> ($b['dist'] ?? INF);

            return $cmp !== 0 ? $cmp : $b['score'] <=> $a['score'];
        });

        $chosen = [];
        $strong = 0;
        $frequent = 0;
        $strongFound = 0;

        foreach ($candidates as $userId => $candidate) {
            if ($candidate['score'] < $minScore) {
                // Not `break`: the list is distance-ordered now, so a weak
                // nearby candidate must not hide a strong one further out.
                continue;
            }

            if ($candidate['reason'] === 'frequent') {
                if ($frequent >= $maxFrequent) {
                    continue;
                }
                $frequent++;
            } else {
                $strongFound++;
                if ($strong >= $maxStrong) {
                    continue;
                }
                $strong++;
            }

            $chosen[$userId] = $candidate;
        }

        // Only worth saying when the ceiling actually bit. This is the number that
        // tells us whether 50 is right, and it is the one thing the live data
        // could not answer up front.
        if (!$dryRun && $strongFound > $maxStrong) {
            Log::warning('firstreply: strong scout matches exceeded the ceiling', [
                'msgid' => $msgid,
                'found' => $strongFound,
                'ceiling' => $maxStrong,
            ]);
            $this->metrics->record('scouts_strong_capped', 1);
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

        $mailed = $this->digest->mailPostToUsers($msgid, $claimed);

        // Anyone we actually mailed must not be mailed again by the reach mailer
        // when the ripple eventually reaches them - that is the same post twice.
        // Keyed on who was really mailed, not who was claimed: a member whose
        // spool failed has had nothing, and must stay eligible for the reach mail.
        foreach ($mailed as $userId) {
            DB::table('rippling_reach_notified')->insertOrIgnore([
                'msgid' => $msgid,
                'userid' => $userId,
                'notified_at' => now(),
            ]);
        }

        // For a weak-signal scout the mail WAS their daily digest, moved earlier,
        // so today's digest must not also go: record it as sent. Strong-signal
        // scouts are excluded - that mail is an extra, justified by their own
        // request, and taking their digest away as well would be a straight loss.
        $broughtForward = array_values(array_filter(
            $mailed,
            static fn ($userId) => ($chosen[$userId]['reason'] ?? null) === 'frequent'
        ));
        $this->markDigestBroughtForward($broughtForward);

        return count($mailed);
    }

    /**
     * Record that these members have had their daily digest, because the scout
     * mail they just received is it.
     *
     * Stamps lastsent only, never the lastmsgid cursor. The cursor is what
     * decides which posts tomorrow's digest covers, and they have not actually
     * seen today's roll-up - so tomorrow's must still start from where it would
     * have. (The scouted post itself is not double-counted: the daily digest
     * excludes posts that have a rippling_reach row, which every scouted post
     * does.)
     *
     * @param int[] $userIds
     */
    private function markDigestBroughtForward(array $userIds): void
    {
        if (empty($userIds) || !Schema::hasTable('users_digests')) {
            return;
        }

        foreach ($userIds as $userId) {
            try {
                DB::table('users_digests')->updateOrInsert(
                    ['userid' => $userId, 'mode' => 'daily'],
                    ['lastsent' => now()]
                );
            } catch (\Throwable $e) {
                // Worst case the member also gets today's digest, which is the
                // behaviour before this existed. Not worth failing the send for.
                Log::warning('firstreply: could not mark digest brought forward', [
                    'userid' => $userId, 'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Live posts with no reply, young enough for a nudge to still help.
     *
     * quiet_minutes defaults to 0, so a post is scouted on the first run after it
     * appears. Holding back was tried and dropped: the point of scouting is speed,
     * and whatever a delay saves in mail is dwarfed by how long the scout then
     * takes to read it and reply. The wait removed nothing and added itself to
     * every reply.
     *
     * A post that yields no scouts writes no ledger row, so it stays a candidate
     * and is reconsidered next run. That matters at zero delay, because a
     * brand-new post can be seen here a minute or two before firstreply:maxreach
     * has populated its max_polygon - without which nobody is eligible at all.
     *
     * @return \Illuminate\Support\Collection<int,object>
     */
    private function silentPosts(array $cfg)
    {
        $quiet = max(0, (int) ($cfg['quiet_minutes'] ?? 45));
        $maxAge = max(1, (int) ($cfg['max_age_hours'] ?? 24));

        // A SMALL batch, newest first. Each post costs seconds (two apiv2
        // matcher calls, a spatial eligibility query, possibly mail), so 200
        // per run meant runs of 10+ minutes - long enough for the WRITE
        // connection to sit idle past the server's wait_timeout (600s), be
        // closed under the run, and wedge the process polling a dead socket
        // (the first live hour piled up 40+ such runs). At the every-minute
        // cadence a small batch drains far faster than posts arrive; newest
        // first because speed-to-first-reply is the whole point, and the
        // once-per-post ledger walks the batch through the backlog anyway.

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
                   )"
             . Rollout::sqlFilter('ms.msgid') . "
             ORDER BY ms.arrival DESC
             LIMIT " . max(1, (int) ($cfg['posts_per_run'] ?? 25)),
            [$quiet, $maxAge]
        ));
    }

    /**
     * Score everyone worth considering for this post.
     *
     * @return array<int,array{score:float, reason:string}>
     */
    private function candidates(object $post, array $cfg): array
    {
        $msgid = (int) $post->msgid;
        $poster = (int) $post->fromuser;
        $limit = max(1, (int) ($cfg['candidate_limit'] ?? 500));

        $scores = [];

        // The type rule - a WANTED matches an OFFER and vice versa, because
        // someone else wanting what you want is competition rather than a lead -
        // lives in the matcher rather than being reimplemented here.
        foreach ($this->matchingPosters($msgid, $limit) as $userId) {
            $scores[$userId] = ['score' => self::SCORE_WANTED, 'reason' => 'wanted'];
        }

        foreach ($this->savedSearchers($msgid, $limit) as $userId) {
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

        // Eligibility differs by how strong the signal is, because what justifies
        // the mail differs. A match on an outstanding post or a saved search is
        // something the member asked for, so it may be an extra mail. "You reply
        // to a lot of things" is not, so it may only ever be their daily digest
        // arriving early. `reason` holds the strongest signal that fired (they
        // are applied strongest first above), so it is the right thing to split
        // on. Two queries rather than one, but each candidate is still reach- and
        // fatigue-tested exactly once.
        $strong = array_keys(array_filter($scores, static fn ($c) => $c['reason'] !== 'frequent'));
        $weak = array_keys(array_filter($scores, static fn ($c) => $c['reason'] === 'frequent'));

        // id => distance beyond the current reach edge, from the eligibility
        // query itself so nobody is measured twice.
        $eligible = $this->filterEligible($msgid, $strong, $cfg, true)
            + $this->filterEligible($msgid, $weak, $cfg, false);

        $out = [];
        foreach ($eligible as $userId => $dist) {
            if (isset($scores[$userId])) {
                $out[$userId] = $scores[$userId] + ['dist' => $dist];
            }
        }

        return $out;
    }

    /**
     * People whose open post of the OPPOSITE type matches this one, by vector.
     *
     * /message/{id}/matches is the matcher the matched-posts email uses, and it
     * applies MinMatchedPostScore (0.85) rather than the 0.80 the on-site
     * "similar posts" strip uses. Those are separate numbers because precision
     * falls off a cliff: hand-judged on live posts, 0.85-0.90 scores 0.92 and
     * 0.80-0.85 scores 0.43. A strip you chose to look at can carry a weak
     * suggestion; an email cannot.
     *
     * This used to be SQL LIKE on subject keywords, which has no score at all -
     * "bed lever" matched "bed" and nothing could say how badly.
     *
     * Recall is traded away deliberately: a missed match costs one email nobody
     * sees, a junk match teaches somebody to ignore the next one.
     *
     * @return int[]
     */
    private function matchingPosters(int $msgid, int $limit): array
    {
        try {
            $matches = $this->api->matchesForPost($msgid, $limit);
        } catch (\Throwable $e) {
            Log::warning('firstreply: match lookup failed', [
                'msgid' => $msgid, 'error' => $e->getMessage(),
            ]);

            return [];
        }

        $ids = array_values(array_filter(array_map(
            static fn ($m) => (int) ($m['id'] ?? 0),
            is_array($matches) ? $matches : []
        )));

        if (empty($ids)) {
            return [];
        }

        return array_map('intval', DB::table('messages as m')
            ->whereIn('m.id', $ids)
            ->whereNull('m.deleted')
            ->whereNotExists(fn ($q) => $q->select('mo.id')
                ->from('messages_outcomes as mo')
                ->whereColumn('mo.msgid', 'm.id'))
            ->pluck('m.fromuser')
            ->unique()
            ->all());
    }


    /**
     * Members whose saved search matches this post, by vector, at the same bar.
     *
     * Terms are stored as DOCUMENT embeddings (EmbeddingService::processSearches),
     * so a post-vs-term cosine is on the same scale as post-vs-post and the one
     * threshold covers both signals rather than each needing its own.
     *
     * Previously SQL LIKE on the term: a saved search for "bed" fired on "bed
     * lever". Somebody who asked to hear about a thing has given about as clear
     * a consent signal as exists, which makes it worse, not better, to spend it
     * on a poor match.
     *
     * @return int[]
     */
    private function savedSearchers(int $msgid, int $limit): array
    {
        try {
            $matches = $this->api->searchMatchesForPost($msgid, $limit);
        } catch (\Throwable $e) {
            Log::warning('firstreply: saved-search match lookup failed', [
                'msgid' => $msgid, 'error' => $e->getMessage(),
            ]);

            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($m) => (int) ($m['userid'] ?? 0),
            is_array($matches) ? $matches : []
        ))));
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
     * Called BEFORE the top-N cap, which is what makes "if they have had one,
     * find other scouts" work: an excluded candidate does not leave a hole, the
     * next-best candidate takes the slot.
     *
     * $strong says what justifies the mail, and therefore what else is checked:
     *
     *  true  - the member has an outstanding post of the opposite type that
     *          matches, or a saved search that matches. They asked to be told
     *          about things like this, so it may be an EXTRA mail. Gated on
     *          users.relevantallowed, which is the existing consent for exactly
     *          this ("Suggested posts for you"), also honoured by the engagement
     *          mails and non-essential admin mails.
     *  false - the member just replies to a lot of things. Nothing here is about
     *          this item, so it may only ever be their daily digest arriving
     *          early: skipped if today's digest has already gone, and skipped if
     *          they accept no post email anywhere, because then there is no
     *          digest to bring forward.
     *
     * @param int[] $userIds
     * @return int[]
     */
    private function filterEligible(int $msgid, array $userIds, array $cfg, bool $strong): array
    {
        if (empty($userIds) || !$this->maxReach->available()) {
            // No max_polygon yet means no basis for reaching beyond today's reach,
            // and scouting inside today's reach only is the reach mailer's job.
            return [];
        }

        $cooldown = max(0, (int) ($cfg['user_cooldown_hours'] ?? 24));
        $weekCap = max(1, (int) ($cfg['user_max_per_week'] ?? 5));

        // "Today" is the London calendar day, matching the daily digest's own
        // once-per-day guard exactly (see UnifiedDigestService: a rolling 24h
        // window was rejected there because off-schedule sends make the digest
        // time drift later every day). The boundary is computed in PHP as a UTC
        // instant rather than with CONVERT_TZ, because that needs MySQL's named
        // timezone tables loaded and fails open to NULL where they are not.
        $londonDayStartUtc = \Carbon\Carbon::now('Europe/London')
            ->startOfDay()
            ->setTimezone('UTC')
            ->toDateTimeString();

        $extra = [];

        if ($strong) {
            $cadenceGate = 'AND u.relevantallowed = 1';
        } else {
            $cadenceGate = 'AND EXISTS (
                     SELECT 1 FROM memberships mem
                     WHERE mem.userid = u.id AND mem.collection = ?
                       AND mem.emailfrequency <> 0
                   )';
            $extra[] = Membership::COLLECTION_APPROVED;

            // Degrade rather than throw where the table has not been created.
            if (Schema::hasTable('users_digests')) {
                $cadenceGate .= "
               AND NOT EXISTS (
                     SELECT 1 FROM users_digests ud
                     WHERE ud.userid = u.id AND ud.mode = 'daily' AND ud.lastsent >= ?
                   )";
                $extra[] = $londonDayStartUtc;
            }
        }

        // The candidate's point, resolved the same "mylocation else lastlocation"
        // order the reach mailer uses, so a candidate is measured from the point
        // that decides their reach membership everywhere else. Built once and
        // interpolated three times (band tests + distance); each use binds SRID.
        $pointExpr = "ST_SRID(POINT(
                     CASE WHEN JSON_EXTRACT(u.settings, '$.mylocation.lat') IS NOT NULL
                               AND JSON_EXTRACT(u.settings, '$.mylocation.lng') IS NOT NULL
                          THEN CAST(JSON_EXTRACT(u.settings, '$.mylocation.lng') AS DECIMAL(10,6))
                          ELSE l.lng END,
                     CASE WHEN JSON_EXTRACT(u.settings, '$.mylocation.lat') IS NOT NULL
                               AND JSON_EXTRACT(u.settings, '$.mylocation.lng') IS NOT NULL
                          THEN CAST(JSON_EXTRACT(u.settings, '$.mylocation.lat') AS DECIMAL(10,6))
                          ELSE l.lat END
                   ), ?)";

        // The reach test and the "is this a real, mailable member" test in one
        // pass. dist is how far the candidate stands beyond the CURRENT reach
        // edge - the selection loop spends its slots nearest-first, because the
        // reach will have grown by the time a scout reads their mail, and the
        // people just past today's edge are the ones it is about to cover.
        // (Coordinate degrees, not metres - the geometry's SRID 3857 tag is a
        // site-wide mislabel - but ordering within one post is unaffected.)
        //
        // keep-raw: spatial ST_Contains/ST_Distance band tests over a JSON-vs-
        // locations CASE point expression, a dynamic IN list and a conditional
        // cadence-gate fragment - the builder cannot render this shape.
        $rows = DB::select(
            "SELECT u.id AS id,
                    ST_Distance(rr.polygon, $pointExpr) AS dist
             FROM users u
             LEFT JOIN locations l ON l.id = u.lastlocation
             JOIN rippling_reach rr ON rr.msgid = ?
             WHERE u.id IN (" . implode(',', array_fill(0, count($userIds), '?')) . ")
               AND u.deleted IS NULL
               AND (u.lastaccess IS NULL OR u.lastaccess > DATE_SUB(NOW(), INTERVAL 90 DAY))
               AND EXISTS (SELECT 1 FROM users_emails ue WHERE ue.userid = u.id AND ue.preferred = 1)
               AND rr.max_polygon IS NOT NULL
               -- OUTSIDE the reach the post has right now, INSIDE the reach it
               -- will eventually have. Someone already inside the current
               -- polygon is going to be told anyway, by the ordinary ripple, so
               -- scouting them spends a scout slot and a mail to change nothing.
               -- The whole point of a scout is to reach past the current edge.
               AND NOT ST_Contains(rr.polygon, $pointExpr)
               AND ST_Contains(rr.max_polygon, $pointExpr) = 1
               AND NOT EXISTS (
                     SELECT 1 FROM firstreply_scouts fs
                     WHERE fs.userid = u.id AND fs.sent_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
                   )
               AND (SELECT COUNT(*) FROM firstreply_scouts fs2
                    WHERE fs2.userid = u.id AND fs2.sent_at > DATE_SUB(NOW(), INTERVAL 7 DAY)) < ?
               AND NOT EXISTS (
                     SELECT 1 FROM rippling_reach_notified rn
                     WHERE rn.msgid = ? AND rn.userid = u.id
                   )
               $cadenceGate",
            array_merge([self::SRID, $msgid], $userIds, [self::SRID, self::SRID, $cooldown, $weekCap, $msgid], $extra)
        );

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->id] = $r->dist === null ? null : (float) $r->dist;
        }

        return $out;
    }

}
