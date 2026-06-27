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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
     * Mirrors iznik-server/include/mail/Digest.php exactly: walk the V1
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

        // The EXISTS is left as a per-group correlated subquery (NO_SEMIJOIN) so it resolves via
        // the memberships(groupid,...) index and short-circuits on the first immediate member of
        // each group. Without the hint the optimiser materialises the semijoin using only the
        // `collection` index, scanning ~2.37M Approved rows (10-24s) because there is no index on
        // memberships.emailfrequency. Immediate members are ~0.7% of Approved, so the per-group
        // lookup is far cheaper. (A memberships(emailfrequency,groupid) index would make either
        // plan fast, but this needs no DDL.) groups_digests is tiny (~3k rows).
        $query = DB::table('groups_digests as gd')
            ->where('gd.frequency', Membership::EMAIL_FREQUENCY_IMMEDIATE)
            ->whereExists(function ($q) {
                $q->select(DB::raw('/*+ QB_NAME(imm_member) */ 1'))->from('memberships')
                    ->whereColumn('memberships.groupid', 'gd.groupid')
                    ->where('memberships.emailfrequency', Membership::EMAIL_FREQUENCY_IMMEDIATE)
                    ->where('memberships.collection', Membership::COLLECTION_APPROVED);
            })
            ->select(DB::raw('/*+ NO_SEMIJOIN(@imm_member) */ gd.groupid'), 'gd.msgid as cursor_msgid', 'gd.msgdate as cursor_msgdate');

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
                    // Record this send in the reach-coordination ledger whenever rippling is active
                    // in EITHER mode — the global master switch OR the scoped within-group experiment.
                    // The reach mailer (mailNewlyReachedForPost) excludes anyone already in this ledger;
                    // if the immediate (cursor) path doesn't record here, a rippled post gets mailed
                    // twice — immediate-on-arrival AND again by the reach mailer. Gating on
                    // ripple.enabled alone missed the scoped experiment (within_groups), which ran with
                    // the global flag off and double-mailed members (~8k dup emails/day; Edinburgh
                    // "Bird cherry sapling", 2026-06-27). When rippling is fully dark (no global flag
                    // and no within_groups) the reach mailer self-idles, so we skip the write then.
                    $ripplingActive = config('freegle.ripple.enabled')
                        || !empty(config('freegle.ripple.within_groups'));
                    if ($spooled && $ripplingActive) {
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
                            Log::warning('Immediate digest: reach-ledger write failed (expander may re-notify)', [
                                'user_id' => $uid,
                                'msgid' => (int) $message->mg_msgid,
                                'error' => $e->getMessage(),
                            ]);
                        }
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
     */
    public function mailNewlyReachedForPost(int $msgid, bool $dryRun = false): int
    {
        if (!self::isEmailTypeEnabled(self::EMAIL_TYPE)) {
            return 0;
        }

        try {
            $msg = Message::with(['attachments', 'fromUser', 'groups'])->find($msgid);
            if ($msg === null) {
                return 0;
            }

            $srid = (int) config('freegle.srid', 3857);
            $recipientIds = collect(DB::select(
                "SELECT DISTINCT u.id AS id
                 FROM messages_groups mg
                 JOIN rippling_reach mr ON mr.msgid = mg.msgid
                 JOIN memberships m ON m.groupid = mg.groupid
                      AND m.emailfrequency = ? AND m.collection = 'Approved'
                 JOIN users u ON u.id = m.userid
                 LEFT JOIN locations l ON l.id = u.lastlocation
                 WHERE mg.msgid = ? AND mg.collection = 'Approved' AND mg.deleted = 0
                   AND NOT EXISTS (
                         SELECT 1 FROM messages_outcomes mo
                         WHERE mo.msgid = mg.msgid AND mo.outcome IN ('Taken', 'Received')
                       )
                   AND u.deleted IS NULL AND (u.lastaccess IS NULL OR u.lastaccess > ?)
                   AND ST_Contains(mr.polygon, ST_SRID(POINT(
                         CASE WHEN JSON_EXTRACT(u.settings, '$.mylocation.lat') IS NOT NULL
                                   AND JSON_EXTRACT(u.settings, '$.mylocation.lng') IS NOT NULL
                              THEN CAST(JSON_EXTRACT(u.settings, '$.mylocation.lng') AS DECIMAL(10,6))
                              ELSE l.lng END,
                         CASE WHEN JSON_EXTRACT(u.settings, '$.mylocation.lat') IS NOT NULL
                                   AND JSON_EXTRACT(u.settings, '$.mylocation.lng') IS NOT NULL
                              THEN CAST(JSON_EXTRACT(u.settings, '$.mylocation.lat') AS DECIMAL(10,6))
                              ELSE l.lat END
                       ), ?))
                   AND NOT EXISTS (
                         SELECT 1 FROM rippling_reach_notified n WHERE n.msgid = mg.msgid AND n.userid = u.id
                       )",
                [Membership::EMAIL_FREQUENCY_IMMEDIATE, $msgid, now()->subDays(90), $srid]
            ))->pluck('id')->map(fn ($v) => (int) $v)->all();

            if (empty($recipientIds)) {
                return 0;
            }

            // Same allowlist gate as the cursor immediate digest.
            $allowlist = $this->getImmediateAllowlist();
            if ($allowlist !== ['*']) {
                $lower = array_map('strtolower', $allowlist);
                $recipientIds = DB::table('users_emails')
                    ->whereIn('userid', $recipientIds)
                    ->whereIn(DB::raw('LOWER(email)'), $lower)
                    ->pluck('userid')->unique()->map(fn ($v) => (int) $v)->all();
                if (empty($recipientIds)) {
                    return 0;
                }
            }

            $postedToGroups = DB::table('messages_groups')->where('msgid', $msgid)
                ->where('collection', MessageGroup::COLLECTION_APPROVED)->where('deleted', 0)
                ->pluck('groupid')->map(fn ($v) => (int) $v)->all();
            $sponsorsCache = !empty($postedToGroups) ? $this->getSponsorsForGroup((int) $postedToGroups[0]) : null;

            $users = User::whereIn('id', $recipientIds)->with(['emails', 'memberships'])->get();
            $sent = 0;
            foreach ($users as $user) {
                if (!$user->email_preferred) {
                    continue;
                }
                if ($dryRun) {
                    $sent++;
                    continue;
                }
                $deduped = collect([['message' => $msg, 'postedToGroups' => $postedToGroups]]);
                try {
                    app(\App\Services\EmailSpoolerService::class)->spool(
                        new UnifiedDigest($user, $deduped, self::MODE_IMMEDIATE, $sponsorsCache),
                        $user->email_preferred,
                        emailType: 'digest_immediate',
                    );
                    DB::table('rippling_reach_notified')->insertOrIgnore([
                        'msgid' => $msgid,
                        'userid' => (int) $user->id,
                        'notified_at' => now(),
                    ]);
                    $sent++;
                } catch (\Throwable $e) {
                    Log::warning('ripple: failed to spool reach immediate mail', [
                        'msgid' => $msgid, 'user_id' => $user->id, 'error' => $e->getMessage(),
                    ]);
                }
            }

            // #0 / §15 instrumentation: count immediate mails sent on expansion.
            if ($sent > 0 && !$dryRun) {
                DB::statement(
                    'INSERT INTO rippling_event_metrics (day, event, count) VALUES (CURDATE(), ?, ?) '
                    . 'ON DUPLICATE KEY UPDATE count = count + ?',
                    ['immediate_mailed', $sent, $sent]
                );
            }

            return $sent;
        } catch (\Throwable $e) {
            Log::warning('ripple: mailNewlyReachedForPost failed', ['msgid' => $msgid, 'error' => $e->getMessage()]);
            return 0;
        }
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
            ->with(['attachments', 'fromUser', 'groups'])
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
        // V1 parity (User::sendOurMails, iznik-server/include/user/User.php:4117
        // and Engage::USER_INACTIVE = 365*12*3600 = 182.5 days): the canonical
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
            $query->whereRaw('users.id % ? = ?', [$shards, $shard]);
        }

        // V1 parity (iznik-server/include/mail/Digest.php:418): per-group
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
        return $query->with(['emails', 'memberships'])->lazyById(500);
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
     * @return array{status: 'sent'|'no_posts'|'skipped', count: int}
     */
    protected function sendDigestToUser(User $user, string $mode, bool $dryRun = false): array
    {
        $email = $user->email_preferred;

        if (!$email) {
            Log::debug("UnifiedDigestService: User {$user->id} has no email address");
            return ['status' => 'skipped', 'count' => 0];
        }

        // Get or create digest tracking record.
        $digestTracker = $this->getOrCreateDigestTracker($user, $mode);

        // One query for the whole window, carrying has_outcome / has_success
        // flags; partition here rather than re-querying.
        $allPosts = $this->getPostsForUser($user, $digestTracker, $mode);

        if ($allPosts->isEmpty()) {
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
            $posts = $this->scoreAndSortAvailable($posts, $this->resolveUserLatLng($user));
        }

        $completedPosts = $mode === self::MODE_DAILY
            ? $this->deduplicateCompletedPosts($allPosts->filter(fn ($p) => $p->has_success)->values())
            : collect();

        if ($posts->isEmpty()) {
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

        if ($deduplicatedPosts->isEmpty()) {
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

        // Daily mode: one rolled-up digest. $completedPosts (the "came and
        // went" Taken/Received set) was partitioned from the same query above.
        if (!$dryRun) {
            app(\App\Services\EmailSpoolerService::class)->spool(
                new UnifiedDigest($user, $deduplicatedPosts, $mode, $sponsors, $completedPosts),
                emailType: 'digest_daily',
            );
            // Advance the cursor past everything examined this window (live,
            // completed and withdrawn) so nothing re-surfaces tomorrow.
            $this->updateDigestTracker($digestTracker, $allPosts);
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
     * V1 parity (iznik-server/include/mail/Digest.php): per-group
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
        $latlng = $this->resolveUserLatLng($user);
        if ($latlng !== null) {
            $query->whereRaw(
                'NOT EXISTS (SELECT 1 FROM rippling_reach rr WHERE rr.msgid = messages.id
                    AND ST_Contains(rr.polygon, ST_SRID(POINT(?, ?), 3857)) = 0)',
                [$latlng[1], $latlng[0]] // POINT(lng, lat)
            );
        }

        return $query->with(['attachments', 'fromUser', 'groups'])->get();
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
            'SELECT rr.lng AS ox, rr.lat AS oy, ST_AsText(rr.polygon) AS poly_wkt
               FROM rippling_reach rr WHERE rr.msgid = ?',
            [$msgid]
        );

        if (!$row || $row->poly_wkt === null) {
            return $this->reachRadiusCache[$msgid] = $default;
        }

        // Parse the WKT exterior ring in PHP and take the greatest great-circle
        // distance (metres) from the origin to any vertex. Parsing in PHP is more
        // portable than MySQL geometry functions and avoids SRID-transform issues.
        // WKT form: POLYGON((lng1 lat1,lng2 lat2,...,lng1 lat1)) — x is lng, y is lat.
        $oLng = (float) $row->ox;
        $oLat = (float) $row->oy;
        $wkt = $row->poly_wkt;

        // Extract the coordinate pairs from the exterior ring.
        if (!preg_match('/POLYGON\s*\(\s*\(([^)]+)\)/', $wkt, $m)) {
            return $this->reachRadiusCache[$msgid] = $default;
        }

        $maxDist = 0.0;
        foreach (explode(',', $m[1]) as $pair) {
            $parts = preg_split('/\s+/', trim($pair));
            if (count($parts) < 2) {
                continue;
            }
            $vLng = (float) $parts[0];
            $vLat = (float) $parts[1];
            $dist = $this->haversineMetres($oLat, $oLng, $vLat, $vLng);
            if ($dist > $maxDist) {
                $maxDist = $dist;
            }
        }

        $r = $maxDist > 0 ? $maxDist : $default;
        return $this->reachRadiusCache[$msgid] = $r;
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
            $post->_score = $s['total'];
        }

        return $posts->sortByDesc('_score')->values();
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
        $processed = [];

        foreach ($posts as $post) {
            $key = $this->getDeduplicationKey($post);

            if (isset($processed[$key])) {
                // Key matches - also check body similarity before deduplicating.
                $existingIndex = $processed[$key];
                $existing = $deduplicated[$existingIndex];

                if ($this->bodiesMatch($existing['message'], $post)) {
                    // Same key AND similar body - true duplicate, merge groups.
                    $existing['postedToGroups'][] = $post->groupid;
                    $deduplicated[$existingIndex] = $existing;
                } else {
                    // Same key but different body - treat as separate post.
                    $index = $deduplicated->count();
                    $deduplicated->push([
                        'message' => $post,
                        'postedToGroups' => [$post->groupid],
                    ]);
                }
            } else {
                // New unique post.
                $index = $deduplicated->count();
                $deduplicated->push([
                    'message' => $post,
                    'postedToGroups' => [$post->groupid],
                ]);
                $processed[$key] = $index;
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
    protected function updateDigestTracker(UserDigest $tracker, Collection $posts): void
    {
        $lastPost = $posts->last();

        if ($lastPost) {
            $tracker->update([
                'lastmsgid' => $lastPost->id,
                'lastmsgdate' => $lastPost->arrival,
                'lastsent' => now(),
            ]);
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