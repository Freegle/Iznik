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
    public function sendDigests(string $mode, ?int $userId = null, ?int $limit = null, bool $dryRun = false): array
    {
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

                // Immediate-mode can fan out into multiple emails (one per
                // post) so the per-user return is an array, not a bare string.
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

        // Filter by simple mail setting.
        if ($mode === self::MODE_IMMEDIATE) {
            // V1 parity: a user is eligible for immediate notifications if EITHER
            // the global simplemail setting is Full OR they have no global
            // simplemail set but at least one approved membership configured for
            // immediate per-group frequency (emailfrequency=-1). The two arms
            // mirror the daily/Basic case below — global setting OR per-group
            // setting when the global is unset.
            $query->where(function ($q) {
                $q->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(settings, '$.simplemail')) = ?", [User::SIMPLE_MAIL_FULL])
                    ->orWhere(function ($q2) {
                        $q2->whereRaw("JSON_EXTRACT(settings, '$.simplemail') IS NULL")
                            ->whereExists(function ($subquery) {
                                $subquery->select(DB::raw(1))
                                    ->from('memberships')
                                    ->whereColumn('memberships.userid', 'users.id')
                                    ->where('memberships.emailfrequency', Membership::EMAIL_FREQUENCY_IMMEDIATE)
                                    ->where('memberships.collection', Membership::COLLECTION_APPROVED);
                            });
                    });
            });

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
        } else {
            // Basic mode = daily digest.
            // Include users with Basic setting OR users without simplemail set but with daily frequency memberships.
            $query->where(function ($q) {
                $q->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(settings, '$.simplemail')) = ?", [User::SIMPLE_MAIL_BASIC])
                    ->orWhere(function ($q2) {
                        // Users without simplemail set who have daily frequency in at least one group.
                        $q2->whereRaw("JSON_EXTRACT(settings, '$.simplemail') IS NULL")
                            ->whereExists(function ($subquery) {
                                $subquery->select(DB::raw(1))
                                    ->from('memberships')
                                    ->whereColumn('memberships.userid', 'users.id')
                                    ->where('memberships.emailfrequency', 24)
                                    ->where('memberships.collection', Membership::COLLECTION_APPROVED);
                            });
                    });
            });
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
                    Mail::send(new UnifiedDigest($user, collect([$deduped]), $mode, $sponsors));
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
            Mail::send(new UnifiedDigest($user, $deduplicatedPosts, $mode, $sponsors));
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
                'lastmsgdate' => null,
            ]
        );
    }

    /**
     * Get all posts for a user from their member groups since last digest.
     *
     * V1 parity: which groups contribute depends on whether the user has a
     * global simplemail setting.
     *   - simplemail set (Full/Basic): the global setting governs every group
     *     — include posts from ALL approved memberships.
     *   - simplemail NULL: per-group emailfrequency decides; for immediate
     *     mode include only groups with emailfrequency=-1, for daily mode
     *     only groups with emailfrequency=24. Otherwise a user who set just
     *     one group to immediate would receive an immediate digest covering
     *     posts from all their daily-frequency groups too.
     *
     * @param User $user
     * @param UserDigest $tracker
     * @param string $mode One of MODE_IMMEDIATE or MODE_DAILY
     * @return Collection
     */
    protected function getPostsForUser(User $user, UserDigest $tracker, string $mode): Collection
    {
        $globalSimplemail = is_array($user->settings)
            ? ($user->settings['simplemail'] ?? null)
            : null;

        $membershipQuery = $user->memberships()
            ->where('collection', Membership::COLLECTION_APPROVED);

        if ($globalSimplemail === null) {
            $freq = $mode === self::MODE_IMMEDIATE
                ? Membership::EMAIL_FREQUENCY_IMMEDIATE
                : Membership::EMAIL_FREQUENCY_DAILY;
            $membershipQuery->where('emailfrequency', $freq);
        }

        $groupIds = $membershipQuery->pluck('groupid');

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
     * @return array Statistics: emails_sent, members_processed.
     */
    public function sendGroupDigests(Group $group, int $frequency, bool $dryRun = false): array
    {
        $stats = ['emails_sent' => 0, 'members_processed' => 0];

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
                Mail::send(new UnifiedDigest($user, $wrappedPosts, self::MODE_GROUP));
            }

            $stats['emails_sent']++;
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

        return $query->get();
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

    // =========================================================================
    // GROUP MODE — one email per group per user (replicates V1 per-group digest)
    // =========================================================================

    /**
     * Send group digests for a group at a specific frequency.
     *
     * Called by SendDigestCommand. Loops over members of the group who want
     * emails at this frequency and sends each one a UnifiedDigest (MODE_GROUP).
     */
    public function sendGroupDigests(Group $group, int $frequency, bool $dryRun = false): array
    {
        $stats = [
            'members_processed' => 0,
            'emails_sent' => 0,
            'errors' => 0,
        ];

        if (!self::isEmailTypeEnabled(self::EMAIL_TYPE)) {
            Log::info('UnifiedDigest emails disabled via FREEGLE_MAIL_ENABLED_TYPES');
            return $stats;
        }

        if ($group->isClosed()) {
            Log::debug("Skipping closed group: {$group->nameshort}");
            return $stats;
        }

        $groupDigest = $this->getOrCreateGroupDigest($group, $frequency);
        $messages = $this->getPostsForGroup($group, $groupDigest);

        if ($messages->isEmpty()) {
            Log::debug("No new messages for {$group->nameshort} at frequency {$frequency}");
            return $stats;
        }

        $members = $this->getMembersForGroup($group, $frequency);

        foreach ($members as $membership) {
            try {
                $user = $membership->user;

                if (!$user || !$user->email_preferred) {
                    continue;
                }

                // Filter out messages posted by this user.
                $userMessages = $messages->filter(fn($msg) => $msg->fromuser !== $user->id);

                if ($userMessages->isEmpty()) {
                    continue;
                }

                // Wrap messages in the post-array format UnifiedDigest expects.
                $posts = $userMessages->values()->map(fn($msg) => [
                    'message'        => $msg,
                    'postedToGroups' => [$group->id],
                ]);

                if (!$dryRun) {
                    $sponsors = $this->getSponsorsForGroup($group);
                    Mail::send(new UnifiedDigest($user, $posts, self::MODE_GROUP, $sponsors));
                }

                $stats['emails_sent']++;
            } catch (\Exception $e) {
                Log::error("UnifiedDigestService: Failed to send group digest to user {$membership->userid}", [
                    'group' => $group->id,
                    'error' => $e->getMessage(),
                ]);
                $stats['errors']++;
            }

            $stats['members_processed']++;
        }

        if (!$dryRun) {
            $this->updateGroupDigestTracker($groupDigest, $messages);
        }

        Log::info("UnifiedDigestService: Group digest complete for {$group->nameshort}", $stats);

        return $stats;
    }

    /**
     * Get or create a GroupDigest tracker for group+frequency.
     */
    protected function getOrCreateGroupDigest(Group $group, int $frequency): GroupDigest
    {
        return GroupDigest::firstOrCreate(
            [
                'groupid'   => $group->id,
                'frequency' => $frequency,
            ],
            [
                'msgid'    => null,
                'msgdate'  => null,
            ]
        );
    }

    /**
     * Get approved messages for a group since the last group digest.
     */
    protected function getPostsForGroup(Group $group, GroupDigest $tracker): Collection
    {
        $query = Message::select('messages.*', 'messages_groups.arrival')
            ->join('messages_groups', 'messages.id', '=', 'messages_groups.msgid')
            ->where('messages_groups.groupid', $group->id)
            ->where('messages_groups.collection', MessageGroup::COLLECTION_APPROVED)
            ->where('messages_groups.deleted', 0)
            ->whereNull('messages.deleted')
            ->whereIn('messages.type', [Message::TYPE_OFFER, Message::TYPE_WANTED])
            ->where('messages_groups.arrival', '>=', now()->subDays(30))
            ->orderBy('messages_groups.arrival', 'asc');

        if ($tracker->msgdate) {
            $query->where('messages_groups.arrival', '>', $tracker->msgdate);
        } else {
            // First digest for this group/frequency — cap at 24 hours.
            $query->where('messages_groups.arrival', '>=', now()->subDay());
        }

        return $query->with(['attachments', 'fromUser'])->get();
    }

    /**
     * Get approved members of a group who want digests at this frequency.
     */
    protected function getMembersForGroup(Group $group, int $frequency): Collection
    {
        return Membership::where('groupid', $group->id)
            ->where('collection', Membership::COLLECTION_APPROVED)
            ->where('emailfrequency', $frequency)
            ->with('user')
            ->get();
    }

    /**
     * Update the GroupDigest tracker after a successful send.
     */
    protected function updateGroupDigestTracker(GroupDigest $tracker, Collection $messages): void
    {
        $lastMessage = $messages->last();

        if ($lastMessage) {
            $tracker->update([
                'msgid'   => $lastMessage->id,
                'msgdate' => $lastMessage->arrival,
                'ended'   => now(),
            ]);
        }
    }

    /**
     * Get active sponsors for a single group.
     */
    public function getSponsorsForGroup(Group $group): Collection
    {
        return DB::table('groups_sponsorship')
            ->where('groupid', $group->id)
            ->where('visible', true)
            ->where('startdate', '<=', now())
            ->where('enddate', '>=', now()->startOfDay())
            ->orderByDesc('amount')
            ->get();
    }

}