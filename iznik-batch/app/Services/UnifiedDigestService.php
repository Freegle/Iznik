<?php

namespace App\Services;

use App\Mail\Digest\UnifiedDigest;
use App\Mail\Traits\FeatureFlags;
use App\Models\Membership;
use App\Models\Message;
use App\Models\MessageGroup;
use App\Models\User;
use App\Models\UserDigest;
use App\Services\Ripple\DigestPostScorer;
use App\Services\Ripple\DistancePreferenceFilter;
use App\Services\Ripple\RingIndex;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
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
    private ?\App\Services\Mail\MailSuppressionService $suppressionService = NULL;

    /**
     * Whether a provider is currently refusing our mail to this member.
     *
     * Consulted before every render. The service is a singleton and caches
     * the active suppression set in-process, so this stays cheap enough to
     * call once per recipient across tens of thousands of members.
     */
    private function suppressions(): \App\Services\Mail\MailSuppressionService
    {
        if ($this->suppressionService === NULL) {
            $this->suppressionService = app(\App\Services\Mail\MailSuppressionService::class);
        }

        return $this->suppressionService;
    }

    /** Per-run cache of post reach radius in metres, keyed by msgid. */
    private array $reachRadiusCache = [];

    /** Memoized once per run: whether the optional messages_pinned table exists. */
    private ?bool $messagesPinnedTableExists = null;

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
     * When the daily run falls behind (or a member is in many groups after rippling), that
     * window grows to days × groups and the eager-loaded Collection (attachments/fromUser/
     * groups) blows the PHP memory_limit — the run then dies part-way, so higher-id members
     * are never reached, their cursor stays stale, and the next run's window is even bigger
     * (self-amplifying). Capping the LOAD bounds per-member memory regardless of backlog:
     * updateDigestTracker() advances the cursor past exactly what was loaded (oldest-first),
     * so a backlogged member drains this many posts per run until caught up. Kept well above
     * the render cap so scoring/dedup still choose from a large pool for normal daily volume.
     */
    public const DIGEST_LOAD_CAP = 500;

    /**
     * Send unified digests to users who want them.
     *
     * @param string $mode One of MODE_IMMEDIATE or MODE_DAILY
     * @param int|null $userId Specific user ID to process (for testing)
     * @return array Statistics about the operation
     */
    public function sendDigests(string $mode, ?int $userId = null, ?int $limit = null, bool $dryRun = false, ?int $groupId = null, int $shard = 0, int $shards = 1, ?callable $shouldStop = null): array
    {
        if ($mode === self::MODE_IMMEDIATE) {
            return $this->sendImmediateDigests($limit, $dryRun, $groupId, $userId, $shard, $shards, $shouldStop);
        }

        if ($mode === self::MODE_REACH) {
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
        if (!self::isEmailTypeEnabled(self::EMAIL_TYPE)) {
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
                $stats['stopped'] = TRUE;
                Log::info('UnifiedDigestService: Daily digest stopping on shutdown signal', [
                    'users_processed' => $stats['users_processed'],
                    'emails_sent'     => $stats['emails_sent'],
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
     * V1-parity per-group iteration for immediate-mode digests.
     *
     * Mirrors the legacy V1 PHP Digest implementation exactly: walk the V1
     * `groups_digests` table, find new messages per group since that group's
     * cursor, send one notification to every member at emailfrequency=-1
     * (minus the poster), then advance the cursor. Using V1's table directly
     * (rather than a parallel per-user table) keeps both systems' notion of
     * "where we got up to" in sync, so re-enabling V1 in an emergency
     * fallback doesn't double-send.
     *
     * Cursor comparison uses a (arrival, msgid) tuple — V1 only uses arrival
     * and relies on its microsecond uniqueness, but tightening this here is
     * strictly safer for the rare collision case at no extra cost.
     *
     * @param int|null $groupLimit Cap groups processed per run (manual sanity)
     * @param bool $dryRun Skip the spool write and cursor advance
     * @param int|null $groupId Restrict to a single group (manual testing)
     * @param int|null $userId Restrict recipients to one user (manual testing)
     * @param int $shard Shard index (0..shards-1) for parallel workers
     * @param int $shards Total shard count; groups partitioned by MOD(groupid, shards)
     * @return array Statistics about the operation
     */
    public function sendImmediateDigests(?int $groupLimit = null, bool $dryRun = false, ?int $groupId = null, ?int $userId = null, int $shard = 0, int $shards = 1, ?callable $shouldStop = null): array
    {
        $stats = [
            'groups_processed' => 0,
            'users_processed' => 0,
            'emails_sent' => 0,
            'no_new_posts_groups' => 0,
            'errors' => 0,
        ];

        if (!self::isEmailTypeEnabled(self::EMAIL_TYPE)) {
            Log::info('UnifiedDigest emails disabled via FREEGLE_MAIL_ENABLED_TYPES');
            return $stats;
        }

        // This used to carry an EXISTS against memberships, to skip groups with no
        // immediate members at all. It was the single most expensive thing this service
        // did: 0.61-0.88s per execution, run about 232,000 times a day across the shards,
        // roughly one and a half to two cores of the database sustained - to skip 12
        // groups out of 505.
        //
        // Dropping it is safe because it decided nothing. processGroupImmediate selects
        // recipients with exactly the same condition (emailfrequency = Immediate,
        // collection = Approved), so a group with none produces an empty recipient list
        // and sends nothing. And a group that reaches that point with messages but no
        // recipients still advances its cursor, so it cannot re-scan the same messages
        // every tick.
        //
        // The 12 groups now cost one cursor-bounded message lookup each per pass, against
        // a correlated subquery that ran for all 505.
        $query = DB::table('groups_digests as gd')
            ->where('gd.frequency', Membership::EMAIL_FREQUENCY_IMMEDIATE)
            ->select('gd.groupid', 'gd.msgid as cursor_msgid', 'gd.msgdate as cursor_msgdate');

        // Partition groups across parallel shards. MOD(groupid, shards) =
        // shard means each group is owned by exactly one shard. Disjoint
        // → safe to run shards concurrently with no overlap, no advisory
        // locking between them.
        if ($shards > 1) {
            $query->whereRaw('MOD(gd.groupid, ?) = ?', [$shards, $shard]);
        }

        if ($groupId) {
            $query->where('gd.groupid', $groupId);
        }
        if ($groupLimit) {
            $query->limit($groupLimit);
        }

        $touchedUsers = [];

        foreach ($query->cursor() as $row) {
            // Graceful interrupt — check between groups so the in-flight
            // group's cursor advance completes before exit.
            if ($shouldStop !== null && $shouldStop()) {
                $stats['stopped'] = TRUE;
                Log::info('UnifiedDigestService: Immediate digest stopping on shutdown signal', [
                    'groups_processed' => $stats['groups_processed'],
                    'emails_sent'      => $stats['emails_sent'],
                ]);
                break;
            }

            try {
                $result = $this->processGroupImmediate($row, $dryRun, $userId);
                $stats['emails_sent'] += $result['emails'];
                foreach ($result['users'] as $uid) {
                    $touchedUsers[$uid] = true;
                }
                if ($result['emails'] === 0) {
                    $stats['no_new_posts_groups']++;
                }
            } catch (\Exception $e) {
                Log::error("UnifiedDigestService: Failed immediate send for group {$row->groupid}", [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                $stats['errors']++;
            }
            $stats['groups_processed']++;
        }

        $stats['users_processed'] = count($touchedUsers);
        Log::info('UnifiedDigestService: Immediate digest send complete', $stats);
        return $stats;
    }

    /**
     * Process one group's immediate-mode notifications.
     *
     * A member gets at most one immediate email per post, however many of their groups it
     * is on. This runs once per group, so a post on several of a member's groups would
     * otherwise be mailed to them once per group - it reaches them as one item and should
     * arrive once. The rippling_reach_notified ledger, which this path already writes to
     * keep the reach mailer from re-mailing, is read here for the same purpose: a later
     * group's pass sees the earlier one's row and skips the recipient.
     *
     * @return array{emails: int, users: int[]}
     */
    protected function processGroupImmediate(object $cursorRow, bool $dryRun, ?int $userFilter = null): array
    {
        $groupid = (int) $cursorRow->groupid;
        $cursorMsgdate = $cursorRow->cursor_msgdate;
        $cursorMsgid = (int) ($cursorRow->cursor_msgid ?? 0);

        $messages = $this->getGroupMessagesSinceCursor($groupid, $cursorMsgdate, $cursorMsgid);

        if ($messages->isEmpty()) {
            // Every new message in this group is rippling-excluded (delivered by the
            // expander-driven reach mailer instead). Advance the cursor past them to the
            // latest approved post — otherwise, after full rippling rollout the cursor
            // freezes here forever and the (unbounded) scan window grows every tick.
            $this->advanceCursorPastExcluded($groupid, $cursorMsgdate, $cursorMsgid, $dryRun);
            return ['emails' => 0, 'users' => []];
        }

        // Recipients: members at emailfrequency=-1, plus allowlist gate. The
        // inactivity gate must match the daily path's V1-parity threshold
        // (getUsersForDigest: Engage::USER_INACTIVE = 365*12*3600 = 182.5 days),
        // NOT a stricter 90-day cutoff. A 90-day window silently dropped members
        // who are inactive for 90-182.5 days from per-post emails even though V1
        // (Digest.php recipient query had no lastaccess filter at all) and the
        // daily digest would still mail them. NULL lastaccess (new users who have
        // never logged in) are included so a brand-new immediate member gets posts
        // right away.
        $memberQuery = DB::table('memberships')
            ->join('users', 'users.id', '=', 'memberships.userid')
            ->where('memberships.groupid', $groupid)
            ->where('memberships.emailfrequency', Membership::EMAIL_FREQUENCY_IMMEDIATE)
            ->where('memberships.collection', Membership::COLLECTION_APPROVED)
            ->whereNull('users.deleted')
            ->where(function ($q) {
                $q->whereNull('users.lastaccess')
                  ->orWhere('users.lastaccess', '>', now()->subSeconds(365 * 12 * 3600));
            });

        if ($userFilter) {
            $memberQuery->where('memberships.userid', $userFilter);
        }

        $memberIds = $memberQuery->pluck('memberships.userid')->all();

        $allowlist = $this->getImmediateAllowlist();
        if ($allowlist !== ['*'] && !empty($memberIds)) {
            $lower = array_map('strtolower', $allowlist);
            $memberIds = DB::table('users_emails')
                ->whereIn('userid', $memberIds)
                ->whereIn(DB::raw('LOWER(email)'), $lower)
                ->pluck('userid')->unique()->all();
        }

        if (empty($memberIds)) {
            // Advance cursor anyway — there's nothing to mail in this group
            // (config gate, allowlist), so we shouldn't re-scan the same
            // messages forever. V1 advances the cursor unconditionally too.
            $last = $messages->last();
            $this->advanceGroupCursor($groupid, $last->mg_arrival, (int) $last->mg_msgid, $dryRun);
            return ['emails' => 0, 'users' => []];
        }

        $users = User::whereIn('id', $memberIds)
            ->with(['emails', 'memberships'])
            ->get()
            ->keyBy('id');

        // Resolve each recipient's latlng ONCE, before the message loop — not once per
        // message. $users is already a small, once-per-group collection, so this is a
        // single extra pass, not a per-message cost. Used by the distance-preference
        // filter below (settings.browseMaxDistance); see the design doc's insertion
        // point B.
        $recipientLatLng = [];
        foreach ($users as $uid => $recipientUser) {
            $recipientLatLng[$uid] = $this->resolveUserLatLng($recipientUser);
        }

        // Who has already had an immediate email about each of these posts, from an earlier
        // group's pass in this run or a previous one. One query for the whole batch rather
        // than a lookup per (message, recipient).
        $alreadyMailed = [];
        $batchMsgids = $messages->pluck('mg_msgid')->map(fn ($v) => (int) $v)->all();
        if (!empty($batchMsgids)) {
            foreach (
                DB::table('rippling_reach_notified')
                    ->whereIn('msgid', $batchMsgids)
                    ->whereIn('userid', $memberIds)
                    ->get(['msgid', 'userid']) as $row
            ) {
                $alreadyMailed[(int) $row->msgid][(int) $row->userid] = true;
            }
        }

        $emailsSent = 0;
        $touched = [];
        $lastProcessed = null;
        $deferred = false;

        foreach ($messages as $message) {
            // Defer messages without a usable attachment so the email
            // doesn't render with a generic placeholder while AI image
            // generation is still in flight. After
            // ATTACHMENT_WAIT_DEADLINE_MINUTES we send anyway (the
            // placeholder is a known-good static URL — never a broken
            // link, just visually weaker). If we defer, STOP processing
            // this group's batch so later messages don't leapfrog the
            // deferred one and trigger a cursor jump that skips it.
            if (!$this->isImmediateMessageReady($message)) {
                $deferred = true;
                break;
            }

            $sponsorsCache = null;
            foreach ($users as $uid => $user) {
                if (!$user->email_preferred) {
                    continue;
                }
                // The member's provider is refusing our mail, so rendering
                // this would only add to a queue that cannot drain. Skipped
                // here rather than at send time because the MJML render below
                // is what actually costs us. Nothing to catch up later: the
                // group cursor advances regardless of individual recipients
                // (see $lastProcessed below), and a three-day-old OFFER is
                // taken or gone by the time a provider recovers anyway.
                if ($this->suppressions()->shouldSkip($user->email_preferred, (int) $uid, 'digest_immediate')) {
                    continue;
                }
                // Already mailed about this post from another of their groups. The item does
                // not become two items by being posted to two groups the member is in.
                if (isset($alreadyMailed[(int) $message->mg_msgid][(int) $uid])) {
                    continue;
                }
                // Distance-preference filter (settings.browseMaxDistance) — skip
                // spooling for this (message, recipient) pair when out of range, but
                // the message is still counted as processed below ($lastProcessed is
                // set outside this inner loop), so the group cursor advances
                // regardless of how many recipients were filtered. Own posts always
                // bypass (V1-parity own-post loop-back, test_immediate_includes_poster_own_post).
                $isOwnPost = (int) $message->fromuser === (int) $uid;
                if (!$this->passesDistancePreference(
                    $recipientLatLng[$uid] ?? null,
                    $message->lat,
                    $message->lng,
                    $user,
                    $isOwnPost,
                    $this->authorMaxMiles((int) $message->fromuser)
                )) {
                    continue;
                }
                if (!$dryRun) {
                    if ($sponsorsCache === null) {
                        // Immediate digest is about THIS group's post only, so
                        // scope sponsors to the group (V1 parity), not the
                        // recipient's whole membership union. The whole batch
                        // is one $groupid, so a single lookup serves every
                        // recipient in this loop.
                        $sponsorsCache = $this->getSponsorsForGroup((int) $groupid);
                    }
                    $deduped = collect([
                        ['message' => $message, 'postedToGroups' => [$groupid]],
                    ]);
                    // Spool through EmailSpoolerService so transient SMTP
                    // failures get retried by the processor rather than
                    // dropping a recipient. Permanent address-rejection
                    // failures (non-ASCII local-part, 550 etc) are classified
                    // + recorded as bounces inside spool() and return ''.
                    //
                    // spool() builds the message (incl. MJML render) up front
                    // and re-throws anything that ISN'T a permanent address
                    // failure (transient render/build error). That exception
                    // must not escape this foreach: if it did, $lastProcessed
                    // would not advance past this message, the group cursor
                    // would stall, and the NEXT cron tick would re-send the
                    // whole batch — exactly the bug that gave Penny Langley 27
                    // copies of the same post in 13 min. Catch it, skip the one
                    // recipient, and let the message still count as processed.
                    $spooled = false;
                    try {
                        app(\App\Services\EmailSpoolerService::class)->spool(
                            new UnifiedDigest($user, $deduped, self::MODE_IMMEDIATE, $sponsorsCache),
                            $user->email_preferred,
                            emailType: 'digest_immediate',
                        );
                        $spooled = true;
                    } catch (\Throwable $e) {
                        Log::warning('Skipping immediate digest recipient after spool failure; continuing loop', [
                            'user_id' => $uid,
                            'email' => $user->email_preferred,
                            'group' => $groupid,
                            'msgid' => (int) $message->mg_msgid,
                            'error' => $e->getMessage(),
                        ]);
                    }
                    // Record this send. Two readers depend on it:
                    //
                    //  - the reach mailer (mailNewlyReachedForPost), which excludes anyone already
                    //    here, so a rippled post is not mailed twice - once on arrival by this path
                    //    and again by the reach engine minutes later once its reach row appears;
                    //  - this path itself, on a later group's pass, so a post on several of the
                    //    member's groups reaches them once.
                    //
                    // Written for every send, not only while rippling is switched on: the second
                    // reader needs it either way, and a row for a post that never ripples is simply
                    // never read by the first.
                    if ($spooled) {
                        // Coordinate with the expander-driven reach mailer: record this send so
                        // mailNewlyReachedForPost never re-mails the same member once the post's
                        // reach row appears (the post is cursor-mailed on arrival, before the reach
                        // engine creates rippling_reach minutes later). Kept OUTSIDE the spool try so
                        // a ledger-write failure can't masquerade as a spool failure or abort the
                        // loop; harmless for non-rippling posts (the row is simply never read).
                        try {
                            DB::table('rippling_reach_notified')->insertOrIgnore([
                                'msgid' => (int) $message->mg_msgid,
                                'userid' => (int) $uid,
                                'notified_at' => now(),
                            ]);
                        } catch (\Throwable $e) {
                            Log::warning('Immediate digest: notified-ledger write failed (member may be re-mailed)', [
                                'user_id' => $uid,
                                'msgid' => (int) $message->mg_msgid,
                                'error' => $e->getMessage(),
                            ]);
                        }

                        $alreadyMailed[(int) $message->mg_msgid][(int) $uid] = true;
                    }
                }
                $emailsSent++;
                $touched[$uid] = true;
            }

            $lastProcessed = $message;
        }

        // Advance the cursor to the LAST SUCCESSFULLY PROCESSED message,
        // not the last message in the candidate batch. If we deferred,
        // the deferred message and everything after it stay in the
        // "after cursor" range so the next tick re-considers them.
        //
        // Skip the advance entirely when --user is restricting recipients:
        // only some members got mailed, so advancing would skip everyone
        // else for these messages on the next unrestricted run. --user
        // is a testing affordance and must not mutate prod cursor state.
        if ($lastProcessed && $userFilter === null) {
            $this->advanceGroupCursor(
                $groupid,
                $lastProcessed->mg_arrival,
                (int) $lastProcessed->mg_msgid,
                $dryRun
            );
        }

        if ($deferred) {
            Log::debug("UnifiedDigestService: deferred unattached messages in group {$groupid}");
        }

        return ['emails' => $emailsSent, 'users' => array_keys($touched)];
    }

    /**
     * Number of minutes to wait for an AI-generated attachment before
     * giving up and mailing with the offer/wanted placeholder. Long
     * enough to cover normal generation latency, short enough that a
     * permanently-stuck AI job doesn't hold immediate notifications
     * back forever.
     */
    public const ATTACHMENT_WAIT_DEADLINE_MINUTES = 5;

    /**
     * Whether this message is ready to be mailed as part of an immediate
     * digest. A message is ready if either:
     *   - it has a usable attachment (user-uploaded or AI-generated and
     *     fully written to messages_attachments), OR
     *   - it arrived more than ATTACHMENT_WAIT_DEADLINE_MINUTES ago, in
     *     which case we mail it with the type-specific placeholder
     *     rather than holding the notification indefinitely.
     *
     * The placeholder fallback is a static URL hosted on the site
     * (offer_placeholder / wanted_placeholder); known-good, never broken.
     */
    protected function isImmediateMessageReady(Message $message): bool
    {
        if ($message->attachments && $message->attachments->isNotEmpty()) {
            $usable = $message->attachments->first(function ($a) {
                return !empty($a->externaluid)
                    || !empty($a->externalurl)
                    || (int) ($a->archived ?? 0) === 1;
            });
            if ($usable) {
                return true;
            }
        }

        $arrival = $message->mg_arrival
            ?? ($message->arrival instanceof \Carbon\Carbon ? $message->arrival : \Carbon\Carbon::parse($message->arrival ?? $message->date));
        $arrival = $arrival instanceof \Carbon\Carbon ? $arrival : \Carbon\Carbon::parse($arrival);

        return $arrival->lessThanOrEqualTo(now()->subMinutes(self::ATTACHMENT_WAIT_DEADLINE_MINUTES));
    }

    /**
     * Fetch messages in a group since the (arrival, msgid) cursor.
     *
     * Tuple comparison (NOT V1's plain arrival > msgdate) so that two
     * messages with identical microsecond-precision arrival timestamps
     * can't both fall past the cursor and one of them be missed.
     */

    /**
     * Decoupled, sharded reach-mail pass. Mails members who are newly inside a rippling post's
     * reach polygon — the work that used to run inline in ExpandService's serial Phase-2 loop,
     * where (per the 2026-06-24 live profile) it was ~75% of the run's wall-clock. Pulling it out
     * lets the reach writes stay serial (Galera single-writer) while the mail fans out in parallel.
     *
     * Processes rippling_reach rows whose reach changed recently (updated_at within the configured
     * window — reach only changes on init/advance, never on this pass, which writes only
     * rippling_reach_notified), partitioned across parallel workers by MOD(msgid, shards) exactly
     * as the immediate-digest cron partitions by MOD(groupid, shards). Disjoint partitions → shards
     * run concurrently with no locking. Idempotent regardless of window overlap: the
     * rippling_reach_notified ledger means an already-notified member is never re-mailed.
     *
     * @param int|null $limit Cap on posts processed per run (null = no cap).
     * @param int $shard Shard index (0..shards-1).
     * @param int $shards Total shard count; posts partitioned by MOD(msgid, shards).
     * @return array{posts_processed:int,emails_sent:int,errors:int,stopped?:bool}
     */
    public function sendReachDigests(?int $limit = null, bool $dryRun = false, int $shard = 0, int $shards = 1, ?callable $shouldStop = null): array
    {
        $stats = ['posts_processed' => 0, 'emails_sent' => 0, 'errors' => 0];

        if (!self::isEmailTypeEnabled(self::EMAIL_TYPE)) {
            return $stats;
        }

        // Only posts whose reach changed recently need a mail check — an unchanged polygon has no
        // newly-inside members the ledger hasn't already covered. This mirrors the old inline
        // trigger (mail fired on each init/advance). The window overlaps the cron interval so a
        // post is never dropped between ticks; overlap is harmless because the ledger dedupes.
        $windowMinutes = (int) config('freegle.ripple.reach_mail_window_minutes', 60);

        $query = DB::table('rippling_reach')
            ->whereIn('status', ['expanding', 'done'])
            ->where('updated_at', '>=', now()->subMinutes($windowMinutes));

        // Disjoint MOD(msgid, shards) partition — same model as sendImmediateDigests' MOD(groupid,
        // shards); each post is owned by exactly one shard, so shards run concurrently safely.
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

        return $stats;
    }

    /**
     * Newly-reached rippling immediate mail (#0 step 4). Called by the decoupled, sharded reach-mail
     * pass (sendReachDigests) and by AutoApproveService (the post-'done' approval gap) — no longer
     * inline in ExpandService's serial loop. Mails the post to every immediate-eligible member of a
     * group it is APPROVED on whose location the reach NOW covers and who has not already been
     * notified (rippling_reach_notified), recording each so a later tick — or another rippled-in
     * group — never re-mails them. Because it re-runs every tick (no cursor), members the reach
     * reaches later are picked up; the cursor digest excludes reach-row posts so neither path
     * double-mails. Member point = settings.mylocation (both coords) else lastlocation. Returns
     * the number spooled. Best-effort: any failure is logged, never aborts the expander.
     *
     * Members-only by design: the memberships JOIN restricts immediate reach-mail to users who
     * have already joined a group this post is on. Cold-emailing non-members about a group they
     * haven't joined is not appropriate; non-members within reach discover the post via browse and
     * the daily digest. Do not "fix" the JOIN to include non-members.
     */
    /** Memoized presence of rippling_reach.overflow_bounds. Null until first checked. */
    private static ?bool $overflowColumn = null;

    /** Test-only: forget the memoized overflow-column check. */
    public static function forgetOverflowColumn(): void
    {
        self::$overflowColumn = null;
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
            self::$overflowColumn ??= Schema::hasColumn('rippling_reach', 'overflow_cells');
            if (! self::$overflowColumn) {
                return $none;
            }
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

    public function mailNewlyReachedForPost(int $msgid, bool $dryRun = false): int
    {
        if (!self::isEmailTypeEnabled(self::EMAIL_TYPE)) {
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
                                 AND JSON_EXTRACT(u.settings, '$.mylocation.lng') IS NOT NULL
                            THEN CAST(JSON_EXTRACT(u.settings, '$.mylocation.lat') AS DECIMAL(10,6))
                            ELSE l.lat END";
            $lngExpr = "CASE WHEN JSON_EXTRACT(u.settings, '$.mylocation.lat') IS NOT NULL
                                 AND JSON_EXTRACT(u.settings, '$.mylocation.lng') IS NOT NULL
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
            // PHP: the message's cell grid is fetched ONCE and every
            // surviving candidate's point is probed against it - the same
            // stored bytes every other surface answers from.
            $probeCells = DB::table('rippling_reach')->where('msgid', $msgid)->value('polygon_cells');
            $containSql = "(ST_GeometryType(mr.outer_bound) <> 'POINT' AND ST_Contains(mr.outer_bound, $point))";
            $mrJoin = '';

            // in_primary: from the outer bound, refined by the PHP probe
            // below. density_band rides along for the ring index whenever
            // either consumer needs post-filtering.
            $primaryFlag = ", $containSql AS in_primary"
                . ", JSON_UNQUOTE(JSON_EXTRACT(u.settings, '$.browseDensityBand')) AS density_band";
            $primaryParams = [$srid];

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
                 FROM messages_groups mg
                 JOIN rippling_reach mr ON mr.msgid = mg.msgid$mrJoin
                 JOIN memberships m ON m.groupid = mg.groupid
                      AND m.emailfrequency = ? AND m.collection = 'Approved'
                 JOIN users u ON u.id = m.userid
                 LEFT JOIN locations l ON l.id = u.lastlocation
                 WHERE mg.msgid = ? AND mg.collection = 'Approved' AND mg.deleted = 0
                   AND mr.status <> 'held'
                   AND NOT EXISTS (
                         SELECT 1 FROM messages_outcomes mo
                         WHERE mo.msgid = mg.msgid AND mo.outcome IN ('Taken', 'Received', 'Withdrawn')
                       )
                   AND u.deleted IS NULL AND (u.lastaccess IS NULL OR u.lastaccess > ?)
                   AND ($containSql$overflowSql)
                   AND NOT EXISTS (
                         SELECT 1 FROM rippling_reach_notified n WHERE n.msgid = mg.msgid AND n.userid = u.id
                       )",
                array_merge(
                    $primaryParams,
                    [Membership::EMAIL_FREQUENCY_IMMEDIATE, $msgid, now()->subDays(90), $srid],
                    $overflowParams
                )
            ));

            // Refine the outer-bound superset to the exact reach: probe
            // each candidate's point against the message's cell grid. A
            // candidate the probe rejects (or cannot decide - unreadable
            // bytes admit nobody) is only a recipient if a ring admits
            // them, exactly like a candidate outside the reach.
            $cellSets = app(\App\Services\Ripple\CellSetService::class);
            foreach ($recipientRows as $row) {
                $in = false;
                if ($probeCells !== null && $probeCells !== ''
                    && $row->resolved_lat !== null && $row->resolved_lng !== null) {
                    $in = $cellSets->containsEncoded($probeCells, (float) $row->resolved_lng, (float) $row->resolved_lat) === true;
                }
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
     * @param int[] $userIds
     * @param array<int,string> $reasons
     * @return int[]
     */
    public function mailPostToUsers(int $msgid, array $userIds, bool $dryRun = false, array $reasons = []): array
    {
        if (!self::isEmailTypeEnabled(self::EMAIL_TYPE) || empty($userIds)) {
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
                              AND JSON_EXTRACT(u.settings, '$.mylocation.lng') IS NOT NULL
                         THEN CAST(JSON_EXTRACT(u.settings, '$.mylocation.lat') AS DECIMAL(10,6))
                         ELSE l.lat END AS resolved_lat,
                    CASE WHEN JSON_EXTRACT(u.settings, '$.mylocation.lat') IS NOT NULL
                              AND JSON_EXTRACT(u.settings, '$.mylocation.lng') IS NOT NULL
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
     * @param int[] $recipientIds
     * @param array<int,array{0:float,1:float}|null> $recipientLatLng
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

        $postedToGroups = DB::table('messages_groups')->where('msgid', $msgid)
            ->where('collection', MessageGroup::COLLECTION_APPROVED)->where('deleted', 0)
            ->pluck('groupid')->map(fn ($v) => (int) $v)->all();
        $sponsorsCache = !empty($postedToGroups) ? $this->getSponsorsForGroup((int) $postedToGroups[0]) : null;

        $users = User::whereIn('id', $recipientIds)->with(['emails', 'memberships'])->get();
        $mailed = [];
        foreach ($users as $user) {
            if (!$user->email_preferred) {
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
            if (!$this->passesDistancePreference(
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
            $deduped = collect([['message' => $msg, 'postedToGroups' => $postedToGroups]]);
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
        if (!empty($mailed) && !$dryRun) {
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

    protected function getGroupMessagesSinceCursor(int $groupid, ?string $cursorMsgdate, int $cursorMsgid): Collection
    {
        $query = Message::select(
                'messages.*',
                'messages_groups.groupid as mg_groupid',
                'messages_groups.arrival as mg_arrival',
                'messages_groups.msgid as mg_msgid'
            )
            ->join('messages_groups', 'messages.id', '=', 'messages_groups.msgid')
            ->where('messages_groups.groupid', $groupid)
            ->where('messages_groups.collection', MessageGroup::COLLECTION_APPROVED)
            ->where('messages_groups.deleted', 0)
            ->whereNull('messages.deleted')
            // Rippling posts (those with a rippling_reach row) are mailed by the
            // expander-driven reach mailer (mailNewlyReachedForPost) — reach-gated and
            // ledger-deduped — so exclude them here or the cursor digest would double-mail.
            // Inert until the reach engine populates rippling_reach (no rows → no exclusion).
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('rippling_reach')
                    ->whereColumn('rippling_reach.msgid', 'messages.id');
            })
            // V1 parity (Digest.php:218): a post with any outcome
            // (Taken/Received/Withdrawn/...) is no longer available, so it
            // must not appear in the immediate digest either.
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('messages_outcomes')
                    ->whereColumn('messages_outcomes.msgid', 'messages.id');
            })
            ->whereIn('messages.type', [Message::TYPE_OFFER, Message::TYPE_WANTED]);

        if ($cursorMsgdate) {
            // (arrival, msgid) > (cursorMsgdate, cursorMsgid)
            $query->whereRaw(
                '(messages_groups.arrival > ? OR (messages_groups.arrival = ? AND messages_groups.msgid > ?))',
                [$cursorMsgdate, $cursorMsgdate, $cursorMsgid]
            );
        }

        return $query
            ->orderBy('messages_groups.arrival', 'asc')
            ->orderBy('messages_groups.msgid', 'asc')
            // Bound the per-tick scan; the cursor advances to the last processed message so
            // the next tick continues. Prevents an unbounded window on a busy group.
            ->limit(500)
            ->with($this->digestPostEagerLoads())
            ->get();
    }

    /**
     * Advance the per-group cursor to the latest approved post when every new message was
     * rippling-excluded (so getGroupMessagesSinceCursor returned nothing to mail). Without
     * this the cursor would never move past reach posts and the scan window would grow
     * without bound after full rippling rollout. Mirrors getGroupMessagesSinceCursor's
     * filters minus the reach exclusion, taking the newest (arrival, msgid) as the watermark.
     */
    protected function advanceCursorPastExcluded(int $groupid, ?string $cursorMsgdate, int $cursorMsgid, bool $dryRun): void
    {
        $watermark = DB::table('messages_groups as mg')
            ->join('messages', 'messages.id', '=', 'mg.msgid')
            ->where('mg.groupid', $groupid)
            ->where('mg.collection', MessageGroup::COLLECTION_APPROVED)
            ->where('mg.deleted', 0)
            ->whereNull('messages.deleted')
            ->whereIn('messages.type', [Message::TYPE_OFFER, Message::TYPE_WANTED]);

        if ($cursorMsgdate) {
            $watermark->whereRaw(
                '(mg.arrival > ? OR (mg.arrival = ? AND mg.msgid > ?))',
                [$cursorMsgdate, $cursorMsgdate, $cursorMsgid]
            );
        }

        $row = $watermark
            ->orderByDesc('mg.arrival')->orderByDesc('mg.msgid')
            ->select('mg.arrival', 'mg.msgid')
            ->first();

        if ($row) {
            $this->advanceGroupCursor($groupid, $row->arrival, (int) $row->msgid, $dryRun);
        }
    }

    /**
     * Advance the per-group cursor after processing immediate-mode messages.
     *
     * Writes both msgid and msgdate so the next tick's tuple comparison
     * lines up correctly even if two messages share an arrival timestamp.
     */
    protected function advanceGroupCursor(int $groupid, $msgdate, int $msgid, bool $dryRun): void
    {
        if ($dryRun) {
            return;
        }
        DB::table('groups_digests')
            ->where('groupid', $groupid)
            ->where('frequency', Membership::EMAIL_FREQUENCY_IMMEDIATE)
            ->update([
                'msgid' => $msgid,
                'msgdate' => $msgdate,
                'ended' => now(),
            ]);
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
     *               list of opted-in email addresses (lower/exact as given).
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
     * Constrain a memberships query to the cadences a digest mode serves.
     *
     * Immediate matches exactly emailfrequency = -1. Daily collapses EVERY
     * periodic cadence into one roll-up: any value > 0 (hourly=1, 2h, 4h,
     * 8h, daily=24). The per-group digest that used to service the
     * intermediate cadences (1/2/4/8h) has been removed, so without this fold
     * those members would have no sender and silently stop receiving mail.
     * emailfrequency = 0 (NEVER) is an opt-out and is excluded from both.
     *
     * @param \Illuminate\Contracts\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder $query
     * @param string $mode One of MODE_IMMEDIATE or MODE_DAILY
     * @param string $column Column to constrain (qualified when joining)
     */
    protected function applyDigestFrequency($query, string $mode, string $column = 'emailfrequency'): void
    {
        if ($mode === self::MODE_IMMEDIATE) {
            $query->where($column, Membership::EMAIL_FREQUENCY_IMMEDIATE);
        } else {
            // Any positive cadence — fold 1/2/4/8/24h all into daily.
            $query->where($column, '>', Membership::EMAIL_FREQUENCY_NEVER);
        }
    }

    /**
     * Get users who should receive digests based on mode.
     *
     * @param string $mode One of MODE_IMMEDIATE or MODE_DAILY
     * @param int|null $userId Specific user ID to process
     * @param int $shard Shard index (0..shards-1) for parallel daily workers
     * @param int $shards Total shard count; users partitioned by MOD(users.id, shards)
     * @return \Illuminate\Support\LazyCollection
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
        // An explicit --user bypasses this. Immediate mode shards by group
        // inside sendImmediateDigests instead, so don't double-shard here.
        if ($mode === self::MODE_DAILY && !$userId && $shards > 1) {
            // Hash the id, don't MOD it directly. Under Galera/Percona the auto-increment
            // stride equals the cluster size (auto_increment_increment, 3 on a 3-node
            // cluster) and each node has a different offset, so users.id is NOT contiguous.
            // MOD(users.id, shards) then skews hard whenever shards shares a factor with the
            // cluster size (e.g. 3/6/9 → almost everyone lands on one shard). CRC32 gives a
            // uniform spread for ANY shard count and is immune to the stride / a cluster-size
            // change. Disjoint partitions still hold (each id maps to exactly one shard).
            $query->whereRaw('CRC32(users.id) % ? = ?', [$shards, $shard]);
        }

        // V1 parity (the legacy V1 PHP Digest implementation): per-group
        // memberships.emailfrequency is authoritative at send time. The
        // global users.settings.simplemail only acts as the join-time
        // DEFAULT that populates a new membership's emailfrequency (see
        // User.php SIMPLE_MAIL_* mapping) — once a per-group value
        // exists, that value alone controls delivery. Without this
        // alignment, a user with legacy simplemail='Full' who later
        // switched some groups to Daily was being treated as a Full
        // user for every group and getting immediate spam for groups
        // they had explicitly downgraded.
        //
        // simplemail='None' remains an account-level opt-out per V1's
        // User::sendOurMails(), so we exclude those users here.
        //
        // Daily mode collapses EVERY periodic cadence (hourly/2h/4h/8h/daily)
        // into the one daily roll-up — see applyDigestFrequency(). With the
        // per-group digest removed, those intermediate cadences would
        // otherwise have no sender at all, so a member set to e.g. 4-hourly
        // must still be picked up here rather than silently dropped.
        $query->whereExists(function ($subquery) use ($mode) {
            $subquery->select(DB::raw(1))
                ->from('memberships')
                ->whereColumn('memberships.userid', 'users.id')
                ->where('memberships.collection', Membership::COLLECTION_APPROVED);
            $this->applyDigestFrequency($subquery, $mode, 'memberships.emailfrequency');
        })->where(function ($q) {
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
        } elseif ($mode === self::MODE_DAILY && !$userId) {
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
        if ($mode === self::MODE_DAILY && !$userId) {
            return $this->streamDailyOverdueFirst($query, 500);
        }

        return $query->with(['emails', 'memberships'])->lazyById(500);
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
        $eager = ['emails', 'memberships'];
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
     * Send a digest to a specific user.
     *
     * Immediate mode sends ONE email per post (V1 parity, and matches what
     * "immediate" means to the recipient — each new post arrives as its own
     * notification). Daily mode bundles every new post since the previous
     * send into a single rolled-up digest.
     *
     * @param User $user
     * @param string $mode
     * @return array{status: 'sent'|'no_posts'|'skipped'|'suppressed', count: int}
     */
    protected function sendDigestToUser(User $user, string $mode, bool $dryRun = false): array
    {
        $email = $user->email_preferred;

        if (!$email) {
            Log::debug("UnifiedDigestService: User {$user->id} has no email address");
            return ['status' => 'skipped', 'count' => 0];
        }

        // The member's provider is refusing our mail. Return BEFORE the
        // digest tracker is touched: leaving the watermark where it is means
        // that when the provider recovers, the next daily run spans the whole
        // gap and sends exactly one catch-up digest covering it, rather than
        // one stale digest per day missed. That is the entire catch-up
        // mechanism for digests - no replay queue needed.
        if ($this->suppressions()->shouldSkip($email, (int) $user->id, 'digest_' . $mode)) {
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
        $pinnedCards = $mode === self::MODE_DAILY
            ? $this->deduplicatePosts($this->getPinnedOpenPostsForUser($user))
            : collect();

        if ($allPosts->isEmpty() && $pinnedCards->isEmpty()) {
            return ['status' => 'no_posts', 'count' => 0];
        }

        // available  = no outcome at all (the live posts)
        // completed  = a Taken/Received outcome (the "came and went" list, daily only)
        // withdrawn/expired (has_outcome && !has_success) appear in neither.
        $posts = $allPosts->filter(fn ($p) => !$p->has_outcome)->values();

        // Order the live posts by the rippling digest-preview score (nearer +
        // newer + less-seen float up), matching the /rippling "Digest preview".
        // Daily only — immediate mode stays chronological (single-group, real-time).
        // Dedup runs after, so the kept cross-post representative is the top-scoring one.
        if ($mode === self::MODE_DAILY) {
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
        }

        $completedPosts = $mode === self::MODE_DAILY
            ? $this->deduplicateCompletedPosts($allPosts->filter(fn ($p) => $p->has_success)->values())
            : collect();

        if ($posts->isEmpty() && $pinnedCards->isEmpty()) {
            // No live posts to send. Still advance the cursor past everything
            // examined (incl. completed/withdrawn) so they don't re-surface,
            // and don't send a completed-only digest.
            if (!$dryRun) {
                $this->updateDigestTracker($digestTracker, $allPosts);
            }
            return ['status' => 'no_posts', 'count' => 0];
        }

        // Deduplicate cross-posted items.
        $deduplicatedPosts = $this->deduplicatePosts($posts);

        if ($deduplicatedPosts->isEmpty() && $pinnedCards->isEmpty()) {
            // Nothing to send, but still advance the tracker past these posts
            // so the next tick doesn't re-fetch and re-filter the same set.
            if (!$dryRun) {
                $this->updateDigestTracker($digestTracker, $allPosts);
            }
            return ['status' => 'no_posts', 'count' => 0];
        }

        // Sponsors. The combined daily digest spans all the user's groups, so
        // the cross-group union is right; the immediate path scopes per-post to
        // that post's group below (V1 parity — one group's email, one group's
        // sponsors).
        $sponsors = $mode === self::MODE_IMMEDIATE
            ? collect()
            : $this->getSponsorsForUser($user);

        if ($mode === self::MODE_IMMEDIATE) {
            // One email per post. Advance the tracker after each send so a
            // mid-loop crash doesn't cause us to re-mail already-sent posts
            // on the next cron tick.
            $sent = 0;
            foreach ($deduplicatedPosts as $deduped) {
                if (!$dryRun) {
                    // Each immediate email is about one post; carry that post's
                    // sponsors. For a cross-post, prefer a group the recipient is a
                    // member of (matching the digest header/byline group) rather
                    // than an arbitrary first group.
                    $postGroupId = (int) (UnifiedDigest::selectPreferredGroup(
                        $deduped['postedToGroups'] ?? [],
                        $user->memberships->pluck('groupid')->all()
                    ) ?? 0);
                    $postSponsors = $this->getSponsorsForGroup($postGroupId);
                    app(\App\Services\EmailSpoolerService::class)->spool(
                        new UnifiedDigest($user, collect([$deduped]), $mode, $postSponsors),
                        emailType: 'digest_immediate',
                    );
                    $this->advanceImmediateTracker($digestTracker, $deduped['message']);
                }
                $sent++;
            }

            // Mop up the trailing edge of the raw batch: cross-posted items
            // merged into a single logical post may have raw rows with
            // arrivals later than the representative we picked above. Ensure
            // the tracker is past every raw row in this batch so the next
            // tick doesn't refetch and treat them as fresh posts.
            if (!$dryRun) {
                $this->updateDigestTracker($digestTracker, $posts);
            }

            return ['status' => 'sent', 'count' => $sent];
        }

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
        if (!$dryRun) {
            app(\App\Services\EmailSpoolerService::class)->spool(
                new UnifiedDigest($user, $deduplicatedPosts, $mode, $sponsors, $completedPosts),
                emailType: 'digest_daily',
            );
            // Advance the cursor past everything examined this window (live,
            // completed and withdrawn) so nothing re-surfaces tomorrow. Pass
            // emailWasSent=true so lastsent is stamped even when $allPosts is empty
            // (a pinned-only digest still sent an email) — see updateDigestTracker.
            $this->updateDigestTracker($digestTracker, $allPosts, true);
        }

        return ['status' => 'sent', 'count' => 1];
    }

    /**
     * Move the tracker past a single immediate-mode post so a mid-loop
     * crash doesn't cause re-mailing of already-sent posts.
     */
    protected function advanceImmediateTracker(UserDigest $tracker, Message $post): void
    {
        $tracker->update([
            'lastmsgid' => $post->id,
            'lastmsgdate' => $post->arrival,
            'lastsent' => now(),
        ]);
    }

    /**
     * Get or create a digest tracking record for a user.
     *
     * Immediate mode: bootstrap fresh trackers with lastmsgdate=NOW so the
     * user only receives notifications for posts arriving AFTER we start
     * tracking them. The previous behaviour (null → "last 24h" window in
     * getPostsForUser) caused a duplicate-flood the first time we processed
     * each user: V1's bulk3 cron had been sending them immediate emails up
     * to the moment we took over, so the 24h backlog we'd pull was every
     * post V1 had just covered.
     *
     * Daily mode keeps the null sentinel so the existing "last 24h" first-
     * tick behaviour still applies — a daily digest user genuinely expects
     * a roll-up of what's new since yesterday on their first send.
     *
     * @param User $user
     * @param string $mode
     * @return UserDigest
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
                'lastmsgdate' => $mode === self::MODE_IMMEDIATE ? now() : null,
            ]
        );
    }

    /**
     * Get all posts for a user from their member groups since last digest.
     *
     * V1 parity (the legacy V1 PHP Digest implementation): per-group
     * memberships.emailfrequency is authoritative at send time. The
     * global users.settings.simplemail is NEVER consulted here — it
     * only sets the join-time default for emailfrequency on new
     * memberships. So a user with simplemail='Full' who later switched
     * one group to Daily must receive ONLY a daily roll-up for that
     * group from this method, not posts from every group they belong
     * to. This is the bug that caused user 801 (Emma, Richmond Upon
     * Thames) to be flooded with immediate emails for groups she had
     * explicitly downgraded.
     *
     * @param User $user
     * @param UserDigest $tracker
     * @param string $mode One of MODE_IMMEDIATE or MODE_DAILY
     * @return Collection
     */
    /**
     * Column-constrained eager-load spec for digest posts, shared by every digest
     * query so they load the same lean set.
     *
     * - groups: only the display-name columns. The default (groups.*) pulls each
     *   group's boundary polygons (poly/polyofficial/polyindex) plus settings/
     *   welcomemail/description — none of which a digest renders — and that was
     *   ~27% of per-user DB time. The digest only needs id + nameshort/namefull
     *   (namedisplay derives from those).
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
            'groups' => fn ($q) => $q->select('groups.id', 'groups.nameshort', 'groups.namefull'),
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
     * @param array $latlng [lat, lng] - the member's location.
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
            ' AND rr.msgid NOT IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
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
        // Immediate pulls only the user's immediate (-1) groups; daily pulls
        // every group on a periodic cadence (hourly/2h/4h/8h/daily), folding
        // them all into the single daily roll-up. See applyDigestFrequency().
        $membershipQuery = $user->memberships()
            ->where('collection', Membership::COLLECTION_APPROVED);
        $this->applyDigestFrequency($membershipQuery, $mode);

        $groupIds = $membershipQuery->pluck('groupid');

        if ($groupIds->isEmpty()) {
            return collect();
        }

        // Single query for the whole window, with two outcome flags so the
        // caller can partition in PHP (no second round-trip):
        //   has_outcome   — any outcome row exists (Taken/Received/Withdrawn/…)
        //   has_success   — a Taken/Received outcome exists
        // From these: available = !has_outcome; "came and went" = has_success;
        // withdrawn/expired (has_outcome && !has_success) are dropped by the
        // caller. V1 parity (Digest.php:218 only lists count(outcomes)==0 as
        // available); matches the platform browse/map dropping any outcome.
        $successList = "'" . Message::OUTCOME_TAKEN . "','" . Message::OUTCOME_RECEIVED . "'";
        $query = Message::select('messages.*', 'messages_groups.groupid', 'messages_groups.arrival')
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
            ->join('messages_groups', 'messages.id', '=', 'messages_groups.msgid')
            ->whereIn('messages_groups.groupid', $groupIds)
            ->where('messages_groups.collection', MessageGroup::COLLECTION_APPROVED)
            ->where('messages_groups.deleted', 0)
            ->whereNull('messages.deleted')
            ->whereIn('messages.type', [Message::TYPE_OFFER, Message::TYPE_WANTED])
            ->orderBy('messages_groups.arrival', 'asc');

        // Only get messages after the last digest.
        if ($tracker->lastmsgdate) {
            $query->where('messages_groups.arrival', '>', $tracker->lastmsgdate);
        } else {
            // First digest - only get messages from the last 24 hours.
            $query->where('messages_groups.arrival', '>=', now()->subDay());
        }

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
        $query->whereRaw(
            "NOT EXISTS (SELECT 1 FROM rippling_reach rrh
                WHERE rrh.msgid = messages.id AND rrh.status = 'held')"
        );

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
                $inSql = ' AND rr.msgid NOT IN (' . implode(',', array_fill(0, count($containing), '?')) . ')';
                $inParams = $containing;
            }
            // Exclude a post when a reach row exists, the member is not in
            // its containment list, and no ring rescues them.
            // keep-raw: correlated NOT EXISTS with a spliced ringRescue fragment
            // (ringRescueIds returns SQL text) - the builder cannot compose
            // another service's fragment.
            $query->whereRaw(
                "NOT EXISTS (SELECT 1 FROM rippling_reach rr
                    WHERE rr.msgid = messages.id$inSql$ringRescue)",
                array_merge($inParams, $ringParams)
            );
        }

        // Bound the load (see DIGEST_LOAD_CAP): oldest-first + this limit means a member who
        // is far behind drains their backlog in DIGEST_LOAD_CAP-sized batches across successive
        // runs (updateDigestTracker advances the cursor past exactly what is returned here)
        // instead of loading days of posts at once and exhausting memory. Normal daily volume
        // is well under the cap, so steady-state digests are unchanged.
        return $query->with($this->digestPostEagerLoads())->limit(self::DIGEST_LOAD_CAP)->get();
    }

    /**
     * Open pinned posts (paid bulk-offer clearances) on any of the recipient's approved groups,
     * to be force-included at the TOP of their daily digest.
     *
     * "Open" mirrors getPostsForUser: Approved on the group, not deleted, an Offer/Wanted, and
     * with NO outcome (Taken/Received/Withdrawn/Expired). Deliberately NOT window-limited and NOT
     * reach-gated, so a pinned post recurs in every daily digest until it closes. Inert (returns
     * empty) until the messages_pinned table exists, so it can never break digests before the
     * migration has run.
     *
     * @return Collection of Message (each with ->groupid, ->arrival, and has_outcome/has_success=0)
     */
    private function getPinnedOpenPostsForUser(User $user): Collection
    {
        if ($this->messagesPinnedTableExists === null) {
            $this->messagesPinnedTableExists = Schema::hasTable('messages_pinned');
        }
        if (!$this->messagesPinnedTableExists) {
            return collect();
        }

        $groupIds = $user->memberships()
            ->where('collection', Membership::COLLECTION_APPROVED)
            ->pluck('groupid');

        if ($groupIds->isEmpty()) {
            return collect();
        }

        return Message::select('messages.*', 'messages_groups.groupid', 'messages_groups.arrival')
            // Live posts only: no outcome (so has_outcome/has_success are constant 0 — the caller
            // treats these as available, matching the flags getPostsForUser computes).
            ->selectRaw('0 AS has_outcome')
            ->selectRaw('0 AS has_success')
            ->selectRaw("(SELECT COALESCE(SUM(ml.count),0) FROM messages_likes ml WHERE ml.msgid = messages.id AND ml.type = 'View') AS views")
            ->selectRaw("(SELECT COUNT(*) FROM chat_messages cm WHERE cm.refmsgid = messages.id AND cm.type = 'Interested' AND cm.reviewrejected = 0 AND cm.reviewrequired = 0) AS replies")
            ->join('messages_groups', 'messages.id', '=', 'messages_groups.msgid')
            ->join('messages_pinned', 'messages_pinned.msgid', '=', 'messages.id')
            ->whereIn('messages_groups.groupid', $groupIds)
            ->where('messages_groups.collection', MessageGroup::COLLECTION_APPROVED)
            ->where('messages_groups.deleted', 0)
            ->whereNull('messages.deleted')
            ->whereIn('messages.type', [Message::TYPE_OFFER, Message::TYPE_WANTED])
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('messages_outcomes')
                    ->whereColumn('messages_outcomes.msgid', 'messages.id');
            })
            ->orderBy('messages_groups.arrival', 'desc')
            ->with($this->digestPostEagerLoads())
            ->get();
    }

    /**
     * Resolve a member's point as settings.mylocation (both coords) else their lastlocation —
     * the same order the immediate-mail recipient query uses, so the digest, the push and the
     * immediate path all agree on where a member is. Returns [lat, lng] or null if unknown.
     *
     * @return array{0:float,1:float}|null
     */
    private function resolveUserLatLng(User $user): ?array
    {
        $settings = $user->settings;
        if (is_string($settings)) {
            $settings = json_decode($settings, true) ?: [];
        }
        $myloc = is_array($settings) ? ($settings['mylocation'] ?? null) : null;
        if (is_array($myloc) && isset($myloc['lat'], $myloc['lng']) && $myloc['lat'] !== null && $myloc['lng'] !== null) {
            return [(float) $myloc['lat'], (float) $myloc['lng']];
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
     * @param array{0:float,1:float}|null $recipientLatLng Recipient's resolved point.
     * @param mixed $lat Post/message latitude (numeric or null).
     * @param mixed $lng Post/message longitude (numeric or null).
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

        if (!config('freegle.ripple.distance_filter.enabled', true)) {
            return true;
        }

        if ($recipientLatLng === null || $lat === null || $lng === null) {
            // Fail open: matches the existing reach-gate/scorer precedent of
            // "skip when we can't resolve — no regression for locationless members".
            return true;
        }

        $filter = app(DistancePreferenceFilter::class);
        // INBOUND cap: the recipient only wants posts within their chosen distance.
        // OUTBOUND cap: the post author only wants their post shown to people within
        // their chosen distance of it (the same setting, read from the author).
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

        return $filter->passesBothPreferences($distanceMiles, $recipientMax, $authorMax, false);
    }

    /**
     * The post author's OUTBOUND distance cap in miles (settings.browseMaxDistance),
     * memoised per author id so repeated posts by the same freegler — across
     * recipients and groups within a run — cost a single lookup. Absent author or
     * absent/sentinel setting resolves to DISTANCE_UNLIMITED (no outbound cap).
     *
     * Uses authorMaxDistanceMiles, NOT maxDistanceMiles: the latter falls back to the
     * member's density band default, which describes how far THEY would travel to collect
     * and must never become a cap on how far their own posts may go.
     */
    private array $authorMaxMilesCache = [];

    private function authorMaxMiles(int $fromuser): float
    {
        if (!array_key_exists($fromuser, $this->authorMaxMilesCache)) {
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
            'SELECT rr.lng AS ox, rr.lat AS oy, rr.polygon_cells AS cells FROM rippling_reach rr WHERE rr.msgid = ?',
            [$msgid]
        );

        return $this->reachRadiusCache[$msgid] = $this->reachRadiusFromRow($row, $default);
    }

    /**
     * One row's reach radius: the stored cell grid (a streaming walk of its
     * run endpoints - see CellSetService::maxDistanceMetresFrom), the
     * configured default when it cannot say.
     */
    private function reachRadiusFromRow(?object $row, float $default): float
    {
        if (!$row) {
            return $default;
        }
        if (($row->cells ?? null) !== null && $row->cells !== '') {
            $metres = app(\App\Services\Ripple\CellSetService::class)
                ->maxDistanceMetresFrom($row->cells, (float) $row->ox, (float) $row->oy);
            if ($metres !== null && $metres > 0) {
                return $metres;
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
            if (!array_key_exists($mid, $this->reachRadiusCache)) {
                $ids[$mid] = true;
            }
        }
        if (empty($ids)) {
            return;
        }
        $ids = array_keys($ids);

        foreach (array_chunk($ids, 500) as $chunk) {
            $rows = DB::table('rippling_reach')
                ->select('msgid', 'lng as ox', 'lat as oy', 'polygon_cells as cells')
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
            if (!array_key_exists($mid, $this->reachRadiusCache)) {
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
        ];

        // Load every candidate post's reach radius in ONE query rather than one
        // round-trip per post (the daily digest is DB-round-trip-bound).
        $this->primeReachRadiusCache($posts);

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
            $s = $scorer->score(
                $dist,
                $reach,
                $ageH,
                (int) ($post->views ?? 0),
                (int) ($post->replies ?? 0),
                false, // anchor/home-group not yet implemented; see /rippling (digest_simulator.go homeGroups). Default weight 0.
                $weights,
                $env
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
     * Deduplicate posts that are cross-posted to multiple groups.
     *
     * Two posts are considered duplicates when ALL of the following match:
     * - Same fromuser
     * - Same item name (from subject)
     * - Same location
     * - Posted within 7 days of each other
     * - Same tnpostid (if present) - definitive match for TN cross-posts
     *
     * @param Collection $posts
     * @return Collection Collection of deduplicated posts with 'groups' array
     */
    public function deduplicatePosts(Collection $posts): Collection
    {
        $deduplicated = collect();
        // key => LIST of entry indices sharing that dedup key. A single key can hold several
        // distinct-body items (e.g. the same poster reposts one item with slightly reworded body,
        // or posts two genuinely different things at one location under the same subject), so we
        // must keep every distinct-body representative — not just the first. Keying on only the
        // first meant the SECOND item's cross-post/ripple copies kept failing bodiesMatch against
        // the wrong representative and each got pushed as its own card, so a reposted item that
        // rippled into N groups showed N times in the digest (Discourse #9850: linda_rowlands' bed
        // 10x — one repost collapsed, the reworded repost's 10 rippled copies did not).
        $processed = [];

        foreach ($posts as $post) {
            $key = $this->getDeduplicationKey($post);
            $merged = false;

            // Merge into the first same-key representative whose body matches (true duplicate,
            // incl. every cross-post/ripple copy of the same message). bodiesMatch still keeps two
            // genuinely different items sharing a subject+location apart.
            foreach ($processed[$key] ?? [] as $existingIndex) {
                $existing = $deduplicated[$existingIndex];
                if ($this->bodiesMatch($existing['message'], $post)) {
                    $existing['postedToGroups'][] = $post->groupid;
                    $deduplicated[$existingIndex] = $existing;
                    $merged = true;
                    break;
                }
            }

            if (!$merged) {
                // No body-matching representative yet — a new distinct post. Register it under the
                // key so its OWN later copies collapse into it (the fix: previously only the very
                // first post per key was ever a merge target).
                $index = $deduplicated->count();
                $deduplicated->push([
                    'message' => $post,
                    'postedToGroups' => [$post->groupid],
                ]);
                $processed[$key][] = $index;
            }
        }

        return $deduplicated;
    }

    /**
     * Deduplicate the "came and went" (Taken/Received) posts.
     *
     * The greyed daily came-and-went section renders Message objects, but it
     * must collapse cross-posted items exactly the way the live section does.
     * The previous ->unique('id') only caught the *same* msgid (one message on
     * several groups); it left an item that was cross-posted as separate
     * messages (different msgids, but the same tnpostid or
     * fromuser+subject+location) showing once per group. Reuse deduplicatePosts()
     * so the decision is identical, then take the representative message of each
     * deduplicated group.
     *
     * @param Collection $posts Message objects (each with a ->groupid).
     * @return Collection of Message
     */
    public function deduplicateCompletedPosts(Collection $posts): Collection
    {
        // Resolve groupid -> Group from the loaded ->groups of the input posts so
        // we can reflect the merged cross-post groups back onto each
        // representative — the came-and-went card reads ->groups for its byline.
        $groupModels = [];
        foreach ($posts as $post) {
            if ($post->relationLoaded('groups')) {
                foreach ($post->groups as $group) {
                    $groupModels[$group->id] = $group;
                }
            }
        }

        return $this->deduplicatePosts($posts)->map(function ($deduped) use ($groupModels) {
            $message = $deduped['message'];

            // Show every group the item was posted to — parity with the live
            // section's merged "Posted to: A, B" — by overriding the
            // representative's ->groups with the union deduplicatePosts() built.
            $merged = collect($deduped['postedToGroups'])->unique()
                ->map(fn ($gid) => $groupModels[$gid] ?? null)
                ->filter()
                ->values();
            if ($merged->isNotEmpty()) {
                $message->setRelation('groups', $merged);
            }

            return $message;
        })->values();
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
     *
     * @param Message $message
     * @return string
     */
    protected function getDeduplicationKey(Message $message): string
    {
        // Always key on CONTENT (fromuser + normalized subject + location), never
        // tnpostid. A TrashNothing item re-posted / re-crossposted on different days
        // gets a NEW tnpostid each time, so a "tn:{id}" key produced a distinct key
        // per posting and the daily digest showed the same item N times while the
        // website (which dedups by content) showed one (Neville Reid, Discourse
        // 9808/#233 — "Small lamp" 4x; 27 such items in 4 days). bodiesMatch() still
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
     *
     * @param string $subject
     * @return string
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
     *
     * @param UserDigest $tracker
     * @param Collection $posts
     */
    protected function updateDigestTracker(UserDigest $tracker, Collection $posts, bool $emailWasSent = false): void
    {
        $lastPost = $posts->last();

        if ($lastPost) {
            $tracker->update([
                'lastmsgid' => $lastPost->id,
                'lastmsgdate' => $lastPost->arrival,
                'lastsent' => now(),
            ]);
        } elseif ($emailWasSent) {
            // A daily email WAS sent but there are no cursor posts to advance past —
            // the digest contained only a pinned post, which is never part of the
            // cursor set (see the pinned-post block in sendDigest). Stamp lastsent
            // anyway so the once-per-London-day guard skips this user on the next tick.
            // Without this, a pinned-only digest re-sends every minute (incident
            // 2026-07-05: the once-per-day guard never fired, so 67 members received the
            // daily digest up to ~198 times).
            $tracker->update(['lastsent' => now()]);
        }
    }

    /**
     * Format the "Posted to" text for display.
     *
     * @param array $groupIds
     * @return string
     */
    public function formatPostedTo(array $groupIds): string
    {
        if (count($groupIds) <= 1) {
            return '';
        }

        $groupNames = DB::table('groups')
            ->whereIn('id', $groupIds)
            ->pluck('nameshort');

        return 'Posted to: ' . $groupNames->implode(', ');
    }

    /**
     * Get active sponsors for a user's groups, deduplicated.
     *
     * A sponsor like Essex County Council may sponsor multiple groups in
     * the same area. In a unified digest, we show each sponsor once
     * (the highest-amount entry wins for ordering) rather than repeating
     * them per group.
     *
     * @param User $user
     * @return Collection Deduplicated sponsor records
     */
    public function getSponsorsForUser(User $user): Collection
    {
        $groupIds = $user->memberships()
            ->where('collection', Membership::COLLECTION_APPROVED)
            ->pluck('groupid');

        if ($groupIds->isEmpty()) {
            return collect();
        }

        // Fetch all active, visible sponsors for the user's groups.
        $sponsors = DB::table('groups_sponsorship')
            ->whereIn('groupid', $groupIds)
            ->where('visible', TRUE)
            ->where('startdate', '<=', now())
            ->where('enddate', '>=', now()->startOfDay())
            ->orderByDesc('amount')
            ->get();

        // Deduplicate by name — same sponsor across multiple groups
        // appears once. Keep the entry with the highest amount (first
        // in the result set due to ORDER BY amount DESC).
        return $sponsors->unique('name')->values();
    }

    /**
     * Active, visible sponsors for a SINGLE group.
     *
     * V1 parity for the immediate digest: an immediate email is about one
     * group's post, so it must carry only that group's sponsors — not the
     * union across every group the recipient belongs to (which is what
     * {@see getSponsorsForUser} returns for the combined daily digest). No
     * name-dedupe here: a single group can't list the same sponsor twice in a
     * way that needs collapsing, and dropping the dedupe keeps the query cheap.
     */
    public function getSponsorsForGroup(int $groupId): Collection
    {
        if ($groupId <= 0) {
            return collect();
        }

        return DB::table('groups_sponsorship')
            ->where('groupid', $groupId)
            ->where('visible', TRUE)
            ->where('startdate', '<=', now())
            ->where('enddate', '>=', now()->startOfDay())
            ->orderByDesc('amount')
            ->get();
    }

}