<?php

namespace App\Services;

use App\Mail\Digest\UnifiedDigest;
use App\Mail\Traits\FeatureFlags;
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

                if ($result === 'sent') {
                    $stats['emails_sent']++;
                } elseif ($result === 'no_posts') {
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
     * Get users who should receive digests based on mode.
     *
     * @param string $mode One of MODE_IMMEDIATE or MODE_DAILY
     * @param int|null $userId Specific user ID to process
     * @return Collection
     */
    protected function getUsersForDigest(string $mode, ?int $userId = null): Collection
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
            // Full mode = immediate notifications.
            $query->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(settings, '$.simplemail')) = ?", [User::SIMPLE_MAIL_FULL]);
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

        return $query->with(['emails', 'memberships'])->get();
    }

    /**
     * Send a digest to a specific user.
     *
     * @param User $user
     * @param string $mode
     * @return string 'sent', 'no_posts', or 'skipped'
     */
    protected function sendDigestToUser(User $user, string $mode, bool $dryRun = false): string
    {
        $email = $user->email_preferred;

        if (!$email) {
            Log::debug("UnifiedDigestService: User {$user->id} has no email address");
            return 'skipped';
        }

        // Get or create digest tracking record.
        $digestTracker = $this->getOrCreateDigestTracker($user, $mode);

        // Get all posts from user's groups since last digest.
        $posts = $this->getPostsForUser($user, $digestTracker);

        if ($posts->isEmpty()) {
            return 'no_posts';
        }

        // Deduplicate cross-posted items.
        $deduplicatedPosts = $this->deduplicatePosts($posts);

        // Filter out user's own posts.
        $deduplicatedPosts = $deduplicatedPosts->filter(fn($post) => $post['message']->fromuser !== $user->id);

        if ($deduplicatedPosts->isEmpty()) {
            return 'no_posts';
        }

        // Get deduplicated sponsors for the user's groups.
        $sponsors = $this->getSponsorsForUser($user);

        if (!$dryRun) {
            // Send the email.
            Mail::send(new UnifiedDigest($user, $deduplicatedPosts, $mode, $sponsors));

            // Update tracker.
            $this->updateDigestTracker($digestTracker, $posts);
        }

        return 'sent';
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
     * @param User $user
     * @param UserDigest $tracker
     * @return Collection
     */
    protected function getPostsForUser(User $user, UserDigest $tracker): Collection
    {
        // Get group IDs user is a member of.
        $groupIds = $user->memberships()
            ->where('collection', Membership::COLLECTION_APPROVED)
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
