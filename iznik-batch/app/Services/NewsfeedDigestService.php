<?php

namespace App\Services;

use App\Mail\Newsfeed\NewsfeedDigestMail;
use App\Models\Group;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Send the newsfeed ("chitchat") digest to recently-active users.
 *
 * Mirrors iznik-server cron/newsfeed_digest.php + Newsfeed::digest()
 * (include/newsfeed/Newsfeed.php:806-973):
 *
 * - Iterates published, on-here, non-playground Freegle groups whose 'newsfeed'
 *   setting is on (default on), and for each approved member builds a digest of
 *   recent nearby chitchat they have not yet seen.
 * - A newsfeed_users marker (highest newsfeed id sent) prevents re-sending, so a
 *   user who belongs to several groups is only mailed once per run.
 *
 * Deviations from V1 (documented in docs/mail-newsfeed-digest.md):
 * - "Nearby" is approximated by the user's approved Freegle group areas (direct
 *   groupid or group polyindex containment), as already done by
 *   NewsfeedModNotifService, rather than a per-user lat/lng bounding box.
 * - The optional " in {locations}" subject clause is omitted.
 */
class NewsfeedDigestService
{
    /** Newsfeed post types included in the digest (V1 getFeed type list). */
    private const FEED_TYPES = ['Message', 'Story', 'AboutMe', 'Noticeboard'];

    /** Only consider posts from the last 14 days (V1 $oldest). */
    private const WINDOW_DAYS = 14;

    /** Max top-level items per digest (V1 getFeed LIMIT). */
    private const MAX_ITEMS = 5;

    /** Max replies shown per item (V1 keeps last 5). */
    private const MAX_REPLIES = 5;

    /** Minimum text length for an item to be worth including (V1 strlen > 40). */
    private const MIN_TEXT_LENGTH = 40;

    /** Snippet length for item text (V1 snip default 117). */
    private const SNIPPET_LENGTH = 117;

    /**
     * Process all eligible groups/members.
     *
     * @return int Number of digest emails sent.
     */
    public function sendDigests(bool $dryRun = false, ?int $onlyUserId = null): int
    {
        if ($onlyUserId !== null) {
            return $this->digestForUser($onlyUserId, $dryRun);
        }

        $groups = DB::table('groups')
            ->where('type', Group::TYPE_FREEGLE)
            ->where('onhere', 1)
            ->where('publish', 1)
            ->where('nameshort', 'not like', '%playground%')
            ->get(['id', 'nameshort', 'settings']);

        $sent = 0;
        $processedUsers = [];

        foreach ($groups as $group) {
            $settings = is_string($group->settings) ? json_decode($group->settings, true) : (array) $group->settings;
            // V1: $g->getSetting('newsfeed', TRUE) — default on.
            if (! ($settings['newsfeed'] ?? true)) {
                continue;
            }

            $memberIds = DB::table('memberships')
                ->where('groupid', $group->id)
                ->where('collection', Membership::COLLECTION_APPROVED)
                ->distinct()
                ->pluck('userid');

            foreach ($memberIds as $userId) {
                // The newsfeed_users marker already dedupes re-sends, but skip
                // users we've handled this run to avoid redundant work.
                if (isset($processedUsers[$userId])) {
                    continue;
                }
                $processedUsers[$userId] = true;

                $sent += $this->digestForUser((int) $userId, $dryRun);
            }
        }

        Log::info('NewsfeedDigestService: completed', ['sent' => $sent, 'dry_run' => $dryRun]);

        return $sent;
    }

    /**
     * Build and send the digest for a single user. Returns 1 if an email was
     * (or would be) sent, 0 otherwise.
     */
    public function digestForUser(int $userId, bool $dryRun = false): int
    {
        $user = User::find($userId);
        if (! $user) {
            return 0;
        }

        // V1 entry condition: sendOurMails() && email && has location &&
        // notificationmails setting on (default true).
        if (! $user->sendOurMails()) {
            return 0;
        }

        $email = $user->email_preferred;
        if (! $email) {
            return 0;
        }

        [$lat, $lng] = $user->getLatLng();
        if (! $lat && ! $lng) {
            return 0;
        }

        $settings = $user->settings ?? [];
        if (! ($settings['notificationmails'] ?? true)) {
            return 0;
        }

        $lastSeen = (int) (DB::table('newsfeed_users')->where('userid', $userId)->value('newsfeedid') ?? 0);

        $groupIds = DB::table('memberships')
            ->join('groups', 'groups.id', '=', 'memberships.groupid')
            ->where('memberships.userid', $userId)
            ->where('memberships.collection', Membership::COLLECTION_APPROVED)
            ->where('groups.type', Group::TYPE_FREEGLE)
            ->pluck('memberships.groupid')
            ->toArray();

        if (empty($groupIds)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
        $oldest = now()->subDays(self::WINDOW_DAYS)->toDateTimeString();

        $typePlaceholders = implode(',', array_fill(0, count(self::FEED_TYPES), '?'));

        $posts = DB::select(
            "SELECT DISTINCT newsfeed.id, newsfeed.type, newsfeed.userid, newsfeed.message, newsfeed.timestamp
             FROM newsfeed
             INNER JOIN `groups` ON (
                 newsfeed.groupid = groups.id
                 OR (newsfeed.position IS NOT NULL AND groups.polyindex IS NOT NULL
                     AND MBRContains(groups.polyindex, newsfeed.position))
             )
             WHERE groups.id IN ({$placeholders})
               AND newsfeed.timestamp >= ?
               AND newsfeed.id > ?
               AND newsfeed.deleted IS NULL
               AND newsfeed.hidden IS NULL
               AND newsfeed.replyto IS NULL
               AND newsfeed.type IN ({$typePlaceholders})
               AND newsfeed.userid != ?
             ORDER BY newsfeed.timestamp DESC
             LIMIT " . self::MAX_ITEMS,
            array_merge($groupIds, [$oldest, $lastSeen], self::FEED_TYPES, [$userId])
        );

        if (empty($posts)) {
            return 0;
        }

        $items = [];
        $maxId = $lastSeen;

        foreach ($posts as $post) {
            $maxId = max($maxId, (int) $post->id);

            $text = $this->formatItemText($post);
            if ($text === null || mb_strlen($text) <= self::MIN_TEXT_LENGTH) {
                continue;
            }

            $items[] = [
                'type' => $post->type,
                'text' => $this->snip($text),
                'author' => $this->userName((int) $post->userid),
                'replies' => $this->repliesFor((int) $post->id),
            ];
        }

        if (empty($items)) {
            // Still advance the marker so we don't re-scan the same too-short
            // posts every run (V1 REPLACEs the marker whenever max > lastseen).
            if (! $dryRun && $maxId > $lastSeen) {
                DB::table('newsfeed_users')->updateOrInsert(['userid' => $userId], ['newsfeedid' => $maxId]);
            }
            return 0;
        }

        if (! $dryRun) {
            $snippet = $this->snip(strip_tags($items[0]['text']), self::MIN_TEXT_LENGTH);

            app(EmailSpoolerService::class)->spool(
                new NewsfeedDigestMail($user, $email, $items, $snippet),
                $email,
                'newsfeed'
            );

            DB::table('newsfeed_users')->updateOrInsert(['userid' => $userId], ['newsfeedid' => $maxId]);
        }

        return 1;
    }

    /**
     * Format a top-level item's text by type, matching V1's per-type wrapping.
     */
    private function formatItemText(object $post): ?string
    {
        $message = trim((string) ($post->message ?? ''));

        switch ($post->type) {
            case 'AboutMe':
                return $message === '' ? null : '"' . $message . '"';

            case 'Noticeboard':
                $decoded = json_decode($message, true);
                $name = is_array($decoded) ? ($decoded['name'] ?? '') : '';
                return $name === '' ? null : 'I put up a poster for Freegle: "' . $name . '"';

            case 'Story':
                return $message === '' ? null : "Here's my Freegle story: " . $message;

            default: // Message
                return $message === '' ? null : $message;
        }
    }

    /**
     * Fetch up to MAX_REPLIES recent reply texts for a newsfeed item.
     *
     * @return list<array{author: string, text: string}>
     */
    private function repliesFor(int $newsfeedId): array
    {
        $rows = DB::table('newsfeed')
            ->where('replyto', $newsfeedId)
            ->whereNull('deleted')
            ->whereNull('hidden')
            ->whereNotNull('message')
            ->orderBy('id', 'desc')
            ->limit(self::MAX_REPLIES)
            ->get(['userid', 'message']);

        return $rows->reverse()->values()->map(fn ($r) => [
            'author' => $this->userName((int) $r->userid),
            'text' => $this->snip(trim((string) $r->message), self::MIN_TEXT_LENGTH),
        ])->filter(fn ($r) => $r['text'] !== '')->values()->all();
    }

    private function userName(int $userId): string
    {
        $u = DB::table('users')->where('id', $userId)->first(['fullname', 'firstname', 'lastname']);
        if (! $u) {
            return 'A freegler';
        }
        return $u->fullname
            ?: trim(($u->firstname ?? '') . ' ' . ($u->lastname ?? ''))
            ?: 'A freegler';
    }

    private function snip(string $text, int $length = self::SNIPPET_LENGTH): string
    {
        $text = trim($text);
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        return rtrim(mb_substr($text, 0, $length)) . '…';
    }
}
