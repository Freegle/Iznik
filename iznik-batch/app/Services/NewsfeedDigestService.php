<?php

namespace App\Services;

use App\Mail\Newsfeed\NewsfeedDigestMail;
use App\Models\Group;
use App\Models\Membership;
use App\Models\User;
use App\Support\GreatCircle;
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
 * - "Nearby" uses the user's lat/lng and a spatial bounding box (V1
 *   getNearbyDistance + MBRContains on newsfeed.position), expanding the radius
 *   until ~10 recent posters are in range (capped at ~20 miles).
 * - A newsfeed_users marker (highest newsfeed id sent) prevents re-sending, so a
 *   user who belongs to several groups is only mailed once per run.
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

    /** Posts must be at least this many hours old (V1 digest $minhourage=12). */
    private const MIN_HOUR_AGE = 12;

    /** getNearbyDistance: start radius (m), target poster count, cap (m). V1 800 / 10 / 32187. */
    private const NEARBY_START_METRES = 800;
    private const NEARBY_TARGET_POSTERS = 10;
    private const NEARBY_MAX_METRES = 32187;

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

        $settings = $user->settings ?? [];
        if (! ($settings['notificationmails'] ?? true)) {
            return 0;
        }

        [$lat, $lng] = $this->resolveLatLng($user);
        if (! $lat && ! $lng) {
            return 0;
        }

        $lastSeen = (int) (DB::table('newsfeed_users')->where('userid', $userId)->value('newsfeedid') ?? 0);

        // V1 getNearbyDistance: grow the radius until ~10 recent posters are in
        // range (or we hit the cap), then select the latest posts within that box.
        $dist = $this->getNearbyDistance($lat, $lng);
        $box = $this->boxSql($lat, $lng, $dist);
        $oldest = now()->subDays(self::WINDOW_DAYS)->toDateTimeString();
        $typePlaceholders = implode(',', array_fill(0, count(self::FEED_TYPES), '?'));

        $posts = DB::select(
            "SELECT newsfeed.id, newsfeed.type, newsfeed.userid, newsfeed.message, newsfeed.timestamp
             FROM newsfeed
             WHERE MBRContains({$box}, newsfeed.position)
               AND newsfeed.replyto IS NULL
               AND newsfeed.deleted IS NULL
               AND newsfeed.hidden IS NULL
               AND newsfeed.userid <> ?
               AND newsfeed.type IN ({$typePlaceholders})
               AND newsfeed.timestamp >= ?
               AND TIMESTAMPDIFF(HOUR, newsfeed.timestamp, NOW()) >= ?
             ORDER BY newsfeed.pinned DESC, newsfeed.timestamp DESC
             LIMIT " . self::MAX_ITEMS,
            array_merge([$userId], self::FEED_TYPES, [$oldest, self::MIN_HOUR_AGE])
        );

        if (empty($posts)) {
            return 0;
        }

        $items = [];
        $locations = [];
        $maxId = $lastSeen;

        foreach ($posts as $post) {
            // V1 filters unseen posts in PHP (LIMIT 5 then id > lastseen).
            if ((int) $post->id <= $lastSeen) {
                continue;
            }

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

            // V1 adds each poster's public location (minus the group suffix) to
            // the subject's "in <locations>" clause.
            $loc = $this->locationFor((int) $post->userid);
            if ($loc !== null) {
                $locations[] = $loc;
            }
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
            $locations = array_values(array_unique(array_filter($locations)));

            // V1 uses auto-login (passwordless) links into the chitchat feed and
            // settings page.
            $readUrl = $user->loginLink('/chitchat', 'newsfeeddigest');
            $settingsUrl = $user->loginLink('/settings', 'newsfeeddigest');

            app(EmailSpoolerService::class)->spool(
                new NewsfeedDigestMail($user, $email, $items, $snippet, $locations, $readUrl, $settingsUrl),
                $email,
                'newsfeed'
            );

            DB::table('newsfeed_users')->updateOrInsert(['userid' => $userId], ['newsfeedid' => $maxId]);
        }

        return 1;
    }

    /**
     * V1 getLatLng(FALSE): prefer the user's saved 'mylocation' setting, then
     * their lastlocation.
     *
     * @return array{0: float|null, 1: float|null}
     */
    private function resolveLatLng(User $user): array
    {
        $settings = $user->settings ?? [];
        if (isset($settings['mylocation']['lat'], $settings['mylocation']['lng'])) {
            return [(float) $settings['mylocation']['lat'], (float) $settings['mylocation']['lng']];
        }

        [$lat, $lng] = $user->getLatLng();
        return [$lat, $lng];
    }

    /**
     * V1 Newsfeed::getNearbyDistance: start at 800m and double until at least 10
     * distinct people have posted (non-reply, non-Alert, last 30 days) within the
     * box, or we reach the cap (~20 miles).
     */
    private function getNearbyDistance(float $lat, float $lng): int
    {
        $dist = self::NEARBY_START_METRES;
        $since = now()->subDays(30)->toDateTimeString();

        do {
            $dist *= 2;
            $box = $this->boxSql($lat, $lng, $dist);

            $others = DB::select(
                "SELECT DISTINCT userid FROM newsfeed
                 WHERE MBRContains({$box}, position)
                   AND replyto IS NULL
                   AND type <> 'Alert'
                   AND timestamp >= ?
                 LIMIT " . self::NEARBY_TARGET_POSTERS,
                [$since]
            );
        } while ($dist < self::NEARBY_MAX_METRES && count($others) < self::NEARBY_TARGET_POSTERS);

        return $dist;
    }

    /**
     * Build the ST_GeomFromText POLYGON box SQL for a given centre + distance,
     * matching V1 (NE corner at bearing 45°, SW at 225°).
     */
    private function boxSql(float $lat, float $lng, int $dist): string
    {
        $ne = GreatCircle::getPositionByDistance($dist, 45, $lat, $lng);
        $sw = GreatCircle::getPositionByDistance($dist, 225, $lat, $lng);
        $srid = (int) config('freegle.srid', 3857);

        $poly = sprintf(
            'POLYGON((%F %F, %F %F, %F %F, %F %F, %F %F))',
            $sw['lng'], $sw['lat'],
            $sw['lng'], $ne['lat'],
            $ne['lng'], $ne['lat'],
            $ne['lng'], $sw['lat'],
            $sw['lng'], $sw['lat']
        );

        return "ST_GeomFromText('{$poly}', {$srid})";
    }

    /**
     * The poster's public location name with the group suffix stripped (V1
     * getPublicLocation()['display'] minus everything after the last comma).
     */
    private function locationFor(int $userId): ?string
    {
        $name = DB::table('users')
            ->join('locations', 'locations.id', '=', 'users.lastlocation')
            ->where('users.id', $userId)
            ->value('locations.name');

        if (! $name) {
            return null;
        }

        $pos = strrpos($name, ',');
        return $pos !== false ? trim(substr($name, 0, $pos)) : $name;
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
