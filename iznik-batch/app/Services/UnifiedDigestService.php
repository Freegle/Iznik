<?php

namespace App\Services;

use App\Mail\Digest\DigestStyle;
use App\Mail\Digest\UnifiedDigest;
use App\Mail\Traits\FeatureFlags;
use App\Models\Message;
use App\Models\User;
use App\Models\UserDigest;
use App\Services\Ripple\DigestPostScorer;
use App\Services\Ripple\DistancePreferenceFilter;
use App\Services\Ripple\RingIndex;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Service for sending unified Freegle digests.
 *
 * This replaces the per-group digest system with a user-centric approach:
 * - One digest per user containing posts from all their communities
 * - Cross-posted items are deduplicated (shown once with "Posted to: A, B, C")
 * - Progress tracked per-user instead of per-group
 */
class UnifiedDigestService
{
    use FeatureFlags;

    public const EMAIL_TYPE = 'UnifiedDigest';

    /** The deferral gate, resolved once per run. */
    private ?\App\Services\Mail\MailSuppressionService $suppressionService = null;

    /**
     * Whether a provider is currently refusing our mail to this member.
     *
     * Consulted before every render. The service is a singleton and caches
     * the active suppression set in-process, so this stays cheap enough to
     * call once per recipient across tens of thousands of members.
     */
    private function suppressions(): \App\Services\Mail\MailSuppressionService
    {
        if ($this->suppressionService === null) {
            $this->suppressionService = app(\App\Services\Mail\MailSuppressionService::class);
        }

        return $this->suppressionService;
    }

    /** Per-run cache of post reach radius in metres, keyed by msgid. */
    private array $reachRadiusCache = [];

    /**
     * Digest mode constants.
     */
    public const MODE_IMMEDIATE = 'immediate';

    public const MODE_DAILY = 'daily';

    /** Reach-mail: decoupled, sharded pass that mails members newly inside a rippling post's reach. */
    public const MODE_REACH = 'reach';

    /**
     * Hard ceiling on how many posts getPostsForUser() loads into memory in one run.
     *
     * A digest renders at most DigestStyle::DIGEST_POST_CAP (65) posts, but the load is
     * unbounded by nature: it fetches every post since the member's last-digest cursor.
     * When the daily run falls behind (or a member's reach covers a lot of posts), that
     * window grows to days × posts-in-reach and the eager-loaded Collection (attachments/
     * fromUser) blows the PHP memory_limit — the run then dies part-way, so higher-id members
     * are never reached, their cursor stays stale, and the next run's window is even bigger
     * (self-amplifying). Capping the LOAD bounds per-member memory regardless of backlog:
     * updateDigestTracker() advances the cursor past exactly what was loaded (oldest-first),
     * so a backlogged member drains this many posts per run until caught up. Kept well above
     * the render cap so scoring/dedup still choose from a large pool for normal daily volume.
     */
    public const DIGEST_LOAD_CAP = 500;

    /**
     * How long a post the cap left out keeps being offered again (users_digests.carryover).
     *
     * A carried post is shown only in the room DIGEST_POST_CAP leaves once the new posts are
     * in (see newPostsFirst), so a member whose daily volume is above the cap may never have
     * room for it. Without an age bound it would then be re-offered for as long as it stayed
     * live - measured at up to 59 days on production - and it would sit at the head of every
     * window, because the load query is oldest-first and carried posts are older than the
     * cursor. Three days is two further chances after the digest that could not fit it; past
     * that it is not news to anybody.
     */
    public const CARRYOVER_MAX_AGE_DAYS = 3;

    /**
     * Send unified digests to users who want them.
     *
     * @param  string  $mode  One of MODE_IMMEDIATE, MODE_REACH or MODE_DAILY. MODE_IMMEDIATE and
     *                        MODE_REACH both run the reach-mail pass: a user's location, not group membership,
     *                        decides whether a post is in reach.
     * @param  int|null  $userId  Specific user ID to process (for testing)
     * @return array Statistics about the operation
     */
    public function sendDigests(string $mode, ?int $userId = null, ?int $limit = null, bool $dryRun = false, ?int $groupId = null, int $shard = 0, int $shards = 1, ?callable $shouldStop = null): array
    {
        // $groupId is accepted but unused: reach is the only immediate-mail pathway, and it mails
        // by location rather than by any single origin community, so there is nothing to scope by.
        if ($mode === self::MODE_IMMEDIATE || $mode === self::MODE_REACH) {
            return $this->sendReachDigests($limit, $dryRun, $shard, $shards, $shouldStop);
        }

        $stats = [
            'users_processed' => 0,
            'emails_sent' => 0,
            'no_new_posts' => 0,
            'suppressed' => 0,
            'errors' => 0,
        ];

        // Check if this email type is enabled.
        if (! self::isEmailTypeEnabled(self::EMAIL_TYPE)) {
            Log::info('UnifiedDigest emails disabled via FREEGLE_MAIL_ENABLED_TYPES');

            return $stats;
        }

        $users = $this->getUsersForDigest($mode, $userId, $shard, $shards);

        if ($limit) {
            $users = $users->take($limit);
        }

        foreach ($users as $user) {
            // Graceful interrupt: SIGTERM/SIGINT (or an abort-file touch) flips
            // the caller's shouldStop flag. Check between users so a kill
            // drains the current per-user spool write before exiting — at
            // worst one duplicate next run, never a torn write.
            if ($shouldStop !== null && $shouldStop()) {
                $stats['stopped'] = true;
                Log::info('UnifiedDigestService: Daily digest stopping on shutdown signal', [
                    'users_processed' => $stats['users_processed'],
                    'emails_sent' => $stats['emails_sent'],
                ]);
                break;
            }

            try {
                $result = $this->sendDigestToUser($user, $mode, $dryRun);

                if ($result['status'] === 'sent') {
                    $stats['emails_sent'] += $result['count'];
                } elseif ($result['status'] === 'no_posts') {
                    $stats['no_new_posts']++;
                } elseif ($result['status'] === 'suppressed') {
                    $stats['suppressed']++;
                }
            } catch (\Exception $e) {
                Log::error("UnifiedDigestService: Failed to send digest to user {$user->id}", [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                $stats['errors']++;
            }

            $stats['users_processed']++;
        }

        Log::info('UnifiedDigestService: Digest send complete', $stats);

        return $stats;
    }

    /**
     * Decoupled, sharded reach-mail pass. Mails members who are newly inside a rippling post's
     * reach polygon — the work that used to run inline in ExpandService's serial Phase-2 loop,
     * where (per the 2026-06-24 live profile) it was ~75% of the run's wall-clock. Pulling it out
     * lets the reach writes stay serial (Galera single-writer) while the mail fans out in parallel.
     *
     * Processes rippling_reach rows whose reach changed recently (updated_at within the configured
     * window — reach only changes on init/advance, never on this pass, which writes only
     * rippling_reach_notified), partitioned across parallel workers by MOD(msgid, shards). Disjoint
     * partitions → shards run concurrently with no locking. Idempotent regardless of window overlap: the
     * rippling_reach_notified ledger means an already-notified member is never re-mailed.
     *
     * @param  int|null  $limit  Cap on posts processed per run (null = no cap).
     * @param  int  $shard  Shard index (0..shards-1).
     * @param  int  $shards  Total shard count; posts partitioned by MOD(msgid, shards).
     * @return array{posts_processed:int,emails_sent:int,errors:int,stopped?:bool}
     */
    public function sendReachDigests(?int $limit = null, bool $dryRun = false, int $shard = 0, int $shards = 1, ?callable $shouldStop = null): array
    {
        $stats = ['posts_processed' => 0, 'members_processed' => 0, 'emails_sent' => 0, 'errors' => 0];

        if (! self::isEmailTypeEnabled(self::EMAIL_TYPE)) {
            return $stats;
        }

        // Only posts whose reach changed since the last pass need a mail check: an unchanged
        // polygon has no newly-inside members the ledger has not already covered. Each shard
        // resumes from a stored mark rather than re-reading a 60-minute window every minute.
        // That window was 47-68% of db2, ~95% of it re-doing unchanged work, and it doubled as
        // the only grace period for members who became eligible after a post's reach settled.
        // That job now belongs to the member queue (drainMemberQueue), so the mark can be exact.
        //
        // The mark is the time this pass STARTED, taken before the query. Timestamps are
        // second-granular, so storing the newest updated_at seen would skip a row written in
        // the same second after the read; pass-start guarantees it is >= mark next tick. The
        // small overlap is harmless because the ledger dedupes. A cold start (no mark) reads
        // the last hour, which is what the window read, so it costs what today costs.
        $passStart = now();
        $mark = $this->reachMailMark($shard) ?? now()->subMinutes(60);

        $query = DB::table('rippling_reach')
            ->whereIn('status', ['expanding', 'done'])
            ->where('updated_at', '>=', $mark->toDateTimeString());

        // Disjoint MOD(msgid, shards) partition: each post is owned by exactly one shard,
        // so shards run concurrently safely.
        if ($shards > 1) {
            $query->whereRaw('MOD(msgid, ?) = ?', [$shards, $shard]);
        }

        $query->orderBy('updated_at'); // oldest-changed first so a backlog drains fairly
        if ($limit !== null) {
            $query->limit($limit);
        }

        foreach ($query->pluck('msgid') as $msgid) {
            if ($shouldStop && $shouldStop()) {
                $stats['stopped'] = true;
                break;
            }
            try {
                $stats['emails_sent'] += $this->mailNewlyReachedForPost((int) $msgid, $dryRun);
                $stats['posts_processed']++;
            } catch (\Throwable $e) {
                $stats['errors']++;
                Log::warning('reach-mail: failed for post', ['msgid' => $msgid, 'error' => $e->getMessage()]);
            }
        }

        // The other direction: members whose eligibility changed since the last pass.
        $this->drainMemberQueue($stats, $dryRun, $shard, $shards, $shouldStop);

        // Advance only after a complete, clean, real pass. A dry run must leave the mark where
        // it is or the next real pass skips what it previewed; a pass stopped early or with a
        // failed post must too, so the next pass re-examines from the old mark. Re-examining is
        // free of side effects (the ledger dedupes); a post silently dropped is not.
        if (! $dryRun && $stats['errors'] === 0 && empty($stats['stopped'])) {
            $this->setReachMailMark($shard, $passStart);
        }

        return $stats;
    }

    /**
     * Drain the member side of reach mail: members queued in rippling_reach_member_pending
     * because they joined a group, moved, returned after a long absence or switched to
     * immediate mail (see ReachMemberQueueService and iznik-server-go's reachqueue). For
     * each, find the live posts whose reach might cover them and ask mailNewlyReachedForPost
     * about that one member - the same containment test and the same ledger the post side
     * uses, so there is one definition of "in reach" and nobody is mailed twice.
     *
     * Candidates are posts on a group the member belongs to with immediate mail, whose stored
     * outer bound contains the member's point (the spatial index does that), plus any post
     * whose overflow ring admits the point per the ring index. mailNewlyReachedForPost then
     * applies the exact label test, so a candidate the bound admits but the label does not
     * is simply not mailed.
     *
     * Partitioned by MOD(userid, shards) like posts are by msgid, so concurrent shards drain
     * disjoint members. The row is removed once the member has been evaluated, mailed or
     * not: a member no live reach covers has nothing to wait for. A dry run leaves the queue
     * as it found it.
     */
    private function drainMemberQueue(array &$stats, bool $dryRun, int $shard, int $shards, ?callable $shouldStop): void
    {
        $srid = (int) config('freegle.srid', 3857);

        $pending = DB::table('rippling_reach_member_pending')->orderBy('id');
        if ($shards > 1) {
            $pending->whereRaw('MOD(userid, ?) = ?', [$shards, $shard]);
        }

        foreach ($pending->limit(500)->get(['id', 'userid']) as $row) {
            if ($shouldStop && $shouldStop()) {
                $stats['stopped'] = true;
                break;
            }

            try {
                $user = User::find($row->userid);
                $point = $user ? $this->resolveUserLatLng($user) : null;

                // emailfrequency -1 is the site-wide immediate setting; anyone else queued here
                // (their location moved, or a post's reach grew over them) has nothing to be
                // mailed about now regardless of what the reach covers.
                if ($point !== null && $user->emailfrequency === -1) {
                    [$lat, $lng] = $point;

                    $candidates = DB::table('rippling_reach as mr')
                        ->whereIn('mr.status', ['expanding', 'done'])
                        ->whereRaw("ST_GeometryType(mr.outer_bound) <> 'POINT'")
                        ->whereRaw('ST_Contains(mr.outer_bound, ST_SRID(POINT(?, ?), ?))', [$lng, $lat, $srid])
                        ->pluck('mr.msgid')
                        ->map(fn ($id) => (int) $id)
                        ->all();

                    $settings = is_string($user->settings) ? (json_decode($user->settings, true) ?: []) : (array) $user->settings;
                    $band = $settings['browseDensityBand'] ?? null;
                    $ringAdmitted = RingIndex::admittedFor($lat, $lng, RingIndex::lanesFor(is_string($band) ? $band : null));

                    foreach (array_unique(array_merge($candidates, $ringAdmitted)) as $msgid) {
                        $stats['emails_sent'] += $this->mailNewlyReachedForPost((int) $msgid, $dryRun, (int) $row->userid);
                    }
                }

                $stats['members_processed']++;
                if (! $dryRun) {
                    DB::table('rippling_reach_member_pending')->where('id', $row->id)->delete();
                }
            } catch (\Throwable $e) {
                $stats['errors']++;
                Log::warning('reach-mail: failed for queued member', ['userid' => $row->userid, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Where shard N of the reach pass keeps its mark. Shards partition posts by MOD(msgid, N)
     * and run concurrently, so each has its own.
     */
    public static function reachMailMarkKey(int $shard): string
    {
        return "reach_mail_mark_shard{$shard}";
    }

    private function reachMailMark(int $shard): ?\Illuminate\Support\Carbon
    {
        $raw = DB::table('config')->where('key', self::reachMailMarkKey($shard))->value('value');

        return $raw ? \Illuminate\Support\Carbon::parse($raw) : null;
    }

    private function setReachMailMark(int $shard, \Illuminate\Support\Carbon $at): void
    {
        DB::table('config')->upsert(
            ['key' => self::reachMailMarkKey($shard), 'value' => $at->toDateTimeString()],
            ['key'],
            ['value']
        );
    }

    /**
     * The ring's BOUNDING BOX as a widening of who this post's mail enumerates.
     *
     * Not the ring itself. The ring is 37,000 vertices of WKT inside a JSON
     * column: testing it here means parsing it per candidate row, and - worse -
     * it means this path deciding for itself who a ring admits while every other
     * surface asks the spatial index. That is how the mail came to invite members
     * the site refused. The box is four numeric comparisons and no geometry, and
     * it is only ever a prefilter: keepRingAdmitted() asks the index which of
     * these candidates the ring really admits.
     *
     * Returns ['', []] when no lane is on, so a post with no applicable lane runs
     * precisely the query it always ran.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function overflowBboxBranch(int $msgid, string $lngExpr, string $latExpr): array
    {
        $none = ['', []];

        $ruralOn = (bool) config('freegle.ripple.rural_access.enabled', false);
        $fairnessOn = (bool) config('freegle.ripple.fairness.enabled', false);
        $clusterOn = (bool) config('freegle.ripple.cluster.enabled', false);
        if (! $ruralOn && ! $fairnessOn && ! $clusterOn) {
            return $none;
        }

        try {
            // The ring document: overflow_cells, which mirrors the retired
            // overflow_bounds lane keys by design and carries the bbox scalar
            // too (rows written before the drop lack it, and fall to the
            // widen-to-everyone branch, which is safe).
            $raw = DB::table('rippling_reach')->where('msgid', $msgid)->value('overflow_cells');
            $bounds = is_string($raw) ? json_decode($raw, true) : null;
            if (! is_array($bounds)) {
                return $none;
            }

            // Does this post carry a ring on any lane that is switched on? If not,
            // there is nothing to widen for and the query stays exactly as it was.
            $applicable = ($ruralOn && ! empty($bounds['rural']))
                || ($fairnessOn && ! empty($bounds['fairness']))
                || ($clusterOn && ! empty($bounds['cluster']));
            if (! $applicable) {
                return $none;
            }

            $box = $bounds['bbox'] ?? null;
            if (! is_array($box) || count($box) < 4) {
                // A ring with no stored box. Widen to every candidate rather than to
                // none: narrowing is an optimisation, and skipping the lane instead
                // would take this post's ring dark HERE while the site went on
                // honouring it - a member invited by neither, or worse, shown a post
                // the mail never mentioned. The index still decides who is in, and
                // the candidate set is this post's own group members at this
                // frequency, not the membership at large.
                return [' OR 1 = 1', []];
            }

            [$minLng, $minLat, $maxLng, $maxLat] = array_map('floatval', array_slice($box, 0, 4));

            // Compared against the member's coordinate EXPRESSIONS (mylocation else
            // lastlocation), not against a constructed point. Building a geometry to
            // pull ST_X/ST_Y back out of it would cost a geometry per candidate row -
            // and, because the point expression carries its own SRID placeholder,
            // naming it twice silently changes how many binds this fragment needs.
            // Four values, four placeholders, no geometry.
            $sql = " OR ($lngExpr BETWEEN ? AND ? AND $latExpr BETWEEN ? AND ?)";

            return [$sql, [$minLng, $maxLng, $minLat, $maxLat]];
        } catch (\Throwable $e) {
            Log::warning('ripple: overflow bbox branch failed', ['msgid' => $msgid, 'error' => $e->getMessage()]);

            return $none;
        }
    }

    /**
     * Keep the members the post's ring actually admits, plus everyone the
     * committed reach already covered.
     *
     * The rows arrive from a query widened by the ring's bounding box, so the
     * ones outside the polygon are candidates and nothing more. The spatial
     * index decides - the same index, and the same answer, that browse, search,
     * the badge, the message page and the reply gate get. A member this drops is
     * a member no surface would have admitted; a member it keeps can find the
     * post and reply to it.
     */
    private function keepRingAdmitted(array $rows, int $msgid): array
    {
        $candidates = [];
        $kept = [];

        foreach ($rows as $i => $row) {
            if ((int) ($row->in_primary ?? 0) === 1) {
                $kept[] = $row;                       // already in the committed reach

                continue;
            }
            if ($row->resolved_lat === null || $row->resolved_lng === null) {
                continue;                             // no location: no ring can admit them
            }
            $lanes = RingIndex::lanesFor(is_string($row->density_band ?? null) ? $row->density_band : null);
            if ($lanes === []) {
                continue;
            }
            $candidates[$i] = [
                'lat' => (float) $row->resolved_lat,
                'lng' => (float) $row->resolved_lng,
                'lanes' => $lanes,
            ];
        }

        // The deprivation lane is per MEMBER, not per post: apiv2 tests the ring
        // belonging to the viewer's OWN fifth (rippling.ViewerFairnessPath), so the
        // mail must ask the same or the two admit different people. The fifth is not
        // recorded anywhere - it is asked of the spatial server, in one call for all
        // the candidates, as it always was.
        foreach ($this->fairnessLanes($msgid, $candidates) as $i => $lane) {
            $candidates[$i]['lanes'][] = $lane;
        }
        $candidates = array_filter($candidates, fn ($c) => $c['lanes'] !== []);

        foreach (RingIndex::admits($msgid, $candidates) as $i) {
            $kept[] = $rows[$i];
        }

        return $kept;
    }

    /**
     * The fairness ring path for each candidate, keyed as $candidates is.
     *
     * Absent for anyone whose fifth is unknown or outside the lane's range, and for
     * everyone if the lookup fails - the same fail-closed posture the old
     * quintile filter had, for the same reason: 0 means "no data", never "deprived".
     *
     * @param  array<int|string, array{lat: float, lng: float, lanes: array<int, string>}>  $candidates
     * @return array<int|string, string>
     */
    private function fairnessLanes(int $msgid, array $candidates): array
    {
        if ($candidates === [] || ! (bool) config('freegle.ripple.fairness.enabled', false)) {
            return [];
        }

        $keys = array_keys($candidates);
        $points = array_map(fn ($c) => [$c['lat'], $c['lng']], array_values($candidates));
        $maxQuintile = max(1, min(4, (int) config('freegle.ripple.fairness.max_quintile', 1)));

        try {
            $base = rtrim((string) config('freegle.routing_server_url'), '/');
            $response = Http::timeout(10)->post($base.'/v1/quintiles', ['points' => $points]);
            $quintiles = $response->successful() ? ($response->json('quintiles') ?? null) : null;

            // A short or missing array cannot be matched back to people by position, and
            // a mismatched one would attribute one member's deprivation to another.
            if (! is_array($quintiles) || count($quintiles) !== count($points)) {
                throw new \RuntimeException('quintile lookup returned '
                    .(is_array($quintiles) ? count($quintiles) : 'nothing')
                    .' answers for '.count($points).' points');
            }
        } catch (\Throwable $e) {
            Log::warning('ripple: fairness quintile lookup failed, no ring admits on that lane', [
                'msgid' => $msgid, 'candidates' => count($points), 'error' => $e->getMessage(),
            ]);

            return [];
        }

        $lanes = [];
        foreach ($keys as $i => $key) {
            $q = (int) ($quintiles[$i] ?? 0);
            if ($q >= 1 && $q <= $maxQuintile) {
                // Quoted: a JSON path member that is a number is not a bare
                // identifier, so $.fairness.1 addresses nothing.
                $lanes[$key] = '$.fairness."'.$q.'"';
            }
        }

        return $lanes;
    }

    private function srid(): int
    {
        return (int) config('freegle.srid', 3857);
    }

    /**
     * Newly-reached rippling immediate mail (#0 step 4). Called by the decoupled, sharded reach-mail
     * pass (sendReachDigests) and by AutoApproveService (the post-'done' approval gap) — no longer
     * inline in ExpandService's serial loop. Mails the post to every immediate-eligible user whose
     * location the reach NOW covers and who has not already been notified (rippling_reach_notified),
     * recording each so a later tick never re-mails them. Because it re-runs every tick (no cursor),
     * users the reach reaches later are picked up; the cursor digest excludes reach-row posts so
     * neither path double-mails. Member point = settings.mylocation (both coords) else lastlocation.
     * Returns the number spooled. Best-effort: any failure is logged, never aborts the expander.
     */
    public function mailNewlyReachedForPost(int $msgid, bool $dryRun = false, ?int $onlyUserid = null): int
    {
        if (! self::isEmailTypeEnabled(self::EMAIL_TYPE)) {
            return 0;
        }

        try {
            $msg = Message::with($this->digestPostEagerLoads())->find($msgid);
            if ($msg === null) {
                return 0;
            }

            $srid = (int) config('freegle.srid', 3857);
            // The resolved-point CASE expression is repeated for ST_Contains' argument AND
            // (new) projected as plain columns — same "mylocation else lastlocation" order
            // as resolveUserLatLng, so the distance-preference filter below measures from
            // exactly the point that decided reach-polygon membership, not a second,
            // possibly-divergent resolution.
            // One definition of the member's point, used by the projection, by the reach
            // containment and by any overflow ring - so a member cannot be admitted on one
            // resolution and then measured from another.
            $latExpr = "CASE WHEN JSON_EXTRACT(u.settings, '$.mylocation.lat') IS NOT NULL
                                 AND JSON_EXTRACT(u.settings, '$.mylocation.lng') IS NOT NULL AND u.tnuserid IS NULL
                            THEN CAST(JSON_EXTRACT(u.settings, '$.mylocation.lat') AS DECIMAL(10,6))
                            ELSE l.lat END";
            $lngExpr = "CASE WHEN JSON_EXTRACT(u.settings, '$.mylocation.lat') IS NOT NULL
                                 AND JSON_EXTRACT(u.settings, '$.mylocation.lng') IS NOT NULL AND u.tnuserid IS NULL
                            THEN CAST(JSON_EXTRACT(u.settings, '$.mylocation.lng') AS DECIMAL(10,6))
                            ELSE l.lng END";
            $point = "ST_SRID(POINT($lngExpr, $latExpr), ?)";

            // The ring WIDENS who this query enumerates, but it does not decide who
            // the ring admits. That decision belongs to the spatial index, and to
            // nothing else - see RingIndex. Here the widening is the ring bbox
            // only: four numeric comparisons against the stored box, no geometry
            // parsed, no ST_Contains against a 37k-vertex ring. Members it lets
            // through are candidates; the index says which of them are in.
            // The lane name is no longer needed here: which lane admits whom is
            // settled by the lanes each candidate is asked about, in keepRingAdmitted.
            [$overflowSql, $overflowParams] = $this->overflowBboxBranch($msgid, $lngExpr, $latExpr);

            // Which arm brought each member in, and which lanes they are in. Both
            // are needed now for every ring lane, not just fairness: a candidate
            // outside the polygon is only a recipient if the ring index says so,
            // and the index needs their band to know which rural ring may admit
            // them. NOTE: these sit before the WHERE in the SQL text, so their
            // parameters come FIRST in the array below.
            // The containment test per candidate member. The SQL narrows by
            // the stored OUTER BOUND (a superset), and exactness lives in
            // PHP: the post's stored LABEL is evaluated at every surviving
            // candidate's point in one routing call - the reach record, with
            // no grid fallback. No label, or routing unreachable, means
            // nobody is newly-reached this round; the next sweep re-asks.
            $reachSvc = app(\App\Services\Ripple\ReachService::class);
            $reachRow = DB::table('rippling_reach')->where('msgid', $msgid)->first();
            // Staged-next label when its stamp is the live partition, else
            // the live one - the routing server can only decode its own.
            $probeLabels = \App\Services\Ripple\ReachService::pickLabels($reachRow);
            $currentSecs = $reachRow !== null
                ? $reachSvc->currentBudgetSecs((int) ($reachRow->tick ?? 0), (float) ($reachRow->max_drive_min ?? 0), $reachRow->schedule ?? null)
                : 0.0;
            $containSql = "(ST_GeometryType(mr.outer_bound) <> 'POINT' AND ST_Contains(mr.outer_bound, $point))";

            // in_primary: from the outer bound, refined by the PHP probe
            // below. density_band rides along for the ring index whenever
            // either consumer needs post-filtering.
            $primaryFlag = ", $containSql AS in_primary"
                .", JSON_UNQUOTE(JSON_EXTRACT(u.settings, '$.browseDensityBand')) AS density_band";
            $primaryParams = [$srid];

            // Every msgid that is the same item as this one. A member who already had an
            // immediate mail about any copy has had this post, so the ledger is read across the
            // whole set - otherwise a hand cross-post or an unmerged TrashNothing copy mails
            // them again as its own reach grows over them.
            $itemCopies = $this->itemSiblingMsgids([$msgid])[$msgid] ?? [$msgid];
            $itemCopiesSql = implode(',', array_fill(0, count($itemCopies), '?'));

            // The member-queue drain asks about ONE member. Same query, same containment,
            // same ledger - keyed to that member rather than enumerating everyone on the
            // post, which at ~0.7s a post would cost about what the pass's mark saves.
            $onlySql = $onlyUserid !== null ? ' AND u.id = ?' : '';
            $onlyParams = $onlyUserid !== null ? [$onlyUserid] : [];

            // status <> 'held': a frozen reach belongs to a post whose origin copy has been
            // pulled back for moderation. Browse, the badge and search hide it, so mailing it
            // would be the one surface still pushing a post that is under review. Freezing is
            // one-way, so this is not a race that resolves.
            //
            // Withdrawn joins Taken/Received: all three mean the post is gone, and a member
            // newly inside the reach of a withdrawn post has nothing to reply to.
            //
            // keep-raw: spatial predicates (ST_Contains, ST_SRID, ST_GeomFromText) and the
            // JSON_EXTRACT point resolution have no query-builder equivalent.
            $recipientRows = collect(DB::select(
                "SELECT DISTINCT u.id AS id,
                       $latExpr AS resolved_lat,
                       $lngExpr AS resolved_lng$primaryFlag
                 FROM rippling_reach mr
                 JOIN users u ON u.emailfrequency = ?
                 LEFT JOIN locations l ON l.id = u.lastlocation
                 WHERE mr.msgid = ?
                   AND mr.status <> 'held'
                   AND NOT EXISTS (
                         SELECT 1 FROM messages_outcomes mo
                         WHERE mo.msgid = mr.msgid AND mo.outcome IN ('Taken', 'Received', 'Withdrawn')
                       )
                   AND u.deleted IS NULL AND (u.lastaccess IS NULL OR u.lastaccess > ?)$onlySql
                   AND ($containSql$overflowSql)
                   AND NOT EXISTS (
                         SELECT 1 FROM rippling_reach_notified n
                         WHERE n.msgid IN ($itemCopiesSql) AND n.userid = u.id
                       )",
                array_merge(
                    $primaryParams,
                    [-1, $msgid, now()->subDays(90)],
                    $onlyParams,
                    [$srid],
                    $overflowParams,
                    $itemCopies
                )
            ));

            // Refine the outer-bound superset to the exact reach. Labels
            // first: ONE routing call evaluates the stored label at every
            // candidate point at the current budget. The cell grid remains
            // the fallback for unlabelled posts or when routing cannot
            // answer; a candidate nothing can decide is only a recipient
            // if a ring admits them, exactly like a candidate outside the
            // reach.
            $labelIn = [];
            if ($probeLabels !== null && $probeLabels !== '' && $currentSecs > 0) {
                $points = [];
                foreach ($recipientRows as $i => $row) {
                    if ($row->resolved_lat !== null && $row->resolved_lng !== null) {
                        $points[$i] = [(float) $row->resolved_lat, (float) $row->resolved_lng];
                    }
                }
                $evals = $points !== [] ? $reachSvc->reachArrivalBatch((string) $probeLabels, $currentSecs, $points) : [];
                foreach ($points as $i => $p) {
                    $labelIn[$i] = (bool) (($evals[$i]['in'] ?? false));
                }
            }
            foreach ($recipientRows as $i => $row) {
                $in = ($labelIn[$i] ?? false);
                $row->in_primary = ((int) ($row->in_primary ?? 0) === 1 && $in) ? 1 : 0;
            }
            $recipientRows = $recipientRows->filter(
                fn ($row) => (int) ($row->in_primary ?? 0) === 1 || $overflowSql !== ''
            )->values();

            if ($overflowSql !== '') {
                $recipientRows = collect($this->keepRingAdmitted($recipientRows->all(), $msgid));
            }

            $recipientIds = $recipientRows->pluck('id')->map(fn ($v) => (int) $v)->all();

            if (empty($recipientIds)) {
                return 0;
            }

            // Recipient point resolved by the SQL above (mylocation else lastlocation) —
            // reused by the distance-preference filter below instead of re-resolving.
            $recipientLatLng = [];
            foreach ($recipientRows as $row) {
                $recipientLatLng[(int) $row->id] = ($row->resolved_lat !== null && $row->resolved_lng !== null)
                    ? [(float) $row->resolved_lat, (float) $row->resolved_lng]
                    : null;
            }

            // Same allowlist gate as the cursor immediate digest.
            $allowlist = $this->getImmediateAllowlist();
            if ($allowlist !== ['*']) {
                $lower = array_map('strtolower', $allowlist);
                $recipientIds = DB::table('users_emails')
                    ->whereIn('userid', $recipientIds)
                    // users_emails.email is utf8mb4_unicode_ci, so this is
                    // already case-insensitive. The LOWER() wrapper bought
                    // nothing and stopped the index being usable.
                    ->whereIn('email', $lower)
                    ->pluck('userid')->unique()->map(fn ($v) => (int) $v)->all();
                if (empty($recipientIds)) {
                    return 0;
                }
            }

            return count($this->spoolPostToRecipients($msg, $recipientIds, $recipientLatLng, $dryRun));
        } catch (\Throwable $e) {
            Log::warning('ripple: mailNewlyReachedForPost failed', ['msgid' => $msgid, 'error' => $e->getMessage()]);

            return 0;
        }
    }

    /**
     * Mail one post immediately to an explicit list of members, bypassing both the
     * reach ledger's "newly reached" logic and the recipient's digest frequency.
     *
     * This exists for match mail (App\Services\FirstReply\MatchMailService), which
     * picks the members whose own open post of the opposite type, or saved search,
     * matches a specific post, and tells them now rather than when their daily
     * digest runs or when the ripple eventually arrives. The caller has already
     * decided WHO; this decides nothing except whether each of them can be mailed
     * at all.
     *
     * The layout is the reach immediate digest, so recipients get the format they
     * already recognise. $reasons (userid => 'wanted'|'search') adds the one thing
     * that must differ: a line saying why this particular mail is for them, and
     * the post's own subject on the envelope. Without that it reads as the daily
     * digest arriving early, which is the mail they are already ignoring.
     *
     * Returns the ids actually mailed rather than a count, because the caller has
     * to know exactly who received it - not including anyone whose spool failed.
     *
     * @param  int[]  $userIds
     * @param  array<int,string>  $reasons
     * @return int[]
     */
    public function mailPostToUsers(int $msgid, array $userIds, bool $dryRun = false, array $reasons = []): array
    {
        if (! self::isEmailTypeEnabled(self::EMAIL_TYPE) || empty($userIds)) {
            return [];
        }

        try {
            $msg = Message::with($this->digestPostEagerLoads())->find($msgid);
            if ($msg === null) {
                return [];
            }

            $allowlist = $this->getImmediateAllowlist();
            if ($allowlist !== ['*']) {
                $lower = array_map('strtolower', $allowlist);
                $userIds = DB::table('users_emails')
                    ->whereIn('userid', $userIds)
                    ->whereIn(DB::raw('LOWER(email)'), $lower)
                    ->pluck('userid')->unique()->map(fn ($v) => (int) $v)->all();
                if (empty($userIds)) {
                    return [];
                }
            }

            // The distance-preference filter needs each recipient's point, resolved
            // the same "mylocation else lastlocation" way the reach query resolves it.
            $latLng = [];
            foreach (DB::table('users as u')
                ->leftJoin('locations as l', 'l.id', '=', 'u.lastlocation')
                ->whereIn('u.id', $userIds)
                ->selectRaw("u.id AS id,
                    CASE WHEN JSON_EXTRACT(u.settings, '$.mylocation.lat') IS NOT NULL
                              AND JSON_EXTRACT(u.settings, '$.mylocation.lng') IS NOT NULL AND u.tnuserid IS NULL
                         THEN CAST(JSON_EXTRACT(u.settings, '$.mylocation.lat') AS DECIMAL(10,6))
                         ELSE l.lat END AS resolved_lat,
                    CASE WHEN JSON_EXTRACT(u.settings, '$.mylocation.lat') IS NOT NULL
                              AND JSON_EXTRACT(u.settings, '$.mylocation.lng') IS NOT NULL AND u.tnuserid IS NULL
                         THEN CAST(JSON_EXTRACT(u.settings, '$.mylocation.lng') AS DECIMAL(10,6))
                         ELSE l.lng END AS resolved_lng")
                ->get() as $row) {
                $latLng[(int) $row->id] = ($row->resolved_lat !== null && $row->resolved_lng !== null)
                    ? [(float) $row->resolved_lat, (float) $row->resolved_lng]
                    : null;
            }

            return $this->spoolPostToRecipients(
                $msg, $userIds, $latLng, $dryRun, writeReachLedger: false, matchReasons: $reasons
            );
        } catch (\Throwable $e) {
            Log::warning('firstreply: mailPostToUsers failed', ['msgid' => $msgid, 'error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Spool one post as an immediate digest to a resolved recipient list.
     *
     * Shared by the reach mailer and by first-reply scouting so the two cannot
     * drift on the things that decide whether a member should be mailed at all:
     * bouncing/absent preferred address, the browseMaxDistance slider, and the
     * poster's own exemption from their own post.
     *
     * Returns the ids actually spooled to, so a caller can act on exactly who was
     * mailed. Both public entry points count them; only first-reply scouting
     * needs the ids themselves.
     *
     * @param  int[]  $recipientIds
     * @param  array<int,array{0:float,1:float}|null>  $recipientLatLng
     * @return int[]
     */
    private function spoolPostToRecipients(
        Message $msg,
        array $recipientIds,
        array $recipientLatLng,
        bool $dryRun,
        bool $writeReachLedger = true,
        array $matchReasons = []
    ): array {
        $msgid = (int) $msg->id;

        // No group to fetch a sponsor for: reach mail is national, not per-community.
        $sponsorsCache = null;

        $users = User::whereIn('id', $recipientIds)->with(['emails'])->get();

        // Anyone who has already had an immediate mail about this ITEM - this message, or
        // another copy of the same thing - is done. The reach query filters those members out
        // before they reach here; first-reply scouting chooses its own recipients and does not,
        // so without this a scouted member can be mailed a copy of something they have had.
        $alreadyHadItem = DB::table('rippling_reach_notified')
            ->whereIn('msgid', $this->itemSiblingMsgids([$msgid])[$msgid] ?? [$msgid])
            ->whereIn('userid', $recipientIds)
            ->pluck('userid')->map(fn ($v) => (int) $v)->flip()->all();

        $mailed = [];
        foreach ($users as $user) {
            if (! $user->email_preferred) {
                continue;
            }
            if (isset($alreadyHadItem[(int) $user->id])) {
                continue;
            }
            // Provider is deferring us. Skipping before spool deliberately
            // leaves rippling_reach_notified unwritten, so if the provider
            // recovers while the post is still inside the reach window the
            // next tick picks this member up again by itself.
            if ($this->suppressions()->shouldSkip($user->email_preferred, (int) $user->id, 'digest_immediate')) {
                continue;
            }
            // Distance-preference filter (settings.browseMaxDistance). Deliberately
            // does NOT write rippling_reach_notified on a filtered-out skip (unlike
            // the "already sent" path below) - see the design doc's "Reach-mail
            // ledger semantics" edge case: leaving the ledger unwritten lets a later
            // tick re-consider this (post, user) pair if the member widens their
            // slider (or their location changes) while the post is still inside the
            // reach-mail recency window; once that window closes the post drops out
            // of sendReachDigests' candidate query regardless, so the cost is bounded.
            // Own posts always bypass (mirrors the cursor path's own-post exception).
            $isOwnPost = (int) $user->id === (int) $msg->fromuser;
            if (! $this->passesDistancePreference(
                $recipientLatLng[(int) $user->id] ?? null,
                $msg->lat,
                $msg->lng,
                $user,
                $isOwnPost,
                $this->authorMaxMiles((int) $msg->fromuser)
            )) {
                continue;
            }
            if ($dryRun) {
                $mailed[] = (int) $user->id;

                continue;
            }
            $deduped = collect([['message' => $msg]]);
            try {
                app(\App\Services\EmailSpoolerService::class)->spool(
                    new UnifiedDigest(
                        $user, $deduped, self::MODE_IMMEDIATE, $sponsorsCache,
                        matchReason: $matchReasons[(int) $user->id] ?? null
                    ),
                    $user->email_preferred,
                    emailType: 'digest_immediate',
                );
                if ($writeReachLedger) {
                    DB::table('rippling_reach_notified')->insertOrIgnore([
                        'msgid' => $msgid,
                        'userid' => (int) $user->id,
                        'notified_at' => now(),
                    ]);
                }
                $mailed[] = (int) $user->id;
            } catch (\Throwable $e) {
                Log::warning('ripple: failed to spool reach immediate mail', [
                    'msgid' => $msgid, 'user_id' => $user->id, 'error' => $e->getMessage(),
                ]);
            }
        }

        // #0 / §15 instrumentation: count immediate mails sent on expansion.
        if (! empty($mailed) && ! $dryRun) {
            $count = count($mailed);
            $today = now()->toDateString();

            $updated = DB::table('rippling_event_metrics')
                ->where('day', $today)
                ->where('event', 'immediate_mailed')
                ->increment('count', $count);

            if ($updated === 0) {
                try {
                    DB::table('rippling_event_metrics')->insert([
                        'day' => $today,
                        'event' => 'immediate_mailed',
                        'count' => $count,
                    ]);
                } catch (\Throwable) {
                    DB::table('rippling_event_metrics')
                        ->where('day', $today)
                        ->where('event', 'immediate_mailed')
                        ->increment('count', $count);
                }
            }
        }

        return $mailed;
    }

    /**
     * Parse the immediate-mode allowlist from config.
     *
     * Returns:
     *   ['*']   — wildcard (empty config OR explicit '*'), allow all users
     *   [...]   — list of email addresses, restrict to those
     *
     * The checked-in default is a single pilot email so production
     * deployments start restricted; clearing the env var or setting it to
     * '*' opens the floodgates for real.
     */
    protected function getImmediateAllowlist(): array
    {
        $raw = trim((string) config('freegle.digest.immediate_allowlist', ''));
        if ($raw === '' || $raw === '*') {
            return ['*'];
        }
        $parts = array_filter(array_map('trim', explode(',', $raw)), fn ($s) => $s !== '');
        // Mixed '*' + addresses → treat as wildcard.
        if (in_array('*', $parts, true)) {
            return ['*'];
        }

        return $parts === [] ? ['*'] : array_values($parts);
    }

    /**
     * Allowlist for the unified-digest DAILY mode.
     *
     * Unlike immediate (which defaults to '*' = everyone), daily defaults
     * to EMPTY = nobody. V1's bulk3 digest.php cron still owns daily, so the
     * new-format daily digest only goes to addresses an operator opts in via
     * FREEGLE_DIGEST_DAILY_ALLOWLIST — letting us pilot the new format to a
     * single recipient (in addition to V1's mail) before any cutover.
     *
     * @return array [] = send to nobody; ['*'] = everyone; otherwise the
     *                  list of opted-in email addresses (lower/exact as given).
     */
    protected function getDailyAllowlist(): array
    {
        $raw = trim((string) config('freegle.digest.daily_allowlist', ''));
        if ($raw === '') {
            return [];
        }
        $parts = array_filter(array_map('trim', explode(',', $raw)), fn ($s) => $s !== '');
        // Any '*' among the entries → treat as wildcard (everyone).
        if (in_array('*', $parts, true)) {
            return ['*'];
        }

        return array_values($parts);
    }

    /**
     * Constrain a query to the cadences a digest mode serves, against the single
     * site-wide users.emailfrequency column (no longer a per-group value).
     *
     * Immediate matches exactly emailfrequency = -1. Daily collapses EVERY
     * periodic cadence into one roll-up: any value > 0 (hourly=1, 2h, 4h,
     * 8h, daily=24). The per-group digest that used to service the
     * intermediate cadences (1/2/4/8h) has been removed, so without this fold
     * those members would have no sender and silently stop receiving mail.
     * emailfrequency = 0 (NEVER) is an opt-out and is excluded from both.
     *
     * @param  \Illuminate\Contracts\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $mode  One of MODE_IMMEDIATE or MODE_DAILY
     * @param  string  $column  Column to constrain (qualified when joining)
     */
    protected function applyDigestFrequency($query, string $mode, string $column = 'emailfrequency'): void
    {
        if ($mode === self::MODE_IMMEDIATE) {
            $query->where($column, -1);
        } else {
            // Any positive cadence — fold 1/2/4/8/24h all into daily.
            $query->where($column, '>', 0);
        }
    }

    /**
     * Get users who should receive digests based on mode.
     *
     * @param  string  $mode  One of MODE_IMMEDIATE or MODE_DAILY
     * @param  int|null  $userId  Specific user ID to process
     * @param  int  $shard  Shard index (0..shards-1) for parallel daily workers
     * @param  int  $shards  Total shard count; users partitioned by MOD(users.id, shards)
     */
    protected function getUsersForDigest(string $mode, ?int $userId = null, int $shard = 0, int $shards = 1): \Illuminate\Support\LazyCollection
    {
        // V1 parity (the legacy V1 PHP User::sendOurMails and
        // Engage::USER_INACTIVE = 365*12*3600 = 182.5 days): the canonical
        // "is this user reachable" gate excludes anyone inactive for half a
        // year, all Trash Nothing-imported users (handled separately by TN),
        // and any address known to be bouncing. V2 previously used 90 days
        // and didn't check tnuserid / bouncing, which (a) silently dropped
        // ~30k users V1 still emails and (b) silently emailed TN users and
        // bouncing addresses V1 explicitly skips — measured 2026-06-11
        // before this patch.
        $query = User::query()
            ->whereNull('deleted')
            ->whereNotNull('lastaccess')
            ->where('lastaccess', '>', now()->subSeconds(365 * 12 * 3600))
            ->whereNull('tnuserid')
            ->where('bouncing', 0);

        if ($userId) {
            $query->where('id', $userId);
        }

        // Daily-mode horizontal sharding: partition the userbase across
        // parallel workers by MOD(users.id, shards) so each user is owned by
        // exactly one shard (disjoint partitions, no inter-worker locking).
        // An explicit --user bypasses this. Immediate and reach mode shard by
        // post inside sendReachDigests instead, so don't double-shard here.
        if ($mode === self::MODE_DAILY && ! $userId && $shards > 1) {
            // Hash the id, don't MOD it directly. Under Galera/Percona the auto-increment
            // stride equals the cluster size (auto_increment_increment, 3 on a 3-node
            // cluster) and each node has a different offset, so users.id is NOT contiguous.
            // MOD(users.id, shards) then skews hard whenever shards shares a factor with the
            // cluster size (e.g. 3/6/9 → almost everyone lands on one shard). CRC32 gives a
            // uniform spread for ANY shard count and is immune to the stride / a cluster-size
            // change. Disjoint partitions still hold (each id maps to exactly one shard).
            $query->whereRaw('CRC32(users.id) % ? = ?', [$shards, $shard]);
        }

        // users.emailfrequency is the single, site-wide authoritative cadence field
        // (no longer per-group): -1 immediate, 0 never, >0 a periodic cadence
        // (hourly=1, 2h, 4h, 8h, daily=24). Daily mode collapses EVERY periodic
        // cadence into the one daily roll-up — see applyDigestFrequency(). With the
        // per-group digest removed, those intermediate cadences would otherwise have
        // no sender at all, so a user set to e.g. 4-hourly must still be picked up
        // here rather than silently dropped.
        $this->applyDigestFrequency($query, $mode, 'users.emailfrequency');
        $query->where(function ($q) {
            $q->whereRaw("JSON_EXTRACT(users.settings, '$.simplemail') IS NULL")
                ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(users.settings, '$.simplemail')) != ?", [
                    User::SIMPLE_MAIL_NONE,
                ]);
        });

        if ($mode === self::MODE_IMMEDIATE) {
            // Safety gate — FREEGLE_DIGEST_IMMEDIATE_ALLOWLIST. Default (or
            // '*') allows every eligible user; a comma-separated list
            // restricts to those addresses. The checked-in config default
            // pins this to a single pilot address so prod deploys start
            // restricted; ops clears the env var (or sets it to '*') to
            // flip immediate emails on for everyone.
            $allowlist = $this->getImmediateAllowlist();
            if ($allowlist !== ['*']) {
                $lowercased = array_map('strtolower', $allowlist);
                $query->whereExists(function ($q) use ($lowercased) {
                    $q->select(DB::raw(1))
                        ->from('users_emails')
                        ->whereColumn('users_emails.userid', 'users.id')
                        ->whereIn(DB::raw('LOWER(users_emails.email)'), $lowercased);
                });
                Log::info('UnifiedDigestService: immediate mode restricted to allowlist', [
                    'allowlist_count' => count($allowlist),
                ]);
            }
        } elseif ($mode === self::MODE_DAILY && ! $userId) {
            // Once-per-day guard. The daily digest sends incrementally off the
            // per-user users_digests cursor (everything since lastmsgid), so if
            // the command is invoked more than once in a day — a manual resume,
            // a staged rollout, an extra cron tick — each run sends a fresh
            // increment and the user gets several digests in one day (observed
            // 2026-06-11: ~11.7k users got 2-5 digests after repeated manual
            // runs). V1 relied purely on being cron'd once daily.
            //
            // Skip any user whose last daily digest already went out on the
            // current London day. A rolling 24h window was rejected: seeded by
            // off-schedule sends it makes the digest time drift permanently off
            // the 08:00 cron slot (a user sent at 16:00 today wouldn't clear a
            // 24h window by 08:00 tomorrow, so the 08:00 cron would skip them
            // and they'd creep later each day). Anchoring to the London
            // calendar day means tomorrow's 08:00 cron is a fresh day and
            // re-includes everyone, while a second run the same day is skipped
            // — once daily, at the scheduled time. Digests effectively never
            // send in the 00:00-08:00 window so the midnight boundary is moot.
            // An explicit --user (manual sampling/resend) bypasses this.
            //
            // The "already today" boundary is the UTC instant of London
            // midnight, computed in PHP (Carbon, DST-correct) rather than via
            // SQL CONVERT_TZ — the latter needs MySQL's named-timezone tables
            // loaded, which would silently fail open (return NULL) where they
            // aren't (e.g. the test DB). lastsent is stored UTC, so a plain
            // ">=" against this UTC bound is an index-friendly range scan.
            $londonDayStartUtc = \Carbon\Carbon::now('Europe/London')
                ->startOfDay()
                ->setTimezone('UTC')
                ->toDateTimeString();
            $query->whereNotExists(function ($q) use ($londonDayStartUtc) {
                $q->select(DB::raw(1))
                    ->from('users_digests')
                    ->whereColumn('users_digests.userid', 'users.id')
                    ->where('users_digests.mode', self::MODE_DAILY)
                    ->where('users_digests.lastsent', '>=', $londonDayStartUtc);
            });

            // Safety gate — FREEGLE_DIGEST_DAILY_ALLOWLIST. Daily unified
            // digests are OFF by default (empty list): V1's bulk3
            // digest.php cron still owns daily, so an unconfigured deploy
            // sends nothing here and can't double-mail the whole userbase.
            // A comma-separated address list pins the new-format daily
            // digest to those pilot users — sent IN ADDITION to V1's daily
            // digest, for a tracked side-by-side comparison. '*' opens it to
            // everyone for the eventual full cutover. An explicit --user
            // bypasses this gate entirely (manual sampling).
            $allowlist = $this->getDailyAllowlist();
            if ($allowlist === []) {
                $query->whereRaw('1 = 0');
                Log::info('UnifiedDigestService: daily mode disabled (empty FREEGLE_DIGEST_DAILY_ALLOWLIST); V1 cron owns daily');
            } elseif ($allowlist !== ['*']) {
                $lowercased = array_map('strtolower', $allowlist);
                $query->whereExists(function ($q) use ($lowercased) {
                    $q->select(DB::raw(1))
                        ->from('users_emails')
                        ->whereColumn('users_emails.userid', 'users.id')
                        ->whereIn(DB::raw('LOWER(users_emails.email)'), $lowercased);
                });
                Log::info('UnifiedDigestService: daily mode restricted to allowlist', [
                    'allowlist_count' => count($allowlist),
                ]);
            }
        }

        // Stream in keyset-paginated chunks with eager loads applied per chunk — these
        // are full User models with relations, so a single get() over the 90-day-active
        // userbase exhausts memory. The caller's take($limit) stays lazy.
        //
        // Daily: drain MOST-OVERDUE-FIRST (never-sent, then oldest lastsent) instead of the
        // default user-id order. When the send window can't clear the whole population in one
        // day (rippling ~tripled it), id-order permanently starves the same high-id tail — it
        // never gets a turn — while lower ids get re-sent. Overdue-first rotates the lag fairly
        // across everyone and self-corrects: as throughput rises (optimisation/hardware) the
        // window reaches further down the queue until it completes. See streamDailyOverdueFirst.
        if ($mode === self::MODE_DAILY && ! $userId) {
            return $this->streamDailyOverdueFirst($query, 500);
        }

        return $query->with(['emails'])->lazyById(500);
    }

    /**
     * Stream daily-digest recipients most-overdue-first: never-sent users (no daily
     * users_digests row, or NULL lastsent) first, then by lastsent ascending.
     *
     * Memory-safe (chunked eager loads, like lazyById) and revisit-safe: both phases advance a
     * strictly-forward keyset cursor, so a user whose lastsent we stamp to "today" mid-run — or a
     * no-post user whose lastsent we don't stamp (updateDigestTracker only stamps when posts were
     * sent) — is never re-fetched within the run. The once-per-London-day guard already in $query
     * excludes anyone sent today, so phase 2's "previously sent" means sent on a PRIOR day.
     */
    protected function streamDailyOverdueFirst(\Illuminate\Database\Eloquent\Builder $query, int $chunk): \Illuminate\Support\LazyCollection
    {
        $eager = ['emails'];
        $joinDaily = function ($j) {
            $j->on('ud_ord.userid', '=', 'users.id')->where('ud_ord.mode', '=', self::MODE_DAILY);
        };

        return \Illuminate\Support\LazyCollection::make(function () use ($query, $chunk, $eager, $joinDaily) {
            // Phase 1 — never sent (most overdue): id keyset so an un-stamped no-post user isn't revisited.
            $lastId = 0;
            while (true) {
                $rows = (clone $query)
                    ->leftJoin('users_digests as ud_ord', $joinDaily)
                    ->whereNull('ud_ord.lastsent')
                    ->where('users.id', '>', $lastId)
                    ->orderBy('users.id')
                    ->select('users.*')
                    ->with($eager)
                    ->limit($chunk)
                    ->get();
                if ($rows->isEmpty()) {
                    break;
                }
                foreach ($rows as $u) {
                    yield $u;
                }
                $lastId = (int) $rows->last()->id;
            }

            // Phase 2 — previously sent: composite (lastsent, id) keyset, oldest first.
            $curSent = null;
            $curId = 0;
            while (true) {
                $b = (clone $query)
                    ->leftJoin('users_digests as ud_ord', $joinDaily)
                    ->whereNotNull('ud_ord.lastsent');
                if ($curSent !== null) {
                    $b->where(function ($w) use ($curSent, $curId) {
                        $w->where('ud_ord.lastsent', '>', $curSent)
                            ->orWhere(function ($w2) use ($curSent, $curId) {
                                $w2->where('ud_ord.lastsent', '=', $curSent)->where('users.id', '>', $curId);
                            });
                    });
                }
                $rows = $b->orderBy('ud_ord.lastsent')
                    ->orderBy('users.id')
                    ->select('users.*')
                    ->addSelect('ud_ord.lastsent as _ord_lastsent')
                    ->with($eager)
                    ->limit($chunk)
                    ->get();
                if ($rows->isEmpty()) {
                    break;
                }
                foreach ($rows as $u) {
                    yield $u;
                }
                $last = $rows->last();
                $curSent = $last->_ord_lastsent;
                $curId = (int) $last->id;
            }
        });
    }

    /**
     * Send the daily roll-up to a specific user: every new post since their previous send,
     * bundled into one email.
     *
     * DAILY ONLY. sendDigests() routes both immediate and reach mode to sendReachDigests,
     * which mails one post at a time itself. $mode is still carried because the tracker row
     * and the mailable are keyed on it, not because this method branches on it.
     *
     * @return array{status: 'sent'|'no_posts'|'skipped'|'suppressed', count: int}
     */
    protected function sendDigestToUser(User $user, string $mode, bool $dryRun = false): array
    {
        if ($mode !== self::MODE_DAILY) {
            // A wiring mistake, not a mode to handle. Loud, because the quiet alternative is
            // mailing people the wrong shape of digest.
            throw new \InvalidArgumentException("sendDigestToUser is daily-only, got '{$mode}'");
        }

        $email = $user->email_preferred;

        if (! $email) {
            Log::debug("UnifiedDigestService: User {$user->id} has no email address");

            return ['status' => 'skipped', 'count' => 0];
        }

        // The member's provider is refusing our mail. Return BEFORE the
        // digest tracker is touched: leaving the watermark where it is means
        // that when the provider recovers, the next daily run spans the whole
        // gap and sends exactly one catch-up digest covering it, rather than
        // one stale digest per day missed. That is the entire catch-up
        // mechanism for digests - no replay queue needed.
        if ($this->suppressions()->shouldSkip($email, (int) $user->id, 'digest_'.$mode)) {
            return ['status' => 'suppressed', 'count' => 0];
        }

        // Get or create digest tracking record.
        $digestTracker = $this->getOrCreateDigestTracker($user, $mode);

        // One query for the whole window, carrying has_outcome / has_success
        // flags; partition here rather than re-querying.
        $allPosts = $this->getPostsForUser($user, $digestTracker, $mode);

        // Pinned posts (paid bulk-offer clearances) are force-included at the TOP of every
        // DAILY digest while they are still open, independent of the cursor window and the
        // per-member reach-gate, so they recur every day until the goods are gone. Fetched and
        // deduplicated separately, and never fed to the cursor (updateDigestTracker uses only
        // $allPosts), so a pinned post never suppresses itself on the next run.
        $pinnedCards = $this->deduplicatePosts($this->getPinnedOpenPostsForUser());

        if ($allPosts->isEmpty() && $pinnedCards->isEmpty()) {
            return ['status' => 'no_posts', 'count' => 0];
        }

        // available  = no outcome at all (the live posts)
        // completed  = a Taken/Received outcome (the "came and went" list, daily only)
        // withdrawn/expired (has_outcome && !has_success) appear in neither.
        $posts = $allPosts->filter(fn ($p) => ! $p->has_outcome)->values();

        // Order the live posts by the rippling digest-preview score (nearer +
        // newer + less-seen float up), matching the /rippling "Digest preview".
        // Dedup runs after, so the kept cross-post representative is the top-scoring one.
        $latlng = $this->resolveUserLatLng($user);
        $posts = $this->scoreAndSortAvailable($posts, $latlng);
        // Distance-preference filter (settings.browseMaxDistance) — a pure narrowing
        // step layered after scoring/sorting and before dedup, so the kept
        // cross-post representative (picked in deduplicatePosts below) is both the
        // top-scoring AND the in-range one. Deliberately independent of
        // scoreAndSortAvailable's internal $post->_dist (which is only set when that
        // method doesn't early-return) — see DistancePreferenceFilter and the design
        // doc's "Insertion points" section.
        $posts = $this->filterByDistancePreference($posts, $user, $latlng);

        // Carried-over posts sink BELOW the new ones, whatever they score. They are older
        // than the cursor by construction, so letting the score interleave them gives the
        // member a digest whose dates jump around - which is what the roll-up is meant to
        // stop. They take the room DIGEST_POST_CAP leaves once the new posts are in, and
        // age out of the carryover when it never comes (see carryoverFrom).
        $posts = $this->newPostsFirst($posts, $digestTracker);

        $completedPosts = $this->deduplicateCompletedPosts(
            $this->filterByDistancePreference(
                $allPosts->filter(fn ($p) => $p->has_success)->values(),
                $user,
                $latlng
            )
        );

        if ($posts->isEmpty() && $pinnedCards->isEmpty()) {
            // No live posts to send. Still advance the cursor past everything
            // examined (incl. completed/withdrawn) so they don't re-surface,
            // and don't send a completed-only digest. Anything carried over was
            // examined too and did not make it, so it is not carried again.
            if (! $dryRun) {
                $this->updateDigestTracker($digestTracker, $allPosts, false, []);
            }

            return ['status' => 'no_posts', 'count' => 0];
        }

        // Deduplicate cross-posted items.
        $deduplicatedPosts = $this->deduplicatePosts($posts);

        // ...and drop the ones whose item went out in an earlier digest. deduplicatePosts
        // collapses the copies that land in ONE digest; this is the same decision across
        // digests, which is how one item reached members on four days running (Discourse 9808).
        $deduplicatedPosts = $this->dropCardsAlreadyCovered(
            $deduplicatedPosts,
            $digestTracker->lastmsgdate,
            $this->digestGroupIdsForUser($user, $mode)
        );

        if ($deduplicatedPosts->isEmpty() && $pinnedCards->isEmpty()) {
            // Nothing to send, but still advance the tracker past these posts
            // so the next tick doesn't re-fetch and re-filter the same set.
            if (! $dryRun) {
                $this->updateDigestTracker($digestTracker, $allPosts, false, []);
            }

            return ['status' => 'no_posts', 'count' => 0];
        }

        // No sponsors are attached to a digest: sponsorship was scoped per group, and
        // there is no site-wide replacement now the site is national.
        $sponsors = collect();

        // Put the pinned posts (paid bulk-offer clearances) at the very TOP of the daily
        // digest, dropping any that also appear in the normal window set so they are not
        // shown twice. Pinned posts are always shown while open, regardless of the cursor.
        if ($pinnedCards->isNotEmpty()) {
            $pinnedIds = $pinnedCards->pluck('message.id')->all();
            $deduplicatedPosts = $pinnedCards->concat(
                $deduplicatedPosts->reject(
                    fn ($c) => in_array($c['message']->id, $pinnedIds, true)
                )
            )->values();
        }

        // Daily mode: one rolled-up digest. $completedPosts (the "came and
        // went" Taken/Received set) was partitioned from the same query above.
        if (! $dryRun) {
            $digest = new UnifiedDigest($user, $deduplicatedPosts, $mode, $sponsors, $completedPosts);
            app(\App\Services\EmailSpoolerService::class)->spool($digest, emailType: 'digest_daily');
            // Advance the cursor past everything examined this window (live,
            // completed and withdrawn) so nothing re-surfaces tomorrow. Pass
            // emailWasSent=true so lastsent is stamped even when $allPosts is empty
            // (a pinned-only digest still sent an email) — see updateDigestTracker.
            // The posts the email left out at the cap are carried to the next run.
            $this->updateDigestTracker(
                $digestTracker,
                $allPosts,
                true,
                $this->carryoverFrom($digest->droppedPostIds(), $deduplicatedPosts)
            );
        }

        return ['status' => 'sent', 'count' => 1];
    }

    /**
     * Get or create a digest tracking record for a user.
     *
     * A fresh tracker keeps the null sentinel, which getPostsForUser reads as "the last 24
     * hours", which is what a daily digest member expects on their first send.
     */
    protected function getOrCreateDigestTracker(User $user, string $mode): UserDigest
    {
        return UserDigest::firstOrCreate(
            [
                'userid' => $user->id,
                'mode' => $mode,
            ],
            [
                'lastmsgid' => null,
                'lastmsgdate' => null,
            ]
        );
    }

    /**
     * Column-constrained eager-load spec for digest posts, shared by every digest
     * query so they load the same lean set.
     *
     * - attachments: the primary-photo pointer columns plus externalmods. The email
     *   digest shows one photo per post (getPrimaryAttachment) and ignores externalmods,
     *   but the daily-posts PUSH shares this eager-load and its collage prefers a real
     *   photo over an AI illustration (PushNotificationService::attachmentIsAi reads
     *   attachments.externalmods) - without the column every photo is silently treated
     *   as real. The heavy `data` blob stays excluded (that was the real dead weight).
     *   msgid is required for the hasMany to match rows to their message.
     */
    private function digestPostEagerLoads(): array
    {
        return [
            'attachments' => fn ($q) => $q->select('id', 'msgid', 'primary', 'externaluid', 'externalurl', 'archived', 'externalmods'),
            'fromUser',
        ];
    }

    /**
     * The posts a ring admits this member to, as an SQL exclusion for the reach gate.
     *
     * The reach gate rejects a post when a reach row says the member is outside it.
     * A ring exists precisely to admit people the capped reach did not cover, so a
     * post a ring admits must not be rejected: the fragment narrows the reject to
     * posts NOT on that list.
     *
     * The list comes from RingIndex - the same call, and so the same answer, that
     * the website's feed, badge and search get for this member. Asking differently
     * here is how the digest came to name posts the site would not show.
     *
     * Fails closed, because RingIndex does: no rings means no rescue, which shows
     * the committed reach only rather than mailing a post nobody can open.
     *
     * @param  array  $latlng  [lat, lng] - the member's location.
     * @return array{0: string, 1: array} SQL fragment (may be empty) and its bindings.
     */
    private function ringRescueIds(User $user, array $latlng): array
    {
        $none = ['', []];

        $settings = $user->settings;
        if (is_string($settings)) {
            $settings = json_decode($settings, true) ?: [];
        }
        $band = is_array($settings) ? ($settings['browseDensityBand'] ?? null) : null;

        $lanes = RingIndex::lanesFor(is_string($band) ? $band : null);
        if ($lanes === []) {
            return $none;
        }

        $ids = RingIndex::admittedFor($latlng[0], $latlng[1], $lanes);
        if ($ids === []) {
            return $none;
        }

        return [
            ' AND rr.msgid NOT IN ('.implode(',', array_fill(0, count($ids), '?')).')',
            $ids,
        ];
    }

    /**
     * Docblock for the daily digest / daily-posts push reach gate below.
     *
     * A member whose own rings admit a post must not be told they have not been reached
     * by it, exactly as on browse, in search and at the reply gate. Which posts those
     * are is asked of the spatial index once for this member (ringRescueIds ->
     * RingIndex::admittedFor) and spliced in as a list of ids; the ring geometry is not
     * tested here, or anywhere else in this codebase, because one question with two
     * implementations is what put members in the position of being emailed posts the
     * site refused them.
     */
    public function getPostsForUser(User $user, UserDigest $tracker, string $mode): Collection
    {
        // Two outcome flags come back with the posts so the caller can partition in
        // PHP rather than going back to the database:
        //   has_outcome   — any outcome row exists (Taken/Received/Withdrawn/…)
        //   has_success   — a Taken/Received outcome exists
        // From these: available = !has_outcome; "came and went" = has_success;
        // withdrawn/expired (has_outcome && !has_success) are dropped by the
        // caller. V1 parity (Digest.php:218 only lists count(outcomes)==0 as
        // available); matches the platform browse/map dropping any outcome.
        $successList = "'".Message::OUTCOME_TAKEN."','".Message::OUTCOME_RECEIVED."'";

        // Built fresh per arm. The window and the carryover are two queries, not one, and
        // each needs its own builder - see the comment on the two arms below.
        $baseQuery = fn () => Message::select('messages.*')
            ->selectRaw('EXISTS(SELECT 1 FROM messages_outcomes mo WHERE mo.msgid = messages.id) AS has_outcome')
            ->selectRaw("EXISTS(SELECT 1 FROM messages_outcomes mo WHERE mo.msgid = messages.id AND mo.outcome IN ($successList)) AS has_success")
            // Engagement signal for the rippling 'budget' (underexposure) score term;
            // mirrors iznik-routing-go/digest_simulator.go (views = SUM of 'View'
            // like counts; replies = approved 'Interested' chat replies).
            ->selectRaw("(SELECT COALESCE(SUM(ml.count),0) FROM messages_likes ml WHERE ml.msgid = messages.id AND ml.type = 'View') AS views")
            ->selectRaw("(SELECT COUNT(*) FROM chat_messages cm WHERE cm.refmsgid = messages.id AND cm.type = 'Interested' AND cm.reviewrejected = 0 AND cm.reviewrequired = 0) AS replies")
            // Has THIS recipient already had a chance to see the post? A messages_likes
            // 'View' row means they viewed it in-app OR opened/clicked a digest that
            // contained it (mail:digest:mark-seen writes the latter). Used to sink
            // already-seen posts in the daily order, mirroring the browse feed's
            // unseen-first sort which reads the same signal.
            ->selectRaw('EXISTS(SELECT 1 FROM messages_likes mlseen WHERE mlseen.msgid = messages.id AND mlseen.userid = ? AND mlseen.type = ?) AS seen_by_user', [$user->id, 'View'])
            ->where('messages.collection', Message::COLLECTION_APPROVED)
            ->whereNull('messages.deleted')
            ->whereIn('messages.type', [Message::TYPE_OFFER, Message::TYPE_WANTED])
            ->orderBy('messages.arrival', 'asc');

        // The exclusions both arms share. Resolved ONCE - the reach gate calls the spatial
        // index and the routing labels, and asking them twice would be both slower and
        // capable of giving the two arms different answers mid-run.
        //
        // Reach-gate rippling posts for the daily digest and the daily-posts push (both
        // call this) just like the immediate path: a post with a rippling_reach row is only
        // included once its reach covers this member (nearest-first), so daily/push members
        // are not notified of posts they cannot yet reply to. Posts with no reach row are
        // unaffected. Skipped entirely when we can't resolve the member's location (fail
        // open — no regression for locationless members).
        // A frozen reach (status 'held') means the post's origin copy has been pulled back for
        // moderation. Browse, the badge and search hide it outright, so the daily digest and
        // the daily-posts push (which share this query) must not carry it either.
        //
        // Written as its own exclusion rather than folded into the reach gate below: that gate
        // is a NOT EXISTS over reach rows which do NOT contain the member, so adding
        // "status <> 'held'" inside it would EXCLUDE the frozen row from the rejection set and
        // let the post through - the exact opposite of the intent.
        $exclusions = [[
            "NOT EXISTS (SELECT 1 FROM rippling_reach rrh
                WHERE rrh.msgid = messages.id AND rrh.status = 'held')",
            [],
        ]];

        $latlng = $this->resolveUserLatLng($user);
        if ($latlng !== null) {
            // The member's whole containment universe comes from the spatial
            // index as an id list (the same authority, and the same call
            // shape, the feed and badge use) and the gate is a pure id
            // comparison - no geometry in this query at all. Failure fails
            // CLOSED, like RingIndex::admits: a spatial outage holds
            // reach-gated posts for a later digest rather than mailing what
            // nobody could check.
            [$ringRescue, $ringParams] = $this->ringRescueIds($user, $latlng);
            $containing = app(\App\Services\Ripple\CellSetService::class)
                ->reachContaining($latlng[0], $latlng[1]) ?? [];
            // Stored labels are the deciding record wherever they exist: drop
            // any grid-admitted post whose label says this member is NOT
            // reachable by road at the post's current budget, and ADD any
            // labelled post the grid prefilter missed whose label admits the
            // member (discover) - the same narrowing-plus-union, from the same
            // one-call authority, the browse feed applies, so the digest can
            // never mail what browse hides nor hide what browse shows.
            // Posts without labels, and everything when routing is
            // unavailable, keep the grid verdict.
            $eval = app(\App\Services\Ripple\ReachService::class)
                ->labelVerdictsWithDiscover((float) $latlng[0], (float) $latlng[1], $containing);
            if ($containing !== [] && $eval['verdicts'] !== []) {
                $containing = array_values(array_filter(
                    $containing,
                    fn ($id) => ($eval['verdicts'][(int) $id] ?? '') !== 'out'
                ));
            }
            foreach ($eval['discovered'] as $id) {
                $containing[] = $id;
            }
            $inSql = '';
            $inParams = [];
            if ($containing !== []) {
                $inSql = ' AND rr.msgid NOT IN ('.implode(',', array_fill(0, count($containing), '?')).')';
                $inParams = $containing;
            }
            // Exclude a post when a reach row exists, the member is not in
            // its containment list, and no ring rescues them.
            // keep-raw: correlated NOT EXISTS with a spliced ringRescue fragment
            // (ringRescueIds returns SQL text) - the builder cannot compose
            // another service's fragment.
            $exclusions[] = [
                "NOT EXISTS (SELECT 1 FROM rippling_reach rr
                    WHERE rr.msgid = messages.id$inSql$ringRescue)",
                array_merge($inParams, $ringParams),
            ];
        }

        $armFor = function () use ($baseQuery, $exclusions) {
            $q = $baseQuery();
            foreach ($exclusions as [$sql, $params]) {
                $q->whereRaw($sql, $params);
            }

            return $q;
        };

        // TWO ARMS, DELIBERATELY TWO QUERIES.
        //
        // The window is everything since the cursor: a range on messages.arrival, filtered
        // by collection and type. The carryover is the posts the member's last digest had to
        // leave out at the post cap, which are older than the cursor by construction: a
        // primary-key lookup of at most DIGEST_POST_CAP ids.
        //
        // messages carries single-column indexes on collection and on arrival, not a
        // composite of the two. ORing the carryover's id predicate into the window's arrival
        // range gives MySQL no merge-friendly plan across the two single-column indexes, and
        // with ORDER BY arrival ASC LIMIT it falls back to walking the arrival index from the
        // oldest of 11M rows: measured 60-74s combined against 0.29s split across two queries,
        // same member and same moment.
        //
        // Split, each arm gets the plan it should have: the window is a range on the arrival
        // index filtered by collection/type, and the carryover is a primary-key lookup of at
        // most DIGEST_POST_CAP ids.
        $window = $armFor();
        if ($tracker->lastmsgdate) {
            $window->where('messages.arrival', '>', $tracker->lastmsgdate);
        } else {
            $window->where('messages.arrival', '>=', now()->subDay());
        }

        // Bound the load (see DIGEST_LOAD_CAP): oldest-first + this limit means a member who
        // is far behind drains their backlog in DIGEST_LOAD_CAP-sized batches across successive
        // runs (updateDigestTracker advances the cursor past exactly what is returned here)
        // instead of loading days of posts at once and exhausting memory. Normal daily volume
        // is well under the cap, so steady-state digests are unchanged.
        $posts = $window->limit(self::DIGEST_LOAD_CAP)->get();

        // Loading the carried posts is NOT showing them first: newPostsFirst() sinks them below
        // the new posts before the cap is applied, so they only take the room the cap leaves.
        // It does mean each carried id costs a DIGEST_LOAD_CAP slot a new post could have had,
        // which is why carryoverFrom() bounds the list by age, seen-ness and size.
        $carryover = array_values(array_filter(array_map('intval', $tracker->carryover ?? [])));
        if ($carryover !== []) {
            $posts = $armFor()
                ->whereIn('messages.id', $carryover)
                ->limit(self::DIGEST_LOAD_CAP)
                ->get()
                ->concat($posts);
        }

        // De-duplicate on id. A carried post whose arrival is also inside the window
        // satisfies both arms and comes back twice; the site is national, so a message has
        // exactly one row and there is no per-copy id to distinguish.
        return $posts
            ->unique('id')
            ->sortBy('arrival')
            ->take(self::DIGEST_LOAD_CAP)
            ->values()
            ->load($this->digestPostEagerLoads());
    }

    /**
     * Open pinned posts (paid bulk-offer clearances), force-included at the TOP of every
     * member's daily digest.
     *
     * "Open" mirrors getPostsForUser: Approved, not deleted, an Offer/Wanted, and with NO
     * outcome (Taken/Received/Withdrawn/Expired). Deliberately NOT window-limited and NOT
     * reach-gated, so a pinned post recurs in every daily digest, for every member, until it
     * closes.
     *
     * @return Collection of Message (each with has_outcome/has_success=0)
     */
    private function getPinnedOpenPostsForUser(): Collection
    {
        return Message::select('messages.*')
            // Live posts only: no outcome (so has_outcome/has_success are constant 0 — the caller
            // treats these as available, matching the flags getPostsForUser computes).
            ->selectRaw('0 AS has_outcome')
            ->selectRaw('0 AS has_success')
            ->selectRaw("(SELECT COALESCE(SUM(ml.count),0) FROM messages_likes ml WHERE ml.msgid = messages.id AND ml.type = 'View') AS views")
            ->selectRaw("(SELECT COUNT(*) FROM chat_messages cm WHERE cm.refmsgid = messages.id AND cm.type = 'Interested' AND cm.reviewrejected = 0 AND cm.reviewrequired = 0) AS replies")
            ->join('messages_pinned', 'messages_pinned.msgid', '=', 'messages.id')
            ->where('messages.collection', Message::COLLECTION_APPROVED)
            ->whereNull('messages.deleted')
            ->whereIn('messages.type', [Message::TYPE_OFFER, Message::TYPE_WANTED])
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('messages_outcomes')
                    ->whereColumn('messages_outcomes.msgid', 'messages.id');
            })
            ->orderBy('messages.arrival', 'desc')
            ->with($this->digestPostEagerLoads())
            ->get();
    }

    /**
     * Resolve a member's point as settings.mylocation (both coords) else their lastlocation —
     * the same order the immediate-mail recipient query uses, so the digest, the push and the
     * immediate path all agree on where a member is. Returns [lat, lng] or null if unknown.
     * A TN member's mylocation is ignored (User::chosenLatLng).
     *
     * @return array{0:float,1:float}|null
     */
    private function resolveUserLatLng(User $user): ?array
    {
        $chosen = User::chosenLatLng($user->settings, $user->tnuserid);
        if ($chosen) {
            return $chosen;
        }

        if ($user->lastlocation) {
            $loc = DB::table('locations')->where('id', $user->lastlocation)->first(['lat', 'lng']);
            if ($loc && $loc->lat !== null && $loc->lng !== null) {
                return [(float) $loc->lat, (float) $loc->lng];
            }
        }

        return null;
    }

    /**
     * Whether a candidate post passes the recipient's distance preference
     * (settings.browseMaxDistance) — the single choke point all three
     * member-notification pipelines call (daily digest, immediate cursor,
     * reach-mail). True = keep (spool/include this post for this recipient);
     * false = filter out. See DistancePreferenceFilter and the design doc
     * docs/superpowers/specs/2026-07-01-distance-preference-email-filtering-design.md.
     *
     * Fail-open (returns true, i.e. no filtering) when: the feature's
     * kill-switch is off (freegle.ripple.distance_filter.enabled), the
     * recipient's or post's location can't be resolved, the post is the
     * recipient's own, or the recipient's setting is absent/sentinel
     * (the overwhelming majority — checked via maxDistanceMiles() before any
     * haversine is computed, so that fast path costs nothing extra).
     *
     * @param  array{0:float,1:float}|null  $recipientLatLng  Recipient's resolved point.
     * @param  mixed  $lat  Post/message latitude (numeric or null).
     * @param  mixed  $lng  Post/message longitude (numeric or null).
     */
    /**
     * Narrow a post collection to the ones inside the member's distance preference.
     *
     * Public and shared because the daily EMAIL digest and the daily-posts PUSH must answer
     * "is this near enough for this member" identically. They previously did not: the email
     * applied this filter and the push, which calls getPostsForUser directly, did not - so a
     * member's own distance setting hid a post from their inbox while it still arrived on
     * their phone.
     *
     * $latlng may be passed when the caller has already resolved it (the digest does, for
     * scoring), otherwise it is resolved here.
     *
     * @param  \Illuminate\Support\Collection  $posts
     * @return \Illuminate\Support\Collection
     */
    public function filterByDistancePreference($posts, User $user, ?array $latlng = null)
    {
        $latlng ??= $this->resolveUserLatLng($user);

        // With a drive-minutes budget in play, resolve every candidate's drive
        // time in ONE routing call up front — the per-post gate then reads the
        // answers from the service's memo instead of paying an HTTP round trip
        // per post. Only worth doing when the budget can actually apply (a
        // limited miles slider AND a minutes budget, same as the gate).
        $filter = app(DistancePreferenceFilter::class);
        if ($latlng !== null
            && config('freegle.ripple.distance_filter.enabled', true)
            && $filter->maxDistanceMiles($user) < DistancePreferenceFilter::DISTANCE_UNLIMITED
            && $filter->maxMinutes($user) > 0) {
            $targets = [];
            foreach ($posts as $p) {
                if ($p->lat !== null && $p->lng !== null) {
                    $targets[] = [(float) $p->lat, (float) $p->lng];
                }
            }
            if ($targets !== []) {
                $this->driveMinutes()->prefetch($latlng[0], $latlng[1], $targets);
            }
        }

        return $posts->filter(fn ($p) => $this->passesDistancePreference(
            $latlng,
            $p->lat,
            $p->lng,
            $user,
            (int) $p->fromuser === (int) $user->id,
            $this->authorMaxMiles((int) $p->fromuser)
        ))->values();
    }

    private function passesDistancePreference(?array $recipientLatLng, $lat, $lng, User $user, bool $isOwnPost, ?float $authorMaxMiles = null): bool
    {
        if ($isOwnPost) {
            return true;
        }

        if (! config('freegle.ripple.distance_filter.enabled', true)) {
            return true;
        }

        if ($recipientLatLng === null || $lat === null || $lng === null) {
            // Fail open: matches the existing reach-gate/scorer precedent of
            // "skip when we can't resolve — no regression for locationless members".
            return true;
        }

        $filter = app(DistancePreferenceFilter::class);
        // INBOUND cap: the recipient only wants posts within their chosen distance —
        // measured in drive MINUTES when they have a budget and the routing engine
        // answers, crow miles otherwise, exactly the site's rule (roadMinuteVerdict /
        // countWithinBudget), so a member's email and browse page can never disagree
        // about the same post.
        // OUTBOUND cap: the post author only wants their post shown to people within
        // their chosen distance of it (the same setting, read from the author). Still
        // crow miles, deliberately: apiv2's AuthorReachCapWhere is crow-miles SQL, and
        // the two outbound surfaces must move together or not at all.
        $recipientMax = $filter->maxDistanceMiles($user);
        $authorMax = $authorMaxMiles ?? (float) DistancePreferenceFilter::DISTANCE_UNLIMITED;
        if ($recipientMax >= DistancePreferenceFilter::DISTANCE_UNLIMITED
            && $authorMax >= DistancePreferenceFilter::DISTANCE_UNLIMITED) {
            // Fast path: neither side limits distance, the majority case. No haversine needed.
            return true;
        }

        $distanceMiles = $filter->distanceMiles(
            $recipientLatLng[0],
            $recipientLatLng[1],
            (float) $lat,
            (float) $lng
        );

        $driveMinutes = null;
        $recipientMaxMinutes = 0.0;
        if ($recipientMax < DistancePreferenceFilter::DISTANCE_UNLIMITED) {
            $recipientMaxMinutes = $filter->maxMinutes($user);
            if ($recipientMaxMinutes > 0) {
                // Memo-served after filterByDistancePreference's prefetch; the
                // immediate pipelines (one post at a time) pay one single-target
                // call per new (recipient, post) pair. Null on any failure —
                // the crow rule below takes over, which is the old behaviour.
                $driveMinutes = $this->driveMinutes()->minutesBetween(
                    $recipientLatLng[0],
                    $recipientLatLng[1],
                    (float) $lat,
                    (float) $lng
                );
            }
        }

        return $filter->passesInbound($distanceMiles, $driveMinutes, $recipientMax, $recipientMaxMinutes, false)
            && $filter->passes($distanceMiles, $authorMax, false);
    }

    /**
     * The per-run drive-minutes client. A property, not app(), so its memo
     * spans every (recipient, post) pair of the run.
     */
    private ?\App\Services\Ripple\DriveMinutesService $driveMinutesService = null;

    private function driveMinutes(): \App\Services\Ripple\DriveMinutesService
    {
        return $this->driveMinutesService ??= new \App\Services\Ripple\DriveMinutesService;
    }

    /**
     * The post author's OUTBOUND distance cap in miles (settings.browseMaxDistance),
     * memoised per author id so repeated posts by the same freegler — across
     * many recipients within a run — cost a single lookup. Absent author or
     * absent/sentinel setting resolves to DISTANCE_UNLIMITED (no outbound cap).
     *
     * Uses authorMaxDistanceMiles, NOT maxDistanceMiles: the latter falls back to the
     * member's density band default, which describes how far THEY would travel to collect
     * and must never become a cap on how far their own posts may go.
     */
    private array $authorMaxMilesCache = [];

    private function authorMaxMiles(int $fromuser): float
    {
        if (! array_key_exists($fromuser, $this->authorMaxMilesCache)) {
            $author = User::select('id', 'settings')->find($fromuser);
            $this->authorMaxMilesCache[$fromuser] = $author
                ? app(DistancePreferenceFilter::class)->authorMaxDistanceMiles($author)
                : (float) DistancePreferenceFilter::DISTANCE_UNLIMITED;
        }

        return $this->authorMaxMilesCache[$fromuser];
    }

    /**
     * The post's reach extent in metres: the greatest great-circle distance from
     * the post origin (rippling_reach.lat/lng) to any vertex of its reach polygon.
     * Used as the closeness denominator in the digest score.
     *
     * rippling_reach.polygon stores lng/lat DEGREES (tagged SRID 3857 by Freegle
     * convention — the coordinates are WGS84 degrees, not projected metres), so we
     * parse the WKT ring and measure each vertex against the origin with haversine
     * to get true metres. The recipient->post distance (see scoreAndSortAvailable)
     * is measured the same way, so close = 1 - dist/reach is a consistent
     * true-metre ratio and the configured default (~30km) is meaningful.
     *
     * Posts with no rippling_reach row (rippling dark, or backlog posts arriving
     * before the go-live cutoff) fall back to the configured default. Cached per
     * run because many recipients share the same posts.
     */
    private function reachRadiusMetres(int $msgid): float
    {
        if (array_key_exists($msgid, $this->reachRadiusCache)) {
            return $this->reachRadiusCache[$msgid];
        }

        $default = (float) config('freegle.ripple.score.default_reach_metres', 30000);

        $row = DB::selectOne(
            'SELECT rr.lng AS ox, rr.lat AS oy,
                    ST_AsText(ST_Envelope(rr.outer_bound)) AS outer_env
               FROM rippling_reach rr WHERE rr.msgid = ?',
            [$msgid]
        );

        return $this->reachRadiusCache[$msgid] = $this->reachRadiusFromRow($row, $default);
    }

    /**
     * One row's reach radius: the OUTER BOUND envelope's furthest corner
     * from the origin - the column every writer keeps refreshed - else the
     * configured default.
     */
    private function reachRadiusFromRow(?object $row, float $default): float
    {
        if (! $row) {
            return $default;
        }
        if (! empty($row->outer_env) && preg_match_all('/(-?\d+\.?\d*) (-?\d+\.?\d*)/', (string) $row->outer_env, $m, PREG_SET_ORDER)) {
            $best = 0.0;
            foreach ($m as $pt) {
                $d = $this->haversineMetres((float) $row->oy, (float) $row->ox, (float) $pt[2], (float) $pt[1]);
                if ($d > $best) {
                    $best = $d;
                }
            }
            if ($best > 0) {
                return $best;
            }
        }

        return $default;
    }

    /**
     * Prime {@see $reachRadiusCache} for a whole batch of posts in a SINGLE query.
     *
     * scoreAndSortAvailable() scores every candidate post for a recipient, and each
     * post needs its reach radius. Fetching them one msgid at a time (the fallback in
     * reachRadiusMetres) is one remote-DB round-trip per post — ~100+ round-trips for
     * one recipient, and the daily digest is DB-round-trip-bound, so that dominated
     * throughput. This collapses the uncached msgids into one IN(...) lookup. msgids
     * with no rippling_reach row are cached to the default so they are never re-queried.
     */
    private function primeReachRadiusCache(Collection $posts): void
    {
        $default = (float) config('freegle.ripple.score.default_reach_metres', 30000);

        $ids = [];
        foreach ($posts as $post) {
            $mid = (int) $post->id;
            if (! array_key_exists($mid, $this->reachRadiusCache)) {
                $ids[$mid] = true;
            }
        }
        if (empty($ids)) {
            return;
        }
        $ids = array_keys($ids);

        foreach (array_chunk($ids, 500) as $chunk) {
            $rows = DB::table('rippling_reach')
                ->select('msgid', 'lng as ox', 'lat as oy')
                ->addSelect(DB::raw('ST_AsText(ST_Envelope(outer_bound)) AS outer_env'))
                ->whereIn('msgid', $chunk)
                ->get()
                ->all();
            foreach ($rows as $row) {
                $this->reachRadiusCache[(int) $row->msgid] = $this->reachRadiusFromRow($row, $default);
            }
        }

        // Any requested msgid with no rippling_reach row (rippling dark, or backlog
        // posts before go-live): cache the default so it isn't re-queried per recipient.
        foreach ($ids as $mid) {
            if (! array_key_exists($mid, $this->reachRadiusCache)) {
                $this->reachRadiusCache[$mid] = $default;
            }
        }
    }

    /**
     * Score the available (live) posts with the rippling digest-preview algorithm
     * and return them ordered by score descending. See DigestPostScorer for the
     * formula and the haversine/drive-time performance approximation.
     *
     * When the recipient's location is unknown we cannot compute closeness, so we
     * leave the posts in their incoming (arrival) order — fail open, no regression.
     */
    private function scoreAndSortAvailable(Collection $posts, ?array $latlng): Collection
    {
        if ($latlng === null || $posts->count() < 2) {
            return $posts->values();
        }

        $scorer = app(DigestPostScorer::class);
        $weights = (array) config('freegle.ripple.score.weights');
        $env = [
            'window_hours' => (float) config('freegle.ripple.score.window_hours', 24),
            'budget_decay' => (float) config('freegle.ripple.score.budget_decay', 25),
            // The reference close term's horizon - the same tuned knob the
            // /rippling digest preview defaults to (RIPPLE_MAX_MINUTES).
            'max_minutes' => (float) config('freegle.ripple.score.max_minutes', config('freegle.ripple.max_minutes', 30)),
        ];

        // Load every candidate post's reach radius in ONE query rather than one
        // round-trip per post (the daily digest is DB-round-trip-bound).
        $this->primeReachRadiusCache($posts);

        // Drive minutes for every candidate in ONE routing call. Scoring runs
        // before the distance filter, so this prefetch also warms the memo the
        // filter reads - the run pays one call per recipient for both.
        $targets = [];
        foreach ($posts as $p) {
            if ($p->lat !== null && $p->lng !== null) {
                $targets[] = [(float) $p->lat, (float) $p->lng];
            }
        }
        if ($targets !== []) {
            $this->driveMinutes()->prefetch($latlng[0], $latlng[1], $targets);
        }

        $now = now();
        foreach ($posts as $post) {
            $reach = $this->reachRadiusMetres((int) $post->id);
            // Post origin: messages.lat/lng (already on the row via messages.* select).
            // Great-circle metres recipient -> post, the same unit as the reach radius.
            $dist = $this->haversineMetres(
                $latlng[0],
                $latlng[1],
                (float) $post->lat,
                (float) $post->lng
            );
            $arrival = $post->arrival instanceof \DateTimeInterface
                ? $post->arrival
                : \Illuminate\Support\Carbon::parse($post->arrival);
            $ageH = max(0.0, $now->floatDiffInHours($arrival));
            // Memo hit from the prefetch above; null (crow fallback in the
            // scorer) when the engine had no answer for this post.
            $driveMinutes = ($post->lat !== null && $post->lng !== null)
                ? $this->driveMinutes()->minutesBetween($latlng[0], $latlng[1], (float) $post->lat, (float) $post->lng)
                : null;
            $s = $scorer->score(
                $dist,
                $reach,
                $ageH,
                (int) ($post->views ?? 0),
                (int) ($post->replies ?? 0),
                false, // anchor/home-area not yet implemented; see /rippling (digest_simulator.go homeGroups). Default weight 0.
                $weights,
                $env,
                $driveMinutes
            );
            // Sink posts the recipient has already had a chance to see (in-app view
            // or an opened/clicked digest) so the digest leads with fresh posts.
            $score = (float) $s['total'];
            if (! empty($post->seen_by_user)) {
                $score *= (float) config('freegle.digest.seen_penalty', 0.15);
            }
            $post->_score = $score;
            $post->_dist = $dist;
        }

        // Pin the two posts nearest the recipient to the top, then the rest by score.
        // Reduces "I keep seeing posts far away" complaints while keeping the scored
        // order for everything below the top two.
        return $this->pinClosestTwo($posts->sortByDesc('_score')->values());
    }

    /**
     * Move the two nearest posts (smallest recipient->post distance) to the front,
     * nearest first, preserving the scored order of the rest. Each post must carry
     * the _dist set in scoreAndSortAvailable. No-op for two or fewer posts.
     */
    private function pinClosestTwo(Collection $sorted): Collection
    {
        if ($sorted->count() <= 2) {
            return $sorted;
        }
        // Pin only among posts the recipient hasn't already seen, so a nearby
        // already-seen post isn't forced back to the very top.
        $closest = $sorted->filter(fn ($p) => empty($p->seen_by_user))
            ->sortBy('_dist')->take(2)->values();
        $closestIds = $closest->pluck('id')->all();
        $rest = $sorted->reject(fn ($p) => in_array($p->id, $closestIds, true))->values();

        return $closest->concat($rest)->values();
    }

    /**
     * New posts first, then the ones a previous digest could not fit, each keeping its
     * scored order within its half.
     *
     * A carried post is older than the cursor by construction, so when the score is free to
     * interleave it the member gets a digest that opens with last week's dates - the exact
     * complaint the daily roll-up exists to avoid. Sinking the carried half means they fill
     * whatever room DIGEST_POST_CAP has left after the new posts and no more.
     *
     * The trade is deliberate: a member whose genuine daily volume is already over the cap
     * has no room left, so their carried posts wait until a quiet day or age out. That is
     * better than showing them stale posts in place of today's.
     */
    private function newPostsFirst(Collection $posts, UserDigest $tracker): Collection
    {
        $carried = array_flip(array_map('intval', $tracker->carryover ?? []));

        if ($carried === []) {
            return $posts;
        }

        [$old, $new] = $posts->partition(fn ($p) => isset($carried[(int) $p->id]));

        return $new->concat($old)->values();
    }

    /**
     * Which of the posts the cap left out are worth offering again: the msgids stored on
     * users_digests.carryover and re-admitted to the window by getPostsForUser().
     *
     * Two kinds are dropped rather than carried:
     *
     *  - Posts the member has ALREADY SEEN (in-app view, or an opened/clicked digest).
     *    scoreAndSortAvailable multiplies those by freegle.digest.seen_penalty, so they
     *    cannot win a slot against anything unseen; carrying them only spends the member's
     *    DIGEST_LOAD_CAP window on posts that will never be shown, and since carried posts
     *    are older than the cursor they spend it at the FRONT of the window, where they
     *    displace genuinely new ones.
     *  - Posts older than CARRYOVER_MAX_AGE_DAYS. Nothing else ever removes an id from this
     *    list except the post getting an outcome or being deleted, so without an age bound
     *    a busy member accumulates a permanent block of old posts.
     *
     * What survives both is then capped at DIGEST_POST_CAP, keeping the head of the list.
     * The age bound alone does not bound the SIZE - a member posting-rich enough to drop
     * hundreds fills the list with three days of RECENT posts (measured: the bound trims the
     * mean by a fifth and the worst case by nine) - and size is what costs them, because the
     * window query is oldest-first under DIGEST_LOAD_CAP, so every carried id is a slot a new
     * post does not get and the member's cursor falls further behind. One digest's worth is
     * also the most that could ever be shown, and droppedPostIds() is in the digest's own
     * priority order, so the head is the part with a real chance.
     *
     * A card with no arrival is not carried: pinned clearances are force-included every day
     * regardless of the cursor (getPinnedOpenPostsForUser), so carrying one is pointless.
     *
     * @param  int[]  $droppedIds  msgids the email could not fit - UnifiedDigest::droppedPostIds()
     * @param  Collection  $cards  the cards the digest was built from ($card['message'] per card)
     * @return int[]
     */
    private function carryoverFrom(array $droppedIds, Collection $cards): array
    {
        if ($droppedIds === []) {
            return [];
        }

        $cutoff = now()->subDays(self::CARRYOVER_MAX_AGE_DAYS);
        $byId = $cards->keyBy(fn ($c) => (int) $c['message']->id);

        $keep = array_filter($droppedIds, function ($id) use ($byId, $cutoff) {
            $card = $byId->get((int) $id);
            $post = $card['message'] ?? null;

            if (! $post || ! empty($post->seen_by_user) || empty($post->arrival)) {
                return false;
            }

            $arrival = $post->arrival instanceof \DateTimeInterface
                ? $post->arrival
                : \Illuminate\Support\Carbon::parse($post->arrival);

            return $arrival >= $cutoff;
        });

        return array_slice(array_values($keep), 0, DigestStyle::DIGEST_POST_CAP);
    }

    /**
     * Great-circle distance in metres between two (lat,lng) points in degrees.
     * Used for both the recipient->post distance and the post reach radius, so the
     * close = 1 - dist/reach ratio is a consistent true-metre ratio.
     */
    private function haversineMetres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000.0; // mean Earth radius (metres)
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Collapse duplicate rows of the same item into one card per distinct body.
     *
     * A single key can hold several distinct-body items (e.g. the same poster
     * reposts one item with a slightly reworded body, or posts two genuinely
     * different things at one location under the same subject), so every
     * distinct-body representative is kept, not just the first (Discourse
     * #9850: a reworded repost stopped collapsing into the original because
     * only the first entry per key was ever tried as a merge target).
     *
     * @return Collection<int,array{message:Message}>
     */
    public function deduplicatePosts(Collection $posts): Collection
    {
        $deduplicated = collect();
        $processed = [];

        foreach ($posts as $post) {
            $key = $this->getDeduplicationKey($post);
            $merged = false;

            foreach ($processed[$key] ?? [] as $existingIndex) {
                $existing = $deduplicated[$existingIndex];
                if ($this->bodiesMatch($existing['message'], $post)) {
                    $merged = true;
                    break;
                }
            }

            if (! $merged) {
                $index = $deduplicated->count();
                $deduplicated->push([
                    'message' => $post,
                ]);
                $processed[$key][] = $index;
            }
        }

        return $deduplicated;
    }

    /**
     * How far back to look for other copies of an item when deciding whether a member has
     * already had their immediate mail about it. The duplicates this catches are copies of one
     * item - a hand cross-post, an unmerged TrashNothing copy, a repost a day or two later -
     * and they all land close together. Past this a member re-offering the same thing is news
     * again, and gets a fresh mail.
     */
    public const ITEM_DEDUP_DAYS = 7;

    /**
     * Ceiling on the copies considered in one lookup. Far above any real posting rate, so it
     * only ever bites on a runaway poster, and when it does the cost is a duplicate mail
     * slipping through - never a post going unmailed.
     */
    public const ITEM_DEDUP_CANDIDATE_CAP = 2000;

    /**
     * Group message ids by ITEM, the way the daily digest groups cards.
     *
     * The immediate paths mail one message at a time, so on their own they mail once per COPY:
     * a member holding an unmerged TrashNothing set of the same post gets the same thing twice
     * within minutes. The daily digest already
     * collapses copies (deduplicatePosts); this exposes the same decision to the per-message
     * paths, reusing getDeduplicationKey() and bodiesMatch() rather than restating them, so the
     * two can never drift.
     *
     * @param  int[]  $msgids
     * @return array<int,int[]> msgid => the msgids that are the same item, including itself
     */
    public function itemSiblingMsgids(array $msgids): array
    {
        $msgids = array_values(array_unique(array_map('intval', $msgids)));
        if (empty($msgids)) {
            return [];
        }

        // Answer from the per-run memo where we can. This matters most for the daily digest,
        // which asks about the same posts once per member of a group. Safe against a copy
        // appearing mid-run: the copy is a new id, so it gets its own lookup, and that lookup
        // sees the earlier post.
        $wanted = array_values(array_filter($msgids, fn ($id) => ! isset($this->itemSiblingMemo[$id])));

        if (! empty($wanted)) {
            $this->lookUpItemSiblings($wanted);
        }

        $siblings = [];
        foreach ($msgids as $id) {
            $siblings[$id] = $this->itemSiblingMemo[$id] ?? [$id];
        }

        return $siblings;
    }

    /**
     * Do the lookup for msgids the memo does not hold yet, and memo the answers.
     *
     * @param  int[]  $msgids
     */
    private function lookUpItemSiblings(array $msgids): void
    {
        foreach ($msgids as $id) {
            $this->itemSiblingMemo[$id] = [$id];
        }

        $cols = ['id', 'fromuser', 'subject', 'textbody', 'tnpostid', 'locationid'];
        $targets = Message::whereIn('id', $msgids)->whereNotNull('subject')->get($cols);
        $posters = $targets->pluck('fromuser')->filter()->unique()->values()->all();
        if (empty($posters)) {
            return;
        }

        // Same poster, same place, recent, and the same kinds of post the immediate paths mail.
        // The (fromuser, arrival, type) index serves this directly, and the location narrowing
        // is free: it is part of the dedup key, so a copy from anywhere else could never match.
        $locations = $targets->pluck('locationid')->unique();
        $candidates = Message::whereIn('fromuser', $posters)
            ->where('arrival', '>=', now()->subDays(self::ITEM_DEDUP_DAYS))
            ->whereNull('deleted')
            ->whereNotNull('subject')
            ->whereIn('type', [Message::TYPE_OFFER, Message::TYPE_WANTED])
            ->where(function ($q) use ($locations) {
                $known = $locations->reject(fn ($v) => $v === null)->values()->all();
                if (! empty($known)) {
                    $q->orWhereIn('locationid', $known);
                }
                if ($locations->contains(null)) {
                    $q->orWhereNull('locationid');
                }
            })
            ->orderByDesc('arrival')
            ->limit(self::ITEM_DEDUP_CANDIDATE_CAP)
            ->get($cols);

        if ($candidates->count() >= self::ITEM_DEDUP_CANDIDATE_CAP) {
            // Newest first, and copies sit next to their originals in time, so a truncated
            // lookup still finds the copies that matter. Worth knowing about all the same: it
            // means someone is posting at a rate nobody anticipated.
            Log::warning('UnifiedDigestService: item dedup candidate cap hit', [
                'posters' => count($posters),
                'cap' => self::ITEM_DEDUP_CANDIDATE_CAP,
            ]);
        }

        // Bucket by dedup key once, so each message is a hash lookup rather than a scan of
        // every candidate.
        $byKey = [];
        foreach ($candidates as $candidate) {
            $byKey[$this->getDeduplicationKey($candidate)][] = $candidate;
        }

        foreach ($targets as $target) {
            $id = (int) $target->id;
            foreach ($byKey[$this->getDeduplicationKey($target)] ?? [] as $candidate) {
                $candidateId = (int) $candidate->id;
                if ($candidateId !== $id && $this->bodiesMatch($target, $candidate)) {
                    $this->itemSiblingMemo[$id][] = $candidateId;
                }
            }
        }
    }

    /** Per-run memo of msgid => the msgids that are the same item. See itemSiblingMsgids(). */
    private array $itemSiblingMemo = [];

    /**
     * The site is national now, so a digest run no longer scopes to a set of groups; kept
     * only for callers built around the old per-group shape.
     *
     * @return int[] always empty
     */
    public function digestGroupIdsForUser(User $user, string $mode): array
    {
        return [];
    }

    /**
     * Of these messages, the ones whose item this member has already been sent - an older copy
     * of the same thing, from a run their cursor has already passed.
     *
     * deduplicatePosts() collapses copies that land in ONE digest; this catches the copy that
     * lands in the NEXT one, which is how the same item reached members on four days running
     * (Discourse 9808). The cursor stands in for a record of what was sent, and it is a close
     * stand-in because copies of an item share a poster and a location: the two things that
     * decide whether a post reaches a member at all - it being their own, and their distance
     * slider - therefore treat every copy alike. A copy the cursor has passed is one the member
     * either received or was never going to.
     *
     * @param  int[]  $msgids
     * @param  int[]  $groupIds  Unused; the site is national now, kept for callers built around
     *                           the old per-group shape.
     * @param  \DateTimeInterface|string|null  $cursorMsgdate  Where their mail got to last time.
     * @return array<int,true> msgid => true for the ones already covered
     */
    public function itemsCoveredBeforeCursor(
        array $msgids,
        \DateTimeInterface|string|null $cursorMsgdate,
        array $groupIds
    ): array {
        if ($cursorMsgdate === null || empty($msgids)) {
            // No cursor means this is the member's first run: nothing has been covered yet, so
            // suppressing anything here would silently lose them a post.
            return [];
        }

        $siblings = $this->itemSiblingMsgids($msgids);
        $inBatch = array_flip(array_map('intval', $msgids));

        $olderCopies = [];
        foreach ($siblings as $msgid => $copies) {
            foreach ($copies as $copy) {
                if ($copy !== $msgid && ! isset($inBatch[$copy])) {
                    $olderCopies[$copy] = true;
                }
            }
        }

        if (empty($olderCopies)) {
            return [];
        }

        // Only copies the cursor has passed.
        $covered = Message::whereIn('id', array_keys($olderCopies))
            ->where('collection', Message::COLLECTION_APPROVED)
            ->whereNull('deleted')
            ->where('arrival', '<=', $cursorMsgdate)
            ->pluck('id')->map(fn ($v) => (int) $v)->flip()->all();

        $alreadyCovered = [];
        foreach ($siblings as $msgid => $copies) {
            foreach ($copies as $copy) {
                if ($copy !== $msgid && isset($covered[$copy])) {
                    $alreadyCovered[$msgid] = true;
                    break;
                }
            }
        }

        return $alreadyCovered;
    }

    /**
     * Drop the deduplicated cards whose item the member has already been sent in an earlier
     * run. Shared by the daily digest and the daily push so the inbox and the phone cannot
     * disagree about what counts as something they have already seen.
     *
     * @param  Collection  $cards  Entries of ['message' => Message]
     */
    public function dropCardsAlreadyCovered(
        Collection $cards,
        \DateTimeInterface|string|null $cursorMsgdate,
        array $groupIds
    ): Collection {
        if ($cards->isEmpty()) {
            return $cards;
        }

        $covered = $this->itemsCoveredBeforeCursor(
            $cards->map(fn ($card) => (int) $card['message']->id)->all(),
            $cursorMsgdate,
            $groupIds
        );

        if (empty($covered)) {
            return $cards;
        }

        return $cards->reject(fn ($card) => isset($covered[(int) $card['message']->id]))->values();
    }

    /**
     * The single id standing for each message's item, so "has this member already had this
     * item?" is one array lookup. Stable across runs for every copy of an item (the lowest
     * msgid in the set), so two copies seen in different runs land on the same entry.
     *
     * @param  int[]  $msgids
     * @return array<int,int> msgid => item id
     */
    public function itemIdsForMsgids(array $msgids): array
    {
        $items = [];
        foreach ($this->itemSiblingMsgids($msgids) as $msgid => $siblings) {
            $items[$msgid] = min($siblings);
        }

        return $items;
    }

    /**
     * Deduplicate the "came and went" (Taken/Received) posts.
     *
     * The greyed daily came-and-went section renders Message objects, and must
     * collapse the same item shown twice (a repost with a reworded body) the
     * way the live section does. Reuses deduplicatePosts() so the decision is
     * identical, then takes the representative message of each group.
     *
     * @param  Collection  $posts  Message objects.
     * @return Collection of Message
     */
    public function deduplicateCompletedPosts(Collection $posts): Collection
    {
        return $this->deduplicatePosts($posts)
            ->map(fn ($deduped) => $deduped['message'])
            ->values();
    }

    /**
     * Check if two messages have matching body content.
     */
    protected function bodiesMatch(Message $a, Message $b): bool
    {
        // TrashNothing posts with same tnpostid are always duplicates.
        if ($a->tnpostid && $b->tnpostid && $a->tnpostid === $b->tnpostid) {
            return true;
        }

        return $this->normalizeBody($a->textbody) === $this->normalizeBody($b->textbody);
    }

    /**
     * Normalize body text for comparison.
     */
    protected function normalizeBody(?string $body): string
    {
        if ($body === null) {
            return '';
        }

        $normalized = strtolower(trim($body));
        $normalized = preg_replace('/\s+/', ' ', $normalized);

        return substr($normalized, 0, 200);
    }

    /**
     * Generate a deduplication key for a message.
     */
    protected function getDeduplicationKey(Message $message): string
    {
        // Always key on CONTENT (fromuser + normalized subject + location), never
        // tnpostid. A TrashNothing item re-posted / re-crossposted on different days
        // gets a NEW tnpostid each time, so a "tn:{id}" key produced a distinct key
        // per posting and the digest listed the same item once per posting (Neville
        // Reid, Discourse 9808/#233: "Small lamp" 4x, 27 such items in 4 days).
        // (Browse is not the comparison to reach for here: it collapses on msgid and
        // nothing else, so it shows every copy. That asymmetry is deliberate - see
        // docs/developers/reference/trashnothing.md.) bodiesMatch() still
        // treats an equal tnpostid as a definitive duplicate and otherwise compares
        // normalized bodies, so genuine cross-posts (same tnpostid) AND same-item
        // reposts (different tnpostid, same body) both merge, while two different
        // items that merely share subject+location stay separate (bodies differ).
        $normalizedSubject = $this->normalizeSubject($message->subject);

        return implode('|', [
            $message->fromuser,
            $normalizedSubject,
            $message->locationid ?? 'unknown',
        ]);
    }

    /**
     * Normalize a subject line for comparison.
     * Removes OFFER/WANTED prefix and location suffix.
     */
    protected function normalizeSubject(string $subject): string
    {
        // Remove OFFER/WANTED prefix.
        $normalized = preg_replace('/^(OFFER|WANTED)\s*:\s*/i', '', $subject);

        // Remove location suffix (stuff in parentheses at the end).
        $normalized = preg_replace('/\s*\([^)]+\)\s*$/', '', $normalized);

        // Normalize whitespace.
        $normalized = preg_replace('/\s+/', ' ', trim($normalized));

        return strtolower($normalized);
    }

    /**
     * Update the digest tracker after sending.
     */
    protected function updateDigestTracker(UserDigest $tracker, Collection $posts, bool $emailWasSent = false, ?array $carryover = null): void
    {
        // Posts the digest left out at the post cap: re-offered by getPostsForUser() next
        // run. Null means "leave whatever is stored" (callers that did not examine them).
        $carry = $carryover === null ? [] : ['carryover' => ($carryover === [] ? null : array_values($carryover))];
        $lastPost = $posts->last();

        if ($lastPost) {
            // The cursor only ever moves FORWARD. $posts is arrival-ascending, so last() is
            // normally the newest thing examined - but a run whose window had nothing new
            // returns the carryover alone, and those are older than the cursor by
            // construction. Writing that arrival back would re-open a window the member has
            // already been sent: they would be re-offered posts, and the next run would scan
            // days instead of hours. Carried posts are re-offered through the carryover list,
            // which is the mechanism for exactly this - the cursor is not it.
            $advance = $tracker->lastmsgdate === null
                || $lastPost->arrival === null
                || $tracker->lastmsgdate <= $lastPost->arrival;

            $tracker->update(($advance ? [
                'lastmsgid' => $lastPost->id,
                'lastmsgdate' => $lastPost->arrival,
            ] : []) + ['lastsent' => now()] + $carry);
        } elseif ($emailWasSent) {
            // A daily email WAS sent but there are no cursor posts to advance past —
            // the digest contained only a pinned post, which is never part of the
            // cursor set (see the pinned-post block in sendDigest). Stamp lastsent
            // anyway so the once-per-London-day guard skips this user on the next tick.
            // Without this, a pinned-only digest re-sends every minute (incident
            // 2026-07-05: the once-per-day guard never fired, so 67 members received the
            // daily digest up to ~198 times).
            $tracker->update(['lastsent' => now()] + $carry);
        }
    }

    /**
     * The site is national now, so a post is never shown as posted to more than
     * one place. Kept only because it remains part of the public service surface.
     *
     * @param  array  $groupIds  unused
     */
    public function formatPostedTo(array $groupIds): string
    {
        return '';
    }
}
