<?php

namespace App\Services\FirstReply;

use App\Services\UnifiedDigestService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Telling the people who have actually asked for an item that one has turned up.
 *
 * Two separate things keep a post from the people who want it, and only one of
 * them is about reach.
 *
 * The first is digest frequency. Immediate mail on a rippling post goes only to
 * members with emailfrequency=IMMEDIATE; everyone on the daily digest hears
 * tomorrow, buried among everything else that was posted.
 *
 * The second is that reach grows over days, so somebody with an open WANTED for
 * exactly this item may be sitting outside today's polygon and inside next
 * Tuesday's.
 *
 * This addresses both, for the people it can be certain about: a member with an
 * open post of the OPPOSITE type that matches, or a saved search that matches.
 * They are mailed now, individually, about that one item.
 *
 * **It matches both ways round.** A new OFFER finds the people with open WANTEDs
 * for it, and a new WANTED finds the people sitting on open OFFERs of it. Both
 * sides of a would-be exchange get the chance to start it, rather than only
 * whichever of them happened to post second. The type rule lives in the matcher
 * (a WANTED matches an OFFER and vice versa; two WANTEDs are competition, not a
 * lead), so it is not reimplemented here.
 *
 * **Nothing here is a guess.** Every mail answers something the member asked for,
 * item by item, which is what justifies it being an EXTRA mail rather than their
 * digest moved earlier, and it is gated on users.relevantallowed - the existing
 * "Suggested posts for you" consent, which is exactly what this is, and which the
 * engagement mails and non-essential admin mails already honour. Propensity
 * ("this member replies to a lot of things and lives near here") is deliberately
 * not a signal: measured over three days on live it sent 7,902 mails for 3
 * replies, against 4 replies for the 7,909 sent in total.
 *
 * **The mail says why it arrived.** It is the immediate-digest layout for that one
 * post - the format members already recognise - with the post's own subject as the
 * email subject and one line at the top naming the post or search of theirs that
 * it matched. An anonymous version of this mail is indistinguishable from the
 * daily digest people already ignore, and the whole value of this one is that it
 * is genuinely for them, so it has to say so.
 *
 * Two signals, stronger first:
 *
 *  wanted - they have an open post of the opposite type that matches. Nearly a
 *           direct hit.
 *  search - they saved a search that matches. They asked to be told.
 *
 * Both start from a small national candidate set (people whose post or saved
 * search matches these words at all), so testing each against the post's
 * eventual reach polygon is cheap and they get the full benefit of it.
 *
 * Membership is NOT required. UnifiedDigestService's reach mailer is
 * members-only, and this deliberately is not: anyone inside the post's eventual
 * reach may be told about it, whether or not they have joined the community it
 * was posted to. Replying joins them - the in-app path calls AddMembership as
 * part of creating the reply, and an emailed reply is joined on its way in - so
 * the membership follows the interest instead of gating it.
 * (Product decision, Edward, 2026-08-05.)
 *
 * The per-(post, member) ledger - which doubles as the fatigue counter and the
 * attribution record - lives in `firstreply_scouts`. The table name is kept as it
 * is because those rows are the evidence base for how this mail performs, and
 * renaming a live table with foreign keys to tidy up a word would put that
 * continuity at risk for nothing.
 */
class MatchMailService
{
    private const SRID = 3857;

    /** `config` key overriding the safety ceiling on matches mailed per post. */
    public const CONFIG_MAX_PER_POST = 'firstreply_matchmail_max_per_post';

    /** Signal weights. Ordering matters more than the absolute numbers. */
    private const SCORE_WANTED = 5.0;

    private const SCORE_SEARCH = 3.0;

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
     * @return array{considered:int, posts_matched:int, mailed:int}
     */
    public function run(bool $dryRun = false): array
    {
        $stats = ['considered' => 0, 'posts_matched' => 0, 'mailed' => 0];

        if (!config('freegle.firstreply.enabled') || !config('freegle.firstreply.matchmail.enabled')) {
            return $stats;
        }

        // Same waking-hours window the ripple's own expansion respects: nothing
        // Freegle sends about a post goes out at half past midnight. An overnight
        // post waits for 06:00, which still beats the digest.
        $hour = (int) now()->format('G');
        if ($hour < (int) config('freegle.ripple.active_start_hour', 6)
            || $hour >= (int) config('freegle.ripple.active_end_hour', 23)) {
            return $stats;
        }

        $cfg = $this->matchConfig();

        foreach ($this->silentPosts($cfg) as $post) {
            $stats['considered']++;

            try {
                $sent = $this->mailMatchesFor($post, $cfg, $dryRun);
                if ($sent > 0) {
                    $stats['posts_matched']++;
                    $stats['mailed'] += $sent;
                }
            } catch (\Throwable $e) {
                Log::warning('firstreply: match mail failed', [
                    'msgid' => $post->msgid ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (!$dryRun && $stats['mailed'] > 0) {
            $this->metrics->record('matchmail_sent', $stats['mailed']);
        }

        return $stats;
    }

    /**
     * Match-mail config, with the per-post ceiling overridable at runtime.
     *
     * The env var sets the default, but env only changes on a deploy, and this is
     * the number worth turning down NOW - it is the entire mail bill of the
     * feature, and the reason to move it is usually that it is mailing too many
     * people, which is the worst time to be waiting for a release. `config` is the
     * same key/value table the rest of the batch code uses for state that has to
     * outlive a container.
     *
     * An absent key means "no opinion", so the env default stands and an empty
     * table behaves exactly as it did before. Zero is meaningful: it stops the
     * mail entirely without touching the enabled flags.
     */
    private function matchConfig(): array
    {
        $cfg = config('freegle.firstreply.matchmail');

        $override = DB::table('config')->where('key', self::CONFIG_MAX_PER_POST)->value('value');
        if ($override !== null && is_numeric($override)) {
            $cfg['max_per_post'] = max(0, (int) $override);
        }

        return $cfg;
    }

    /**
     * Stamp firstreply_scouts.replied_at for scouts who have since replied to the
     * post we told them about.
     *
     * This is the only thing that answers "does this work?", and it has to be
     * a sweep rather than a hook on the reply path: the reply arrives through
     * several doors (web, app, email, TrashNothing) and none of them knows or
     * should know that the replier was mailed.
     *
     * Attribution is deliberately weak - they replied after we mailed them, which
     * is correlation, not proof. That is why the score is read alongside the
     * unmailed reply rate rather than on its own.
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
            Log::warning('firstreply: match attribution failed', ['error' => $e->getMessage()]);

            return 0;
        }
    }

    /**
     * Where a member is, resolved the same "mylocation else lastlocation" way the
     * reach query and the digest mailer resolve it - so a recipient is measured from
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
     * A matched member replied, so bring the post's reach out far enough to include them.
     *
     * These mails go to people OUTSIDE the current reach - that is the whole
     * point of them - so a reply is evidence the item is wanted at a distance the
     * ripple has not got to yet. Leaving the reach where it is would mean the one
     * person we hand-picked can reply while their neighbours, who are just as
     * close to it, keep waiting on the clock. So the tick that covers the replier
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
                $this->metrics->record('matchmail_reply_expanded_reach');
            }
        } catch (\Throwable $e) {
            Log::warning('firstreply: could not pull reach out to a matched member who replied', [
                'msgid' => $msgid, 'userid' => $userId, 'error' => $e->getMessage(),
            ]);
        }
    }

    /** Pick and mail this post's matches. Returns how many were mailed. */
    public function mailMatchesFor(object $post, array $cfg, bool $dryRun = false): int
    {
        $msgid = (int) $post->msgid;

        // This fires as soon as a post is seen, which can be a beat before the
        // background pass has worked out the post's eventual reach - and without
        // that nobody is eligible. Ask for it directly rather than making the post
        // wait a minute for a different cron. Schedule-only, so it costs a JSON
        // decode and an UPDATE; posts that need a routing call are left to the
        // background pass and simply get no match mail this time round.
        $this->maxReach->populateForPost($msgid);

        $candidates = $this->candidates($post, $cfg);
        if (empty($candidates)) {
            return 0;
        }

        $minScore = (float) ($cfg['min_score'] ?? 1.0);

        // Everyone left is somebody who ASKED for this - they have an open post of
        // the opposite type that matches, or a saved search that matches. There is
        // no good reason to tell the first ten and not the eleventh, so the cap is
        // a backstop against something pathological rather than a rationing of the
        // signal, and it should essentially never bind. Sized from live:
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
        // It costs nothing when it never fires, and it is counted when it does
        // (below), so a pathological post shows up instead of quietly mailing
        // everybody.
        $maxPerPost = max(0, (int) ($cfg['max_per_post'] ?? 50));

        // Nearest to the current reach edge first, then score. Every candidate is
        // outside today's polygon by construction (inside it the ordinary ripple
        // already tells them), but the reach will have grown by the time they read
        // their mail - so if the ceiling ever does bind, the slots should go to the
        // people standing just past the edge rather than to the strongest signal
        // ten miles out.
        uasort($candidates, static function ($a, $b) {
            $cmp = ($a['dist'] ?? INF) <=> ($b['dist'] ?? INF);

            return $cmp !== 0 ? $cmp : $b['score'] <=> $a['score'];
        });

        $chosen = [];
        $found = 0;

        foreach ($candidates as $userId => $candidate) {
            if ($candidate['score'] < $minScore) {
                // Not `break`: the list is distance-ordered now, so a weak
                // nearby candidate must not hide a strong one further out.
                continue;
            }

            $found++;
            if (count($chosen) >= $maxPerPost) {
                continue;
            }

            $chosen[$userId] = $candidate;
        }

        // Only worth saying when the ceiling actually bit. This is the number that
        // tells us whether the ceiling is right, and it is the one thing the live
        // data could not answer up front.
        if (!$dryRun && $found > $maxPerPost) {
            Log::warning('firstreply: matches for a post exceeded the ceiling', [
                'msgid' => $msgid,
                'found' => $found,
                'ceiling' => $maxPerPost,
            ]);
            $this->metrics->record('matchmail_capped', 1);
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

        // Each recipient's own reason travels with them, so the mail can open by
        // saying which of their posts or searches this matched. That line is the
        // difference between "a digest, but sooner" - which people were already
        // ignoring - and a mail that is visibly about something they asked for.
        $reasons = [];
        foreach ($claimed as $userId) {
            $reasons[$userId] = $chosen[$userId]['reason'];
        }

        $mailed = $this->digest->mailPostToUsers($msgid, $claimed, false, $reasons);

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

        return count($mailed);
    }

    /**
     * Live posts with no reply, young enough for a nudge to still help.
     *
     * quiet_minutes defaults to 0, so a post is matched on the first run after it
     * appears. Holding back buys nothing: the point of this mail is speed,
     * and whatever a delay saves in mail is dwarfed by how long the recipient then
     * takes to read it and reply. The wait removed nothing and added itself to
     * every reply.
     *
     * A post that yields no matches writes no ledger row, so it stays a candidate
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

        unset($scores[$poster]);
        if (empty($scores)) {
            return [];
        }

        // id => distance beyond the current reach edge, from the eligibility
        // query itself so nobody is measured twice.
        $eligible = $this->filterEligible($msgid, array_keys($scores), $cfg);

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
     * A score is the point. Keyword matching cannot say HOW well two things
     * matched, so "bed lever" and "bed" are indistinguishable to it.
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
     * The bar matters most here. Somebody who asked to hear about a thing has
     * given about as clear a consent signal as exists, which makes it worse, not
     * better, to spend it on a poor match - a keyword search for "bed" that
     * fires on "bed lever" burns exactly the person most worth reaching.
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
     * Narrow a candidate list to people we may actually mail: alive, contactable,
     * recently active, inside the post's eventual reach, and not already mailed
     * too often.
     *
     * Called BEFORE the per-post cap, so an excluded candidate does not leave a
     * hole - the next-best candidate takes the slot.
     *
     * Everyone here has an outstanding post of the opposite type that matches, or
     * a saved search that matches. They asked to be told about things like this,
     * so this may be an EXTRA mail; the consent for that is users.relevantallowed
     * ("Suggested posts for you"), which the engagement mails and non-essential
     * admin mails also honour.
     *
     * @param int[] $userIds
     * @return int[]
     */
    private function filterEligible(int $msgid, array $userIds, array $cfg): array
    {
        if (empty($userIds) || !$this->maxReach->available()) {
            // No max_polygon yet means no basis for reaching beyond today's reach,
            // and mailing inside today's reach only is the reach mailer's job.
            return [];
        }

        $cooldown = max(0, (int) ($cfg['user_cooldown_hours'] ?? 24));
        $weekCap = max(1, (int) ($cfg['user_max_per_week'] ?? 5));

        // A candidate must have an address the mailer can actually use, judged
        // by the SAME rule User::email_preferred applies at spool time
        // (non-internal, non-excluded). A preferred=1 row is not enough on its
        // own: an internal @users.ilovefreegle.org address satisfies it, and
        // such a member would claim a slot and a cooldown here and then get
        // nothing at the mail stage. The domain lists come from the same config
        // the accessor reads, so the two cannot drift.
        $unmailable = [];
        $unmailableParams = [];
        foreach (config('freegle.mail.internal_domains', []) as $domain) {
            $unmailable[] = 'ue.email NOT LIKE ?';
            $unmailableParams[] = '%@' . $domain;
        }
        foreach (config('freegle.mail.excluded_domain_patterns', []) as $pattern) {
            $unmailable[] = 'ue.email NOT LIKE ?';
            $unmailableParams[] = '%' . $pattern . '%';
        }
        $mailableSql = $unmailable ? (' AND ' . implode(' AND ', $unmailable)) : '';

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
        // reach will have grown by the time they read their mail, and the
        // people just past today's edge are the ones it is about to cover.
        // (Coordinate degrees, not metres - the geometry's SRID 3857 tag is a
        // site-wide mislabel - but ordering within one post is unaffected.)
        //
        // keep-raw: spatial ST_Contains/ST_Distance band tests over a JSON-vs-
        // locations CASE point expression, a dynamic IN list and a conditional
        // cadence-gate fragment - the builder cannot render this shape.
        // Someone an overflow lane has already admitted is NOT a candidate for this mail.
        // They can see the post on browse, find it in search and reply to it, and the ordinary
        // reach mail tells them about it - so by this query's own rule ("mailing them spends a
        // slot and a mail to change nothing") they are already reached. The polygon test below
        // cannot see them, because a lane admits precisely the people outside it.
        //
        // Same SQL the mail path itself admits on, borrowed rather than restated: two
        // definitions of "admitted" drifting apart is the failure this area has been correcting.
        [$laneSql, $laneParams] = app(UnifiedDigestService::class)->overflowAdmissionSql($msgid, $pointExpr);
        $laneExclusion = '';
        if ($laneSql !== '') {
            // The lane SQL is a run of " OR (...)" clauses meant to follow a containment test;
            // stripped of that leading OR it is the bare "a lane admits them" condition.
            $laneExclusion = ' AND NOT (' . preg_replace('/^\s*OR\s+/', '', $laneSql) . ')';
        }

        $rows = DB::select(
            "SELECT u.id AS id,
                    ST_Distance(rr.polygon, $pointExpr) AS dist
             FROM users u
             LEFT JOIN locations l ON l.id = u.lastlocation
             JOIN rippling_reach rr ON rr.msgid = ?
             WHERE u.id IN (" . implode(',', array_fill(0, count($userIds), '?')) . ")
               AND u.deleted IS NULL
               AND (u.lastaccess IS NULL OR u.lastaccess > DATE_SUB(NOW(), INTERVAL 90 DAY))
               AND EXISTS (SELECT 1 FROM users_emails ue WHERE ue.userid = u.id{$mailableSql})
               AND rr.max_polygon IS NOT NULL
               -- OUTSIDE the reach the post has right now, INSIDE the reach it
               -- will eventually have. Someone already inside the current
               -- polygon is going to be told anyway, by the ordinary ripple, so
               -- mailing them spends a slot and a mail to change nothing.
               -- The whole point of this mail is to reach past the current edge.
               AND NOT ST_Contains(rr.polygon, $pointExpr)$laneExclusion
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
               -- 'Suggested posts for you'. Everyone here asked for this item,
               -- so the mail may be an EXTRA one rather than their digest moved
               -- earlier, and that is the consent for it - the same one the
               -- engagement and non-essential admin mails honour.
               AND u.relevantallowed = 1",
            array_merge(
                [self::SRID, $msgid],
                $userIds,
                $unmailableParams,
                [self::SRID],
                $laneParams,
                [self::SRID, $cooldown, $weekCap, $msgid]
            )
        );

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->id] = $r->dist === null ? null : (float) $r->dist;
        }

        return $out;
    }

}
