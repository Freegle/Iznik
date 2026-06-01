<?php

namespace App\Services;

use App\Mail\Digest\UnifiedDigest;
use App\Mail\Traits\FeatureFlags;
use App\Models\Group;
use App\Models\GroupDigest;
use App\Models\Membership;
use App\Models\Message;
use App\Models\MessageGroup;
use App\Models\User;
use App\Models\UserDigest;
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

    /**
     * Digest mode constants.
     */
    public const MODE_IMMEDIATE = 'immediate';
    public const MODE_DAILY = 'daily';
    public const MODE_GROUP = 'group';

    /**
     * Send unified digests to users who want them.
     *
     * @param string $mode One of MODE_IMMEDIATE or MODE_DAILY
     * @param int|null $userId Specific user ID to process (for testing)
     * @return array Statistics about the operation
     */
    public function sendDigests(string $mode, ?int $userId = null, ?int $limit = null, bool $dryRun = false, ?int $groupId = null, int $shard = 0, int $shards = 1): array
    {
        if ($mode === self::MODE_IMMEDIATE) {
            return $this->sendImmediateDigests($limit, $dryRun, $groupId, $userId, $shard, $shards);
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

        $users = $this->getUsersForDigest($mode, $userId);

        if ($limit) {
            $users = $users->take($limit);
        }

        foreach ($users as $user) {
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
    public function sendImmediateDigests(?int $groupLimit = null, bool $dryRun = false, ?int $groupId = null, ?int $userId = null, int $shard = 0, int $shards = 1): array
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

        $query = DB::table('groups_digests as gd')
            ->where('gd.frequency', Membership::EMAIL_FREQUENCY_IMMEDIATE)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))->from('memberships')
                    ->whereColumn('memberships.groupid', 'gd.groupid')
                    ->where('memberships.emailfrequency', Membership::EMAIL_FREQUENCY_IMMEDIATE)
                    ->where('memberships.collection', Membership::COLLECTION_APPROVED);
            })
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
            return ['emails' => 0, 'users' => []];
        }

        // Recipients: members at emailfrequency=-1, active in the last 90 days,
        // plus allowlist gate. NULL lastaccess (new users who have never logged
        // in) are included; users whose lastaccess is older than 90 days are
        // excluded to prevent long-inactive accounts from receiving per-post
        // emails (matches the daily-digest eligibility threshold).
        $memberQuery = DB::table('memberships')
            ->join('users', 'users.id', '=', 'memberships.userid')
            ->where('memberships.groupid', $groupid)
            ->where('memberships.emailfrequency', Membership::EMAIL_FREQUENCY_IMMEDIATE)
            ->where('memberships.collection', Membership::COLLECTION_APPROVED)
            ->whereNull('users.deleted')
            ->where(function ($q) {
                $q->whereNull('users.lastaccess')
                  ->orWhere('users.lastaccess', '>', now()->subDays(90));
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
                if ((int) $message->fromuser === (int) $uid) {
                    continue;
                }
                if (!$user->email_preferred) {
                    continue;
                }
                if (!$dryRun) {
                    if ($sponsorsCache === null) {
                        $sponsorsCache = $this->getSponsorsForUser($user);
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
                    try {
                        app(\App\Services\EmailSpoolerService::class)->spool(
                            new UnifiedDigest($user, $deduped, self::MODE_IMMEDIATE, $sponsorsCache),
                            $user->email_preferred,
                            emailType: 'digest_immediate',
                        );
                    } catch (\Throwable $e) {
                        Log::warning('Skipping immediate digest recipient after spool failure; continuing loop', [
                            'user_id' => $uid,
                            'email' => $user->email_preferred,
                            'group' => $groupid,
                            'msgid' => (int) $message->mg_msgid,
                            'error' => $e->getMessage(),
                        ]);
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
            ->with(['attachments', 'fromUser', 'groups'])
            ->get();
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
     * Get users who should receive digests based on mode.
     *
     * @param string $mode One of MODE_IMMEDIATE or MODE_DAILY
     * @param int|null $userId Specific user ID to process
     * @return \Illuminate\Support\LazyCollection
     */
    protected function getUsersForDigest(string $mode, ?int $userId = null): \Illuminate\Support\LazyCollection
    {
        $query = User::query()
            ->whereNull('deleted')
            ->whereNotNull('lastaccess')
            ->where('lastaccess', '>', now()->subDays(90)); // Active in last 90 days.

        if ($userId) {
            $query->where('id', $userId);
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
        $freq = $mode === self::MODE_IMMEDIATE
            ? Membership::EMAIL_FREQUENCY_IMMEDIATE
            : Membership::EMAIL_FREQUENCY_DAILY;
        $query->whereExists(function ($subquery) use ($freq) {
            $subquery->select(DB::raw(1))
                ->from('memberships')
                ->whereColumn('memberships.userid', 'users.id')
                ->where('memberships.emailfrequency', $freq)
                ->where('memberships.collection', Membership::COLLECTION_APPROVED);
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

        // Get all posts from user's groups since last digest.
        $posts = $this->getPostsForUser($user, $digestTracker, $mode);

        if ($posts->isEmpty()) {
            return ['status' => 'no_posts', 'count' => 0];
        }

        // Deduplicate cross-posted items.
        $deduplicatedPosts = $this->deduplicatePosts($posts);

        // Filter out user's own posts.
        $deduplicatedPosts = $deduplicatedPosts->filter(fn($post) => $post['message']->fromuser !== $user->id)->values();

        if ($deduplicatedPosts->isEmpty()) {
            // Nothing to send, but still advance the tracker past these posts
            // so the next tick doesn't re-fetch and re-filter the same set.
            if (!$dryRun) {
                $this->updateDigestTracker($digestTracker, $posts);
            }
            return ['status' => 'no_posts', 'count' => 0];
        }

        // Get deduplicated sponsors for the user's groups.
        $sponsors = $this->getSponsorsForUser($user);

        if ($mode === self::MODE_IMMEDIATE) {
            // One email per post. Advance the tracker after each send so a
            // mid-loop crash doesn't cause us to re-mail already-sent posts
            // on the next cron tick.
            $sent = 0;
            foreach ($deduplicatedPosts as $deduped) {
                if (!$dryRun) {
                    app(\App\Services\EmailSpoolerService::class)->spool(
                        new UnifiedDigest($user, collect([$deduped]), $mode, $sponsors),
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

        // Daily mode: one rolled-up digest covers everything.
        if (!$dryRun) {
            app(\App\Services\EmailSpoolerService::class)->spool(
                new UnifiedDigest($user, $deduplicatedPosts, $mode, $sponsors),
                emailType: 'digest_daily',
            );
            $this->updateDigestTracker($digestTracker, $posts);
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
    protected function getPostsForUser(User $user, UserDigest $tracker, string $mode): Collection
    {
        $freq = $mode === self::MODE_IMMEDIATE
            ? Membership::EMAIL_FREQUENCY_IMMEDIATE
            : Membership::EMAIL_FREQUENCY_DAILY;

        $groupIds = $user->memberships()
            ->where('collection', Membership::COLLECTION_APPROVED)
            ->where('emailfrequency', $freq)
            ->pluck('groupid');

        if ($groupIds->isEmpty()) {
            return collect();
        }

        $query = Message::select('messages.*', 'messages_groups.groupid', 'messages_groups.arrival')
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

        return $query->with(['attachments', 'fromUser', 'groups'])->get();
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
        // If we have a TrashNothing post ID, use it - it's definitive.
        if ($message->tnpostid) {
            return "tn:{$message->tnpostid}";
        }

        // Otherwise, combine fromuser + normalized subject + location.
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
     * Send per-group digests to members subscribed at a given frequency.
     *
     * Unlike the user-centric sendDigests(), this sends one email per member
     * containing only posts from the given group, using the GroupDigest tracker
     * to track progress per-group rather than per-user.
     *
     * @param Group $group The group to digest.
     * @param int $frequency emailfrequency value (e.g. 1 = hourly, 24 = daily).
     * @param bool $dryRun If true, count would-be emails but do not send.
     * @return array Statistics: emails_sent, members_processed, errors.
     */
    public function sendGroupDigests(Group $group, int $frequency, bool $dryRun = false): array
    {
        $stats = ['emails_sent' => 0, 'members_processed' => 0, 'errors' => 0];

        // Skip closed groups.
        $settings = is_array($group->settings) ? $group->settings : json_decode($group->settings ?? '{}', true);
        if (!empty($settings['closed'])) {
            return $stats;
        }

        $tracker = GroupDigest::where('groupid', $group->id)->where('frequency', $frequency)->first();
        $posts   = $this->getPostsForGroup($group, $tracker);

        if ($posts->isEmpty()) {
            return $stats;
        }

        $members = $this->getMembersForGroup($group, $frequency);

        foreach ($members as $member) {
            $stats['members_processed']++;

            try {
                $memberPosts = $posts->filter(fn($post) => $post->fromuser !== $member->userid);

                if ($memberPosts->isEmpty()) {
                    continue;
                }

                $user = User::find($member->userid);

                if (!$user || !$user->email_preferred) {
                    continue;
                }

                $wrappedPosts = $memberPosts->values()->map(fn($post) => [
                    'message'       => $post,
                    'postedToGroups' => [$group->id],
                ]);

                if (!$dryRun) {
                    app(\App\Services\EmailSpoolerService::class)->spool(
                        new UnifiedDigest($user, $wrappedPosts, self::MODE_GROUP, collect(), $frequency),
                        $user->email_preferred,
                        emailType: 'digest_group',
                    );
                }

                $stats['emails_sent']++;
            } catch (\Throwable $e) {
                // One member's failure must not abort the whole group's digest.
                Log::error("UnifiedDigestService: failed to send group digest to user {$member->userid}", [
                    'group'     => $group->id,
                    'frequency' => $frequency,
                    'error'     => $e->getMessage(),
                ]);
                $stats['errors']++;
            }
        }

        if (!$dryRun) {
            $lastPost = $posts->last();
            GroupDigest::updateOrCreate(
                ['groupid' => $group->id, 'frequency' => $frequency],
                [
                    'msgid'   => $lastPost->id,
                    'msgdate' => $lastPost->arrival,
                    'started' => now(),
                    'ended'   => now(),
                ]
            );
        }

        return $stats;
    }

    /**
     * Get approved members of a group subscribed at the given emailfrequency.
     */
    protected function getMembersForGroup(Group $group, int $frequency): Collection
    {
        return Membership::where('groupid', $group->id)
            ->where('emailfrequency', $frequency)
            ->where('collection', Membership::COLLECTION_APPROVED)
            ->get();
    }

    /**
     * Get approved messages posted to a group since the last digest.
     */
    protected function getPostsForGroup(Group $group, ?GroupDigest $tracker = null): Collection
    {
        $query = Message::select('messages.*', 'messages_groups.arrival')
            ->join('messages_groups', 'messages.id', '=', 'messages_groups.msgid')
            ->where('messages_groups.groupid', $group->id)
            ->where('messages_groups.collection', MessageGroup::COLLECTION_APPROVED)
            ->where('messages_groups.deleted', 0)
            ->whereNull('messages.deleted')
            ->whereIn('messages.type', [Message::TYPE_OFFER, Message::TYPE_WANTED])
            ->orderBy('messages_groups.arrival', 'asc');

        if ($tracker && $tracker->msgdate) {
            $query->where('messages_groups.arrival', '>', $tracker->msgdate);
        } else {
            $query->where('messages_groups.arrival', '>=', now()->subDay());
        }

        // Eager-load the poster (for the "Posted by"/From display name) and
        // attachments (for the hero/thumbnail images). Without fromUser the
        // template falls back to "Freegler"; without attachments each post
        // would lazy-load its photo one query at a time.
        return $query->with(['fromUser', 'attachments'])->get();
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

}