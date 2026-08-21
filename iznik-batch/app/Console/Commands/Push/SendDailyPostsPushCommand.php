<?php

namespace App\Console\Commands\Push;

use App\Console\Concerns\PreventsOverlapping;
use App\Models\User;
use App\Models\UserDigest;
use App\Services\PushNotificationService;
use App\Services\UnifiedDigestService;
use App\Traits\GracefulShutdown;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Send the daily new-posts push notification.
 *
 * Finds eligible FD-app users (active, not opted out, with an FCM token),
 * fetches new posts since their per-user cursor (users_digests mode='push'),
 * builds a rich FCM data payload, and sends via PushNotificationService.
 *
 * DARK BY DEFAULT — sends to nobody until FREEGLE_POSTS_PUSH_ALLOWLIST is set
 * in the environment. Set to '*' to open to all eligible users, or to a
 * comma-separated list of email addresses for a pilot cohort.
 *
 * Once-per-London-day guard prevents duplicate sends if the scheduler fires
 * multiple times. An explicit --user bypasses the guard and the allowlist.
 */
class SendDailyPostsPushCommand extends Command
{
    use GracefulShutdown;
    use PreventsOverlapping;

    protected $signature = 'push:daily-posts
                            {--user=   : Restrict to one user ID (bypasses allowlist + once-per-day guard)}
                            {--limit=  : Cap the number of users processed per run}
                            {--dry-run : Show what would be sent without sending}';

    protected $description = 'Send daily new-posts push notifications to FD-app users';

    /** Digest mode identifier in users_digests. */
    private const MODE = 'push';

    public function handle(
        PushNotificationService $pushService,
        UnifiedDigestService    $digestService,
    ): int {
        if (! $this->acquireLock()) {
            $this->info('Already running, exiting.');
            return Command::SUCCESS;
        }

        try {
            return $this->doHandle($pushService, $digestService);
        } finally {
            $this->releaseLock();
        }
    }

    private function doHandle(
        PushNotificationService $pushService,
        UnifiedDigestService    $digestService,
    ): int {
        $this->registerShutdownHandlers();

        $userIdOpt = $this->option('user') !== null ? (int) $this->option('user') : null;
        $limit     = $this->option('limit')  !== null ? (int) $this->option('limit')  : null;
        $dryRun    = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no pushes will be sent.');
        }

        // ---- Allowlist gate ------------------------------------------------
        // Parse the allowlist once and keep it for the per-user check below.
        // An explicit --user bypasses the gate entirely (manual sampling).
        $allowlist = $this->resolveAllowlist();

        if ($userIdOpt === null) {
            if ($allowlist === []) {
                $this->info('Daily new-posts push disabled (FREEGLE_POSTS_PUSH_ALLOWLIST is empty). Set it to \'*\' to enable.');
                Log::info('push:daily-posts: disabled (empty allowlist)');
                return Command::SUCCESS;
            }

            if ($allowlist !== ['*']) {
                $this->info('Daily new-posts push restricted to allowlist (' . count($allowlist) . ' address(es)).');
                Log::info('push:daily-posts: restricted to allowlist', ['count' => count($allowlist)]);
            }
        }

        // ---- Once-per-London-day guard (daily mode) ------------------------
        // Mirrors UnifiedDigestService::getUsersForDigest daily-mode guard.
        // Skipped when --user is set (manual resend/sampling).
        $londonDayStartUtc = null;
        if ($userIdOpt === null) {
            $londonDayStartUtc = \Carbon\Carbon::now('Europe/London')
                ->startOfDay()
                ->setTimezone('UTC')
                ->toDateTimeString();
        }

        // ---- Eligibility query ---------------------------------------------
        // A user is eligible when:
        //   1. Not deleted, has lastaccess within the 182-day active window,
        //      not a TN import, not bouncing (same as getUsersForDigest).
        //   2. Has at least one FCM token for the User (FD) app.
        //   3. settings.notifications.dailypostspush !== false (opt-out flag;
        //      absent or true → opted in).
        $query = User::query()
            ->whereNull('deleted')
            ->whereNotNull('lastaccess')
            ->where('lastaccess', '>', now()->subSeconds(365 * 12 * 3600))
            ->whereNull('tnuserid')
            ->where('bouncing', 0)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('users_push_notifications')
                    ->whereColumn('users_push_notifications.userid', 'users.id')
                    ->where('users_push_notifications.apptype', 'User')
                    ->whereIn('users_push_notifications.type', ['FCMAndroid', 'FCMIOS']);
            });

        if ($userIdOpt !== null) {
            $query->where('id', $userIdOpt);
        }

        // Allowlist filter (only when not a manual --user run).
        if ($userIdOpt === null && $allowlist !== ['*']) {
            $lowercased = array_map('strtolower', $allowlist);
            $query->whereExists(function ($q) use ($lowercased) {
                $q->select(DB::raw(1))
                    ->from('users_emails')
                    ->whereColumn('users_emails.userid', 'users.id')
                    ->whereIn(DB::raw('LOWER(users_emails.email)'), $lowercased);
            });
        }

        // Once-per-London-day guard (skipped for --user).
        if ($londonDayStartUtc !== null) {
            $query->whereNotExists(function ($q) use ($londonDayStartUtc) {
                $q->select(DB::raw(1))
                    ->from('users_digests')
                    ->whereColumn('users_digests.userid', 'users.id')
                    ->where('users_digests.mode', self::MODE)
                    ->where('users_digests.lastsent', '>=', $londonDayStartUtc);
            });
        }

        $users = $query->lazyById(200);

        if ($limit !== null) {
            $users = $users->take($limit);
        }

        $stats = [
            'users_processed' => 0,
            'pushes_sent'     => 0,
            'no_posts'        => 0,
            'opted_out'       => 0,
            'errors'          => 0,
        ];

        foreach ($users as $user) {
            if ($this->shouldStop()) {
                $this->info('Shutdown signal received — stopped between users.');
                break;
            }

            try {
                $result = $this->processUser($user, $pushService, $digestService, $dryRun);
                $stats['users_processed']++;
                $stats[$result]++;
            } catch (\Throwable $e) {
                $stats['errors']++;
                Log::error('push:daily-posts: error processing user', [
                    'user_id' => $user->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['Users Processed',  $stats['users_processed']],
                ['Pushes Sent',      $stats['pushes_sent']],
                ['No Posts',         $stats['no_posts']],
                ['Opted Out',        $stats['opted_out']],
                ['Errors',           $stats['errors']],
            ]
        );

        if ($stats['errors'] > 0) {
            $this->warn('There were ' . $stats['errors'] . ' errors. Check logs for details.');
        }

        return Command::SUCCESS;
    }

    /**
     * Process a single user: fetch posts, build payload, send, advance cursor.
     *
     * Returns a stats key: 'pushes_sent', 'no_posts', or 'opted_out'.
     */
    private function processUser(
        User                    $user,
        PushNotificationService $pushService,
        UnifiedDigestService    $digestService,
        bool                    $dryRun,
    ): string {
        // Opt-out check: settings.notifications.dailypostspush === false.
        // Absent key or true → opted in (default).
        $settings = $user->settings ?? [];
        $notifSettings = $settings['notifications'] ?? [];
        if (array_key_exists('dailypostspush', $notifSettings) && $notifSettings['dailypostspush'] === FALSE) {
            Log::debug('push:daily-posts: user opted out', ['user_id' => $user->id]);
            return 'opted_out';
        }

        // Get or create the push cursor (mode='push').
        $tracker = UserDigest::firstOrCreate(
            [
                'userid' => $user->id,
                'mode'   => self::MODE,
            ],
            [
                'lastmsgid'   => null,
                'lastmsgdate' => null, // null → last 24 h on first tick (same as daily email)
            ]
        );

        // Fetch posts since cursor using UnifiedDigestService::getPostsForUser,
        // passing the push tracker. We call with mode=daily so the frequency
        // filter accepts any positive emailfrequency (not only immediate=-1),
        // matching the scope of the daily email digest.
        $allPosts = $digestService->getPostsForUser($user, $tracker, UnifiedDigestService::MODE_DAILY);

        if ($allPosts->isEmpty()) {
            return 'no_posts';
        }

        // Only available posts (no outcome). Mirrors sendDigestToUser filtering.
        $availablePosts = $allPosts->filter(fn ($p) => ! $p->has_outcome)->values();

        // ...and the member's own distance preference, which the daily EMAIL digest applies
        // in sendDigestToUser. Calling getPostsForUser directly skipped it, so a member who
        // had narrowed their range still got the far-away post pushed to their phone while
        // it was correctly missing from their inbox. Same method, so the two cannot drift.
        $availablePosts = $digestService->filterByDistancePreference($availablePosts, $user);

        if ($availablePosts->isEmpty()) {
            if (! $dryRun) {
                $this->advanceCursor($tracker, $allPosts);
            }
            return 'no_posts';
        }

        // Deduplicate cross-posted items.
        $deduped = $digestService->deduplicatePosts($availablePosts);

        if ($deduped->isEmpty()) {
            if (! $dryRun) {
                $this->advanceCursor($tracker, $allPosts);
            }
            return 'no_posts';
        }

        $postsArray = $deduped->values()->all();

        if ($dryRun) {
            $this->line(
                "  [dry-run] user={$user->id} posts=" . count($postsArray) .
                ' title="' . $pushService->buildDailyNewPostsPayload($user->id, $postsArray)['title'] . '"'
            );
            return 'pushes_sent';
        }

        $sent = $pushService->notifyDailyNewPosts($user->id, $postsArray);

        // Advance the cursor regardless of send count (the posts were processed).
        $this->advanceCursor($tracker, $allPosts);

        Log::info('push:daily-posts: sent', [
            'user_id'    => $user->id,
            'post_count' => count($postsArray),
            'tokens_hit' => $sent,
        ]);

        return 'pushes_sent';
    }

    /**
     * Advance the push cursor past the last processed post.
     *
     * Mirrors UnifiedDigestService::updateDigestTracker.
     */
    private function advanceCursor(UserDigest $tracker, \Illuminate\Support\Collection $posts): void
    {
        $last = $posts->last();
        if (! $last) {
            return;
        }

        $tracker->update([
            'lastmsgid'   => $last->id,
            'lastmsgdate' => $last->arrival,
            'lastsent'    => now(),
        ]);
    }

    /**
     * Parse FREEGLE_POSTS_PUSH_ALLOWLIST into the canonical form used by
     * getUsersForDigest and getDailyAllowlist:
     *   []      → nobody
     *   ['*']   → everyone
     *   [...]   → explicit address list
     */
    private function resolveAllowlist(): array
    {
        $raw = trim((string) config('freegle.posts_push_allowlist', ''));
        if ($raw === '') {
            return [];
        }
        $parts = array_values(array_filter(array_map('trim', explode(',', $raw)), fn ($s) => $s !== ''));
        if (in_array('*', $parts, TRUE)) {
            return ['*'];
        }
        return $parts;
    }
}
