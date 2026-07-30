<?php

namespace App\Services;

use App\Models\ChatRoom;
use App\Models\User;
use App\Services\LokiService;
use App\Services\Ripple\RippleReplyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;

/**
 * Service for sending push notifications via Firebase Cloud Messaging.
 *
 * Ported from the legacy V1 PHP PushNotifications class.
 * Handles FCM Android/iOS notifications for ModTools moderators.
 */
class PushNotificationService
{
    private const PUSH_FCM_ANDROID = 'FCMAndroid';
    private const PUSH_FCM_IOS = 'FCMIOS';

    private const APPTYPE_MODTOOLS = 'ModTools';
    private const APPTYPE_USER = 'User';

    // V1 PushNotifications::CATEGORY_EXHORT — Android "tips" channel, passive iOS.
    private const CATEGORY_EXHORT = 'EXHORT';

    // Daily new-posts push — matches V1 PostNotifications::CATEGORY_NEW_POSTS.
    // The constant is public so tests and the command can reference it without
    // duplicating the string literal.
    public const CATEGORY_NEW_POSTS = 'NEW_POSTS';

    // Constant notId for the daily digest push so Android replaces the
    // previous day's tray entry rather than stacking a new one.
    private const NEW_POSTS_NOT_ID = '200000001';

    private $messaging = null;

    /** Whether we've already alerted that FCM is unavailable (avoids per-push spam). */
    private bool $unavailableLogged = false;

    public function __construct()
    {
        $credentialsPath = config('freegle.firebase.credentials_path', '/etc/firebase.json');

        try {
            // NB: file_exists() is TRUE for a /dev/null bind-mount, and an empty
            // or unreadable credentials file makes the Firebase factory throw deep
            // inside SplFileObject ("length must be > 0"). Check explicitly so the
            // failure is unambiguous rather than a cryptic stream error — a broken
            // creds mount previously disabled push for days with no visible alert.
            if (! is_file($credentialsPath) || ! is_readable($credentialsPath) || filesize($credentialsPath) === 0) {
                throw new \RuntimeException("Firebase credentials missing, unreadable or empty: {$credentialsPath}");
            }

            $factory = (new Factory)->withServiceAccount($credentialsPath);
            $this->messaging = $factory->createMessaging();
        } catch (\Throwable $e) {
            $this->reportFirebaseUnavailable($e->getMessage());
        }
    }

    /**
     * Alert loudly — application log (ERROR), Sentry and Loki — that FCM could
     * not be initialised, so a broken/empty credentials mount can never again
     * silently swallow push notifications. Fires once per service construction.
     */
    private function reportFirebaseUnavailable(string $detail): void
    {
        $message = 'Push notifications DISABLED: Firebase Cloud Messaging failed to initialise';

        Log::error($message, ['detail' => $detail]);

        if (function_exists('\Sentry\captureMessage')) {
            \Sentry\captureMessage($message.' — '.$detail);
        }

        try {
            app(LokiService::class)->logEvent('push', 'firebase_init_failed', [
                'detail' => $detail,
            ]);
        } catch (\Throwable $e) {
            // Never let alerting break construction.
        }
    }

    /**
     * Record (once per instance) that a push had to be dropped because FCM is
     * unavailable, then return 0. Makes the *impact* visible in Sentry/Loki
     * without emitting a line per dropped message.
     */
    private function messagingUnavailable(string $context, array $extra = []): int
    {
        if (! $this->unavailableLogged) {
            $this->unavailableLogged = true;

            $message = 'Push notification DROPPED: Firebase Cloud Messaging not initialised';
            Log::error($message, array_merge(['context' => $context], $extra));

            if (function_exists('\Sentry\captureMessage')) {
                \Sentry\captureMessage($message.' ('.$context.')');
            }

            try {
                app(LokiService::class)->logEvent('push', 'dropped_no_firebase', array_merge([
                    'context' => $context,
                ], $extra));
            } catch (\Throwable $e) {
                // Swallow — alerting must not break the send path.
            }
        }

        return 0;
    }

    /**
     * Notify group moderators of new pending work.
     *
     * Matches legacy PushNotifications::notifyGroupMods().
     * Finds all moderators/owners, checks their pushnotify setting,
     * and sends FCM notifications.
     */
    public function notifyGroupMods(int $groupId): int
    {
        $count = 0;

        $mods = DB::select(
            "SELECT DISTINCT userid FROM memberships WHERE groupid = ? AND role IN ('Owner', 'Moderator')",
            [$groupId]
        );

        foreach ($mods as $mod) {
            // Check per-group notification settings
            $settings = $this->getGroupSettings($mod->userid, $groupId);

            if (! array_key_exists('pushnotify', $settings) || $settings['pushnotify']) {
                $count += $this->notify($mod->userid, TRUE);
            }
        }

        return $count;
    }

    /**
     * Send push notification to a user (ModTools context).
     *
     * Queries the user's registered FCM devices and sends a data-only
     * notification with badge count and message summary.
     */
    public function notify(int $userId, bool $modtools): int
    {
        if (! $this->messaging) {
            return $this->messagingUnavailable('notify', ['user_id' => $userId]);
        }

        $count = 0;

        $apptype = $modtools ? self::APPTYPE_MODTOOLS : 'User';
        $notifs = DB::select(
            "SELECT * FROM users_push_notifications WHERE userid = ? AND apptype = ?",
            [$userId, $apptype]
        );

        foreach ($notifs as $notif) {
            if (! in_array($notif->type, [self::PUSH_FCM_ANDROID, self::PUSH_FCM_IOS])) {
                continue;
            }

            try {
                $payload = $this->buildModToolsPayload($userId);
                if ($payload === null) {
                    continue;
                }

                $this->sendFcm($userId, $notif->type, $notif->subscription, $payload);

                DB::table('users_push_notifications')
                    ->where('userid', $userId)
                    ->where('subscription', $notif->subscription)
                    ->update(['lastsent' => now()]);

                $count++;
            } catch (\Throwable $e) {
                $errorMsg = $e->getMessage();
                Log::warning('Push notification failed', [
                    'user_id' => $userId,
                    'type' => $notif->type,
                    'error' => $errorMsg,
                ]);

                // Remove permanently invalid tokens (UNREGISTERED = app uninstalled,
                // NOT_FOUND = instance deleted, SENDER_ID_MISMATCH = wrong Firebase project)
                if (str_contains($errorMsg, 'UNREGISTERED') ||
                    str_contains($errorMsg, 'NOT_FOUND') ||
                    str_contains($errorMsg, 'SENDER_ID_MISMATCH') ||
                    str_contains($errorMsg, 'Requested entity was not found') ||
                    str_contains($errorMsg, 'Invalid registration token') ||
                    str_contains($errorMsg, 'not a valid FCM registration token')) {
                    DB::table('users_push_notifications')
                        ->where('userid', $userId)
                        ->where('subscription', $notif->subscription)
                        ->delete();

                    Log::info('Removed invalid push subscription', [
                        'user_id' => $userId,
                        'subscription' => substr($notif->subscription, 0, 20) . '...',
                    ]);
                }
            }
        }

        return $count;
    }

    /**
     * Send a Freegle-app (FD) push to a user about their on-site notifications,
     * mirroring the legacy V1 PHP PushNotifications::notify($userid, FALSE) +
     * User::getNotificationPayload(FALSE). Used after creating an onsite
     * notification (e.g. the Exhort nudge) so the device badge/banner updates.
     *
     * Returns the number of FD devices notified.
     */
    public function notifyUser(int $userId): int
    {
        if (! $this->messaging) {
            return $this->messagingUnavailable('notify_user', ['user_id' => $userId]);
        }

        $notifs = DB::select(
            'SELECT * FROM users_push_notifications WHERE userid = ? AND apptype = ?',
            [$userId, self::APPTYPE_USER]
        );

        if (empty($notifs)) {
            return 0;
        }

        $payload = $this->buildUserNotificationPayload($userId);
        if (empty($payload)) {
            return 0;
        }

        $count = 0;
        foreach ($notifs as $notif) {
            if (! in_array($notif->type, [self::PUSH_FCM_ANDROID, self::PUSH_FCM_IOS])) {
                continue;
            }

            try {
                $this->sendFcm($userId, $notif->type, $notif->subscription, $payload, true);

                DB::table('users_push_notifications')
                    ->where('userid', $userId)
                    ->where('subscription', $notif->subscription)
                    ->update(['lastsent' => now()]);

                $count++;
            } catch (\Throwable $e) {
                $errorMsg = $e->getMessage();
                Log::warning('User push notification failed', [
                    'user_id' => $userId,
                    'type' => $notif->type,
                    'error' => $errorMsg,
                ]);

                if (str_contains($errorMsg, 'UNREGISTERED') ||
                    str_contains($errorMsg, 'NOT_FOUND') ||
                    str_contains($errorMsg, 'SENDER_ID_MISMATCH') ||
                    str_contains($errorMsg, 'Requested entity was not found') ||
                    str_contains($errorMsg, 'Invalid registration token') ||
                    str_contains($errorMsg, 'not a valid FCM registration token')) {
                    DB::table('users_push_notifications')
                        ->where('userid', $userId)
                        ->where('subscription', $notif->subscription)
                        ->delete();
                }
            }
        }

        return $count;
    }

    /**
     * Build the FD-app payload from a user's unseen chats + on-site notifications,
     * mirroring V1 User::getNotificationPayload(FALSE). When there are no unseen
     * chats the payload reflects the most recent unseen notification (e.g. an
     * Exhort: title/body/route taken from the notification, EXHORT category/tips
     * channel).
     */
    /**
     * The unread counts that make up a consumer's app-icon badge: unseen
     * on-site notifications + unread User2User/User2Mod chats. This is the
     * "actionable items" count the badge should reflect - NOT informational
     * pushes such as the daily "new posts near you" digest.
     *
     * @return array{0:int,1:int} [chatcount, notifcount]
     */
    public function consumerUnreadCounts(int $userId): array
    {
        $notifcount = (int) DB::table('users_notifications')
            ->where('touser', $userId)
            ->where('seen', 0)
            ->count();

        // Unseen chats: User2User/User2Mod rooms where a message from someone
        // else is newer than what this user has seen.
        $chatcount = (int) DB::table('chat_roster as cr')
            ->join('chat_rooms as crm', 'crm.id', '=', 'cr.chatid')
            ->whereIn('crm.chattype', [ChatRoom::TYPE_USER2USER, ChatRoom::TYPE_USER2MOD])
            ->where('cr.userid', $userId)
            ->whereRaw('(cr.lastmsgseen IS NULL OR cr.lastmsgseen < (
                SELECT MAX(cm.id) FROM chat_messages cm
                WHERE cm.chatid = cr.chatid AND cm.userid <> ?
                  AND ' . RippleReplyService::deliveryGateSql('cm.id') . '
            ))', [$userId])
            ->count();

        return [$chatcount, $notifcount];
    }

    public function buildUserNotificationPayload(int $userId): array
    {
        [$chatcount, $notifcount] = $this->consumerUnreadCounts($userId);

        $total = $chatcount + $notifcount;

        $title = '';
        $message = '';
        $route = '/';
        $category = null;
        $threadId = 'notifications';
        $channelId = 'chat_messages';

        if ($chatcount > 0) {
            $title = "You have {$chatcount} new message" . ($chatcount === 1 ? '' : 's');
            if ($notifcount > 0) {
                $title .= " and {$notifcount} notification" . ($notifcount === 1 ? '' : 's');
            }
            $route = '/chats';
            $threadId = 'chats';
            $category = 'CHAT_MESSAGE';
        } elseif ($notifcount > 0) {
            $latest = DB::table('users_notifications')
                ->where('touser', $userId)
                ->where('seen', 0)
                ->orderByDesc('id')
                ->first();

            $title = ($latest->title ?? '') ?: 'You have a new notification';
            $message = $latest->text ?? '';
            $route = ($latest->url ?? '') ?: '/';

            if (($latest->type ?? '') === 'Exhort') {
                $category = self::CATEGORY_EXHORT;
                $threadId = 'tips';
                $channelId = 'tips';
            }
        } else {
            // Nothing to say.
            return [];
        }

        return [
            'badge' => (string) $total,
            'count' => (string) $total,
            'chatcount' => (string) $chatcount,
            'notifcount' => (string) $notifcount,
            'title' => $title,
            'message' => $message,
            'chatids' => '',
            'content-available' => $total > 0 ? '1' : '0',
            'image' => 'www/images/user_logo.png',
            'modtools' => '0',
            'sound' => 'default',
            'route' => $route,
            'category' => $category ?? '',
            'channel_id' => $channelId,
            'threadId' => $threadId,
            'notId' => (string) $userId,
        ];
    }

    /**
     * Compute the badge count for a ModTools user.
     *
     * Mirrors session.go's work total calculation:
     * - Only ACTIVE groups (membership settings.active != 0 and settings.showmessages != 0)
     * - Only unheld pending messages (heldby IS NULL)
     * - Only spam collection messages (not spamtype in Pending)
     * - Excludes deleted messages (mg.deleted = 0)
     * - INNER JOINs users with u.deleted IS NULL, so system messages (null fromuser)
     *   AND messages whose author has been deleted are both excluded — matching the
     *   app menu (session.go), which filters them too. Without this a pending message
     *   from a deleted user is counted in the badge but hidden from the menu, leaving
     *   a phantom +1 the mod can never clear (Discourse #9654/12).
     *
     * This prevents phantom badges caused by held messages, deleted messages,
     * deleted-user messages, or work from inactive groups inflating the count while
     * the app shows nothing.
     *
     * Note: currently covers only pending + spam (2 of 14 session.go work categories).
     * Omitted categories: pendingmembers, spammembers, pendingevents, pendingadmins,
     * editreview, pendingvolunteering, stories, spammerpendingadd, spammerpendingremove,
     * chatreview, newsletterstories, relatedmembers. Add those here as needed.
     *
     * See: Discourse #9547 — phantom badge count on Android/iOS ModTools.
     */
    public function getBadgeCount(int $userId): int
    {
        return $this->getBadgeBreakdown($userId)['total'];
    }

    /**
     * Return per-collection badge counts for a ModTools user.
     *
     * Returns ['pending' => N, 'spam' => N, 'volunteering' => N, 'total' => N].
     * Callers that need per-collection data (e.g. to choose notification route/label)
     * use this directly rather than duplicating the queries in a separate method.
     *
     * The queries here are the single authoritative source for badge counts — do not
     * add a parallel count method; update this breakdown instead.
     */
    private function getBadgeBreakdown(int $userId): array
    {
        $zero = ['pending' => 0, 'spam' => 0, 'volunteering' => 0, 'total' => 0];

        // Get all approved mod/owner memberships with settings to determine active/inactive.
        $memberships = DB::select(
            "SELECT groupid, settings FROM memberships
             WHERE userid = ? AND role IN ('Owner', 'Moderator') AND collection = 'Approved'",
            [$userId]
        );

        if (empty($memberships)) {
            return $zero;
        }

        // Mirror session.go: only count work from active groups in the badge total.
        // Inactive groups' work appears as blue informational badges in the app — not in total.
        $activeGroupIds = [];
        foreach ($memberships as $m) {
            if ($this->isActiveMod($m->settings)) {
                $activeGroupIds[] = $m->groupid;
            }
        }

        if (empty($activeGroupIds)) {
            return $zero;
        }

        $placeholders = implode(',', array_fill(0, count($activeGroupIds), '?'));

        // Unheld pending messages in active groups.
        $pendingParams = array_merge([$userId], $activeGroupIds);
        $pending = DB::selectOne(
            "SELECT COUNT(*) as cnt FROM messages_groups mg
             INNER JOIN messages m ON m.id = mg.msgid
             INNER JOIN users u ON u.id = m.fromuser AND u.deleted IS NULL
             INNER JOIN memberships mem ON mem.groupid = mg.groupid AND mem.userid = ?
             WHERE mem.role IN ('Owner', 'Moderator')
             AND mem.collection = 'Approved'
             AND mg.collection = 'Pending'
             AND mg.groupid IN ({$placeholders})
             AND mg.deleted = 0
             -- Per-group hold: mg.heldby, not the message-wide messages.heldby mirror,
             -- which suppressed the push for groups that had never held anything just
             -- because another group the post rippled to had (Discourse 9970/2).
             AND mg.heldby IS NULL",
            $pendingParams
        );

        // Spam collection messages in active groups.
        $spamParams = array_merge([$userId], $activeGroupIds);
        $spam = DB::selectOne(
            "SELECT COUNT(*) as cnt FROM messages_groups mg
             INNER JOIN messages m ON m.id = mg.msgid
             INNER JOIN users u ON u.id = m.fromuser AND u.deleted IS NULL
             INNER JOIN memberships mem ON mem.groupid = mg.groupid AND mem.userid = ?
             WHERE mem.role IN ('Owner', 'Moderator')
             AND mem.collection = 'Approved'
             AND mg.collection = 'Spam'
             AND mg.groupid IN ({$placeholders})
             AND mg.deleted = 0",
            $spamParams
        );

        // Pending volunteering ops in active groups (mirrors session.go pendingvolunteering query).
        $volunteering = DB::selectOne(
            "SELECT COUNT(DISTINCT v.id) AS cnt FROM volunteering v
             INNER JOIN volunteering_groups vg ON vg.volunteeringid = v.id
             LEFT JOIN volunteering_dates vd ON vd.volunteeringid = v.id
             WHERE vg.groupid IN ({$placeholders})
             AND v.pending = 1 AND v.deleted = 0 AND v.expired = 0
             AND (vd.end IS NULL OR vd.end >= NOW())",
            $activeGroupIds
        );

        $pendingCnt = (int) ($pending->cnt ?? 0);
        $spamCnt = (int) ($spam->cnt ?? 0);
        $volunteeringCnt = (int) ($volunteering->cnt ?? 0);

        return [
            'pending' => $pendingCnt,
            'spam' => $spamCnt,
            'volunteering' => $volunteeringCnt,
            'total' => $pendingCnt + $spamCnt + $volunteeringCnt,
        ];
    }

    /**
     * Determine if a moderator is active for a group based on membership settings JSON.
     *
     * Mirrors session.go isActiveModForGroup: defaults to active unless explicitly
     * set to false/0 via the 'active' or 'showmessages' setting.
     */
    private function isActiveMod(?string $settingsJson): bool
    {
        if (! $settingsJson) {
            return true;
        }

        $settings = json_decode($settingsJson, true);
        if (! is_array($settings)) {
            return true;
        }

        if (array_key_exists('active', $settings)) {
            return (bool) $settings['active'];
        }

        if (array_key_exists('showmessages', $settings)) {
            return (bool) $settings['showmessages'];
        }

        return true;
    }

    /**
     * Build the ModTools notification payload.
     *
     * Mirrors the legacy V1 PHP User::getNotificationPayload(TRUE):
     * iterate categories in a fixed priority order (volunteering → spam → pending), last-wins,
     * so pending — being last — always wins when present. This ensures the notification route and
     * title reflect the highest-priority work type.
     *
     * Routes use direct Nuxt paths WITHOUT a /modtools/ prefix. The /modtools/ prefix hits the
     * catch-all redirect page (iznik-nuxt3/modtools/pages/modtools/[...slug].vue) which delays
     * navigation by 2 seconds. The correct direct pages are /messages/pending and /volunteering.
     * (Discourse #9692/10).
     *
     * Category mapping (V1 parity):
     *   volunteering → route /volunteering,        title "N volunteer op(s)"
     *   spam         → route /messages/pending,    title "N message(s) to review"
     *   pending      → route /messages/pending,    title "N pending message(s)"
     *
     * Title is multi-line ("\n"-joined) listing each non-zero category.
     * If total == 0: empty title, route "/".
     * Badge is always total across all categories.
     */
    private function buildModToolsPayload(int $userId): ?array
    {
        $breakdown = $this->getBadgeBreakdown($userId);
        $total = $breakdown['total'];

        if ($total === 0) {
            // Still send a zero-count to clear badge
            return [
                'badge' => '0',
                'count' => '0',
                'chatcount' => '0',
                'notifcount' => '0',
                'title' => '',
                'message' => '',
                'chatids' => '',
                'content-available' => '0',
                'image' => 'www/images/modtools_logo.png',
                'modtools' => '1',
                'sound' => 'default',
                'route' => '/',
                'channel_id' => 'modtools',
                'notId' => (string) $userId,
            ];
        }

        // Per-category label lines (for multi-line title) and per-category route.
        // Last-wins order: volunteering first, pending last — so pending wins if present.
        $titleLines = [];
        $route = '/';

        $volunteeringCount = $breakdown['volunteering'];
        if ($volunteeringCount > 0) {
            $titleLines[] = $volunteeringCount . ' volunteer op' . ($volunteeringCount > 1 ? 's' : '');
            $route = '/volunteering';
        }

        $spamCount = $breakdown['spam'];
        if ($spamCount > 0) {
            $titleLines[] = $spamCount . ' message' . ($spamCount > 1 ? 's' : '') . ' to review';
            $route = '/messages/pending';
        }

        $pendingCount = $breakdown['pending'];
        if ($pendingCount > 0) {
            $titleLines[] = $pendingCount . ' pending message' . ($pendingCount > 1 ? 's' : '');
            $route = '/messages/pending';
        }

        $title = implode("\n", $titleLines);

        return [
            'badge' => (string) $total,
            'count' => (string) $total,
            'chatcount' => '0',
            'notifcount' => (string) $total,
            'title' => $title,
            'message' => 'Open ModTools to review',
            'chatids' => '',
            'content-available' => '1',
            'image' => 'www/images/modtools_logo.png',
            'modtools' => '1',
            'sound' => 'default',
            'route' => $route,
            'channel_id' => 'modtools',
            // Fixed notId per user so each new notification replaces the previous one
            // on Android instead of stacking multiple "N pending" badges.
            'notId' => (string) $userId,
        ];
    }

    /**
     * Send a forced-visible test push to all of a user's registered devices for the given app.
     *
     * Bypasses the work-count payload entirely: always includes a notification block so the
     * push lands in the system tray on Android and iOS, and uses badge = max(realWork, 1)
     * so a real pending count is never overwritten with a smaller number.
     */
    public function notifyTest(int $userId, bool $modtools): int
    {
        if (! $this->messaging) {
            Log::debug('Firebase not configured, skipping test push notification', [
                'user_id' => $userId,
            ]);

            return 0;
        }

        $apptype = $modtools ? self::APPTYPE_MODTOOLS : 'User';
        $notifs = DB::select(
            "SELECT * FROM users_push_notifications WHERE userid = ? AND apptype = ?",
            [$userId, $apptype]
        );

        $appLabel = $modtools ? 'ModTools' : 'Freegle';
        $realCount = $modtools ? $this->getBadgeCount($userId) : 0;
        $badge = max($realCount, 1);

        $payload = [
            'badge' => (string) $badge,
            'count' => (string) $badge,
            'chatcount' => '0',
            'notifcount' => (string) $badge,
            'title' => "$appLabel test notification",
            'message' => 'Push test from iznik-batch — if you can see this, push notifications are working.',
            'chatids' => '',
            'content-available' => '1',
            'image' => $modtools ? 'www/images/modtools_logo.png' : 'www/images/user_logo.png',
            'modtools' => $modtools ? '1' : '0',
            'sound' => 'default',
            'route' => $modtools ? '/modtools' : '/',
            'notId' => (string) $userId,
            'test' => '1',
            'channel_id' => $modtools ? 'modtools' : 'chat_messages',
        ];

        $count = 0;
        foreach ($notifs as $notif) {
            if (! in_array($notif->type, [self::PUSH_FCM_ANDROID, self::PUSH_FCM_IOS])) {
                continue;
            }

            try {
                $this->sendFcm($userId, $notif->type, $notif->subscription, $payload, true);

                DB::table('users_push_notifications')
                    ->where('userid', $userId)
                    ->where('subscription', $notif->subscription)
                    ->update(['lastsent' => now()]);

                $count++;
            } catch (\Throwable $e) {
                $errorMsg = $e->getMessage();
                Log::warning('Test push notification failed', [
                    'user_id' => $userId,
                    'type' => $notif->type,
                    'error' => $errorMsg,
                ]);

                if (str_contains($errorMsg, 'UNREGISTERED') ||
                    str_contains($errorMsg, 'Invalid registration token') ||
                    str_contains($errorMsg, 'not a valid FCM registration token')) {
                    DB::table('users_push_notifications')
                        ->where('userid', $userId)
                        ->where('subscription', $notif->subscription)
                        ->delete();

                    Log::info('Removed invalid push subscription', [
                        'user_id' => $userId,
                        'subscription' => substr($notif->subscription, 0, 20) . '...',
                    ]);
                }
            }
        }

        return $count;
    }

    /**
     * Build the array passed to CloudMessage::fromArray for an Android push.
     *
     * Extracted so we can assert on the wire-level structure in tests without
     * standing up a Firebase mock. Adds a `notification` block when the push
     * is for ModTools or has been explicitly marked forceVisible — otherwise
     * FCM hands the data-only message to the app's listener and it never
     * appears in the system tray.
     */
    protected function buildAndroidFcmMessage(string $token, array $payload, bool $forceVisible): array
    {
        $isModtools = ($payload['channel_id'] ?? '') === 'modtools';

        $androidMessage = [
            'token' => $token,
            'data' => $payload,
        ];

        if (($forceVisible || $isModtools) && ! empty($payload['title'])) {
            $androidMessage['notification'] = [
                'title' => $payload['title'],
                'body' => $payload['message'] ?: $payload['title'],
            ];
        }

        return $androidMessage;
    }

    /**
     * Build the AndroidConfig (priority, ttl, optional notification tag)
     * that accompanies the FCM message.
     *
     * Zero-work ModTools pushes carry empty title/message — they exist only
     * to clear the launcher badge and must stay silent. We achieve that by
     * sending pure data-only at normal priority: no notification block, and
     * no AndroidConfig.notification (which can promote the message to a
     * notification on some devices/Capacitor builds and leave an empty
     * tray entry).
     */
    protected function buildAndroidConfig(int $userId, array $payload, bool $forceVisible): array
    {
        $isModtools = ($payload['channel_id'] ?? '') === 'modtools';
        $hasVisibleContent = ! empty($payload['title']);

        $androidConfig = [
            'ttl' => '3600s',
            'priority' => ($forceVisible || ($isModtools && $hasVisibleContent)) ? 'high' : 'normal',
        ];

        if ($isModtools && $hasVisibleContent) {
            $androidConfig['notification'] = ['tag' => "modtools-{$userId}"];
        }

        return $androidConfig;
    }

    /**
     * Send FCM notification to a device.
     *
     * When $forceVisible is true, includes a notification block so the push appears in the
     * system tray even if the app is killed (used by the test command).
     *
     * For the NEW_POSTS category on iOS we set mutable-content=1 in the APNS
     * aps dictionary so the Notification Service Extension (NSE) fires and can
     * attach the post image. The alert body is required for the NSE to wake;
     * without an alert block the system drops the notification before the NSE
     * runs. Existing callers (chat, ModTools, exhort) are unaffected because
     * they don't carry category=NEW_POSTS in their payload.
     */
    private function sendFcm(int $userId, string $type, string $token, array $payload, bool $forceVisible = false): void
    {
        if ($type === self::PUSH_FCM_ANDROID) {
            $androidMessage = $this->buildAndroidFcmMessage($token, $payload, $forceVisible);
            $message = CloudMessage::fromArray($androidMessage);
            $message = $message->withAndroidConfig($this->buildAndroidConfig($userId, $payload, $forceVisible));
        } else {
            // iOS: include notification block for display
            $ios = [
                'token' => $token,
                'data' => $payload,
            ];

            if (! empty($payload['title'])) {
                $ios['notification'] = [
                    'title' => $payload['title'],
                    'body' => $payload['message'] ?: $payload['title'],
                ];
            }

            $message = CloudMessage::fromArray($ios);

            $badge = (int) ($payload['count'] ?? 0);
            $isNewPosts = ($payload['category'] ?? '') === self::CATEGORY_NEW_POSTS;

            $aps = [
                'badge' => $badge,
                'sound' => 'default',
            ];

            // mutable-content=1 wakes the NSE so it can attach the post image
            // and expand lines[] into a rich notification. Only set for NEW_POSTS
            // — other categories (chat, modtools) don't use the NSE.
            if ($isNewPosts) {
                $aps['mutable-content'] = 1;
            }

            $message = $message->withApnsConfig([
                'headers' => [
                    'apns-priority' => '10',
                ],
                'payload' => [
                    'aps' => $aps,
                ],
            ]);
        }

        $this->messaging->validate($message);
        $this->messaging->send($message);
    }

    /**
     * Get user's per-group settings.
     */
    private function getGroupSettings(int $userId, int $groupId): array
    {
        $membership = DB::selectOne(
            "SELECT settings FROM memberships WHERE userid = ? AND groupid = ?",
            [$userId, $groupId]
        );

        if (! $membership || ! $membership->settings) {
            return [];
        }

        return json_decode($membership->settings, TRUE) ?: [];
    }

    /**
     * Send FCM push notifications for a chat message to all recipients
     * computed by getChatMessageRecipients().
     *
     * Returns the total number of FCM tokens successfully notified.
     */
    public function notifyChatMessage(int $messageId): int
    {
        // Rippling-out held replies (#3): don't push a reply to the poster while it is held
        // because the post hasn't yet rippled to the replier's area. Until a reply is held
        // the rippling table is empty, so this never fires. The reply is pushed normally
        // once released (status='released').
        if (app(RippleReplyService::class)->isDeliveryHeld($messageId)) {
            return 0;
        }

        $recipients = $this->getChatMessageRecipients($messageId);

        $count = 0;
        foreach ($recipients['fd'] as $userId) {
            $count += $this->sendChatMessagePush($userId, $messageId, FALSE);
        }
        foreach ($recipients['mt'] as $userId) {
            $count += $this->sendChatMessagePush($userId, $messageId, TRUE);
        }
        return $count;
    }

    /**
     * Send a chat-message FCM push to all of a user's registered devices for
     * the appropriate app (FD or MT). Mirrors notify() but uses the
     * chat-message payload and chat_messages/modtools channel.
     */
    private function sendChatMessagePush(int $userId, int $messageId, bool $modtools): int
    {
        if (! $this->messaging) {
            return $this->messagingUnavailable('chat_message', ['user_id' => $userId, 'message_id' => $messageId]);
        }

        $payload = $this->buildChatMessagePayload($messageId, $userId, $modtools);
        if (empty($payload)) {
            return 0;
        }

        $apptype = $modtools ? self::APPTYPE_MODTOOLS : 'User';
        $notifs = DB::select(
            'SELECT * FROM users_push_notifications WHERE userid = ? AND apptype = ?',
            [$userId, $apptype]
        );

        $count = 0;
        foreach ($notifs as $notif) {
            if (! in_array($notif->type, [self::PUSH_FCM_ANDROID, self::PUSH_FCM_IOS])) {
                continue;
            }

            try {
                $this->sendFcm($userId, $notif->type, $notif->subscription, $payload, TRUE);

                DB::table('users_push_notifications')
                    ->where('userid', $userId)
                    ->where('subscription', $notif->subscription)
                    ->update(['lastsent' => now()]);

                $count++;
            } catch (\Throwable $e) {
                $errorMsg = $e->getMessage();
                Log::warning('Chat push notification failed', [
                    'user_id' => $userId,
                    'message_id' => $messageId,
                    'type' => $notif->type,
                    'error' => $errorMsg,
                ]);

                if (str_contains($errorMsg, 'UNREGISTERED') ||
                    str_contains($errorMsg, 'NOT_FOUND') ||
                    str_contains($errorMsg, 'SENDER_ID_MISMATCH') ||
                    str_contains($errorMsg, 'Requested entity was not found') ||
                    str_contains($errorMsg, 'Invalid registration token') ||
                    str_contains($errorMsg, 'not a valid FCM registration token')) {
                    DB::table('users_push_notifications')
                        ->where('userid', $userId)
                        ->where('subscription', $notif->subscription)
                        ->delete();
                }
            }
        }
        return $count;
    }

    /**
     * Compute push recipients for a chat message, mirroring the legacy V1 PHP
     * ChatRoom::notifyMembers().
     *
     * Returns ['fd' => int[], 'mt' => int[]] — FD-app and MT-app recipient
     * user IDs respectively. The sender is always excluded. Recipients
     * without any group membership are excluded (V1 getMemberships()>0).
     * Held or rejected messages return empty arrays.
     *
     * Out-of-scope for this method: U2U's $modstoo path (mods notified when
     * message is held for review). That's tied to the review-release flow
     * which is a separate ticket.
     */
    public function getChatMessageRecipients(int $messageId): array
    {
        $empty = ['fd' => [], 'mt' => []];

        $msg = DB::table('chat_messages as cm')
            ->join('chat_rooms as cr', 'cm.chatid', '=', 'cr.id')
            ->where('cm.id', $messageId)
            ->select('cm.userid as sender', 'cm.reviewrequired', 'cm.reviewrejected',
                'cr.chattype', 'cr.user1', 'cr.user2', 'cr.groupid')
            ->first();

        if (! $msg || $msg->reviewrequired || $msg->reviewrejected) {
            return $empty;
        }

        $sender = (int) $msg->sender;
        $fd = [];
        $mt = [];

        switch ($msg->chattype) {
            case ChatRoom::TYPE_USER2USER:
                $fd = [(int) $msg->user1, (int) $msg->user2];
                break;

            case ChatRoom::TYPE_USER2MOD:
                $fd = [(int) $msg->user1];
                $mt = $this->getActiveGroupMods((int) $msg->groupid);
                break;

            // Mod2Mod: V1 notifyMembers has no case for it.
        }

        $chatId = (int) DB::table('chat_messages')->where('id', $messageId)->value('chatid');
        $fd = $this->filterPushRecipients($fd, $sender, $chatId);
        $mt = $this->filterPushRecipients($mt, $sender, $chatId);

        return ['fd' => $fd, 'mt' => $mt];
    }

    /**
     * Apply V1 recipient invariants: exclude sender, dedupe, drop users with
     * zero memberships (ex-members must not be pushed), drop users who
     * blocked this chat (chat_roster.status = 'Blocked' — V1 notifyIndividualMessages).
     */
    private function filterPushRecipients(array $userIds, int $excludeUser, int $chatId = 0): array
    {
        $userIds = array_values(array_unique(array_filter($userIds, function ($u) use ($excludeUser) {
            return (int) $u !== 0 && (int) $u !== $excludeUser;
        })));

        if (empty($userIds)) {
            return [];
        }

        $haveMembership = DB::table('memberships')
            ->whereIn('userid', $userIds)
            ->pluck('userid')
            ->unique()
            ->map(fn ($u) => (int) $u)
            ->all();

        $userIds = array_values(array_intersect($userIds, $haveMembership));

        if ($chatId > 0 && ! empty($userIds)) {
            $blocked = DB::table('chat_roster')
                ->where('chatid', $chatId)
                ->whereIn('userid', $userIds)
                ->where('status', 'Blocked')
                ->pluck('userid')
                ->map(fn ($u) => (int) $u)
                ->all();
            if (! empty($blocked)) {
                $userIds = array_values(array_diff($userIds, $blocked));
            }
        }

        return $userIds;
    }

    /**
     * Return user IDs of active moderators/owners for a group. "Active" mirrors
     * V1: a mod whose membership settings.active is not explicitly false.
     */
    private function getActiveGroupMods(int $groupId): array
    {
        $rows = DB::select(
            "SELECT DISTINCT userid, settings FROM memberships
             WHERE groupid = ? AND role IN (?, ?)",
            [$groupId, 'Owner', 'Moderator']
        );

        $active = [];
        foreach ($rows as $row) {
            if ($this->isActiveMod($row->settings)) {
                $active[] = (int) $row->userid;
            }
        }
        return $active;
    }

    /**
     * Build the FCM payload for a single chat-message push notification.
     *
     * Mirrors the legacy V1 PHP PushNotifications::notifyIndividualMessages
     * payload shape. Key fields the
     * mobile app's handleNotification (iznik-nuxt3/stores/mobile.js) needs:
     *
     * - channel_id: 'chat_messages' (FD) or 'modtools' (MT) — controls
     *   Android notification channel
     * - notId: chatid — Android collapses by notId so successive messages
     *   in the same chat REPLACE rather than stack
     * - chatids/chatid: the chat room id, so the app can fetch messages
     * - route: '/chats/{id}' — tap-through destination
     *
     * For U2M chats, mod-sent messages show "{GroupName} Volunteers" as the
     * title to the member, matching V1 (hides individual mod identity).
     */
    public function buildChatMessagePayload(int $messageId, int $recipientUserId, bool $modtools): array
    {
        $row = DB::table('chat_messages as cm')
            ->join('chat_rooms as cr', 'cm.chatid', '=', 'cr.id')
            ->leftJoin('users as su', 'cm.userid', '=', 'su.id')
            ->where('cm.id', $messageId)
            ->select('cm.id as msgid', 'cm.message', 'cm.type', 'cm.date',
                'cm.userid as sender_id', 'su.fullname as sender_name',
                'cr.id as chatid', 'cr.chattype', 'cr.user1', 'cr.groupid')
            ->first();

        if (! $row) {
            return [];
        }

        $title = $this->resolveChatPushTitle($row);

        $message = $row->message ?? '';
        if (mb_strlen($message) > 256) {
            $message = mb_substr($message, 0, 253) . '...';
        }

        // For messages with no text (image, address, system types), use a
        // descriptive fallback so the push body is never a repeat of the title.
        if ($message === '') {
            $message = $this->chatMessageTypeFallback($row->type ?? '');
        }

        $chatId = (int) $row->chatid;

        return [
            'badge' => '0',
            'count' => '0',
            'chatcount' => '1',
            'notifcount' => '0',
            'title' => $title,
            'message' => $message,
            'chatids' => (string) $chatId,
            'chatid' => (string) $chatId,
            'messageid' => (string) $row->msgid,
            'notId' => (string) $chatId,
            'timestamp' => (string) strtotime((string) $row->date),
            'content-available' => '1',
            'image' => $modtools ? 'www/images/modtools_logo.png' : 'www/images/user_logo.png',
            'modtools' => $modtools ? '1' : '0',
            'sound' => 'default',
            'route' => '/chats/' . $chatId,
            'category' => 'CHAT_MESSAGE',
            'channel_id' => $modtools ? 'modtools' : 'chat_messages',
            'threadId' => 'chat_' . $chatId,
        ];
    }

    /**
     * Return a human-readable description for a chat message type that has no
     * text body (image, address, system messages, etc.).
     *
     * Mirrors the labels used in iznik-nuxt3 for chat message rendering and
     * matches V1 PushNotifications wording where applicable.
     */
    private function chatMessageTypeFallback(string $type): string
    {
        return match ($type) {
            'Image'        => 'Sent an image',
            'Address'      => 'Sent an address',
            'Interested'   => 'Interested',
            'Promised'     => 'Promised',
            'Reneged'      => 'Reneged',
            'Completed'    => 'Marked as completed',
            'Nudge'        => 'Sent a nudge',
            'Reminder'     => 'Sent a reminder',
            default        => 'Sent a message',
        };
    }

    /**
     * Title shown in the push banner. For U2M chats sent by a moderator to
     * a member, hide the mod identity and show "{Group} Volunteers" (V1).
     */
    private function resolveChatPushTitle(object $row): string
    {
        if ($row->chattype === ChatRoom::TYPE_USER2MOD
            && (int) $row->sender_id !== (int) $row->user1
            && $row->groupid) {
            $group = DB::table('groups')->where('id', $row->groupid)
                ->select('namefull', 'nameshort')->first();
            if ($group) {
                $name = $group->namefull ?: $group->nameshort ?: 'Freegle';
                return $name . ' Volunteers';
            }
            return 'Freegle Volunteers';
        }

        // Use the display name the rest of the site uses rather than the raw
        // users.fullname: it strips the TrashNothing "-gNNN" suffix from imported
        // names (which was showing up in the push banner as "alice-g3486") and
        // rewrites misleading brand/authority names. See
        // User::getDisplayNameAttribute, which the chat notification email
        // already goes through.
        $sender = $row->sender_id ? User::find((int) $row->sender_id) : null;

        return $sender?->display_name ?: ($row->sender_name ?: 'Someone');
    }

    // -------------------------------------------------------------------------
    // Daily new-posts push
    // -------------------------------------------------------------------------

    /**
     * Send a daily new-posts push to all of a user's registered FD-app devices.
     *
     * $posts is the deduped post list as returned by
     * UnifiedDigestService::deduplicatePosts() — each element is
     * ['message' => Message, 'postedToGroups' => [groupid, ...]].
     *
     * Returns the number of FCM tokens successfully delivered.
     */
    public function notifyDailyNewPosts(int $userId, array $posts): int
    {
        if (! $this->messaging) {
            return $this->messagingUnavailable('daily_new_posts', ['user_id' => $userId]);
        }

        // Exclude the user's own posts (V1 PostNotifications::sendToUser parity).
        $posts = array_values(array_filter($posts, function (array $item) use ($userId) {
            return (int) ($item['message']->fromuser ?? 0) !== $userId;
        }));

        if (empty($posts)) {
            return 0;
        }

        $payload = $this->buildDailyNewPostsPayload($userId, $posts);
        if (empty($payload)) {
            return 0;
        }

        $notifs = DB::select(
            'SELECT * FROM users_push_notifications WHERE userid = ? AND apptype = ?',
            [$userId, self::APPTYPE_USER]
        );

        $count = 0;
        foreach ($notifs as $notif) {
            if (! in_array($notif->type, [self::PUSH_FCM_ANDROID, self::PUSH_FCM_IOS])) {
                continue;
            }

            try {
                // forceVisible=FALSE — Android must stay DATA-ONLY so the app's
                // own notification builder (InboxStyle / BigPictureStyle) runs.
                // The notification block on Android would bypass the JS handler
                // that reads channel_id and lines[]. iOS needs its alert block
                // for the NSE to fire (set unconditionally when title is present
                // in sendFcm's iOS path above).
                $this->sendFcm($userId, $notif->type, $notif->subscription, $payload, FALSE);

                DB::table('users_push_notifications')
                    ->where('userid', $userId)
                    ->where('subscription', $notif->subscription)
                    ->update(['lastsent' => now()]);

                $count++;
            } catch (\Throwable $e) {
                $errorMsg = $e->getMessage();
                Log::warning('Daily new-posts push failed', [
                    'user_id' => $userId,
                    'type' => $notif->type,
                    'error' => $errorMsg,
                ]);

                if (str_contains($errorMsg, 'UNREGISTERED') ||
                    str_contains($errorMsg, 'NOT_FOUND') ||
                    str_contains($errorMsg, 'SENDER_ID_MISMATCH') ||
                    str_contains($errorMsg, 'Requested entity was not found') ||
                    str_contains($errorMsg, 'Invalid registration token') ||
                    str_contains($errorMsg, 'not a valid FCM registration token')) {
                    DB::table('users_push_notifications')
                        ->where('userid', $userId)
                        ->where('subscription', $notif->subscription)
                        ->delete();

                    Log::info('Removed invalid push subscription', [
                        'user_id' => $userId,
                        'subscription' => substr($notif->subscription, 0, 20) . '...',
                    ]);
                }
            }
        }

        return $count;
    }

    /**
     * Build the FCM data payload for the daily new-posts push.
     *
     * ALL data values are strings (FCM requirement). The payload shape
     * is defined in full in the project spec and matches the contract
     * read by iznik-nuxt3's handleNotification / NSE.
     *
     * Adaptive rendering:
     *   count == 1  → BigPictureStyle (item photo); title = item name.
     *   count >= 2  → InboxStyle: lines[] rows + "+N more" row; largeIcon = first photo.
     *   Both paths carry a single-line fallback (title + message) for old app versions.
     *
     * @param int   $userId  Recipient user ID (used for token lookup by the caller).
     * @param array $posts   Deduped post list (already own-post-filtered by the caller).
     *                       Each element: ['message' => Message, 'postedToGroups' => [...]].
     */
    public function buildDailyNewPostsPayload(int $userId, array $posts): array
    {
        $count = count($posts);

        if ($count === 0) {
            return [];
        }

        $maxLines = 5; // V1 PostNotifications::MAX_POSTS_IN_NOTIFICATION

        // ---- Item names and type labels (for lines[] and message fallback) ----
        $lines = [];
        foreach (array_slice($posts, 0, $maxLines) as $item) {
            $msg = $item['message'];
            $lines[] = $this->formatPostLine($msg);
        }

        $moreCount = max(0, $count - count($lines));

        // ---- Single-line fallback message ----
        $allNames = array_map(fn ($item) => $this->nameWithBulk($item['message']), $posts);
        $previewNames = array_slice($allNames, 0, $maxLines);
        $message = implode(', ', $previewNames);
        if ($moreCount > 0) {
            $message .= ' +' . $moreCount . ' more';
        }

        // ---- Title ----
        if ($count === 1) {
            // Single post: title is the item name itself (BigPictureStyle).
            $title = $this->nameWithBulk($posts[0]['message']);
        } else {
            $title = $count . ' new things near you';
        }

        // ---- Photo URLs ----
        // image  = first post's photo (single-post BigPicture / large icon / old apps).
        // images = up to 4 photo URLs across the top posts; the app tiles these into a
        //          collage for the multi-post expanded view (needs >=2, else it falls back
        //          to the text list).
        //
        // Prefer real (user-uploaded) photos over AI illustrations: collect real photos
        // first (in digest order), then pad the remaining collage slots with AI photos
        // only when there aren't enough real ones. The single `image` follows the same
        // preference (real photos come first in the merged list).
        $realUrls = [];
        $aiUrls   = [];
        foreach ($posts as $item) {
            // Once four real photos are found they fill the whole collage, so there is
            // no need to keep scanning for AI padding.
            if (count($realUrls) >= 4) {
                break;
            }
            $img = $this->extractPostImage($item['message']);
            if (! $img) {
                continue;
            }
            if ($img['ai']) {
                if (count($aiUrls) < 4) {
                    $aiUrls[] = $img['url'];
                }
            } else {
                $realUrls[] = $img['url'];
            }
        }
        $imageUrls = array_slice(array_merge($realUrls, $aiUrls), 0, 4);
        $imageUrl  = $imageUrls[0] ?? null;

        // The app-icon badge must reflect the user's actionable unread items
        // (unread chats + unseen notifications), NOT the number of posts in this
        // informational digest. Previously this was `(string) $count`, so a daily
        // "new posts near you" push set the FD badge to the post count and made it
        // look like there was unread activity to deal with. Use the real unread
        // total so the digest never inflates the badge.
        [$chatcount, $notifcount] = $this->consumerUnreadCounts($userId);
        $badge = $chatcount + $notifcount;

        return [
            'channel_id'        => 'new_posts',
            'category'          => self::CATEGORY_NEW_POSTS,
            'notId'             => self::NEW_POSTS_NOT_ID,
            'count'             => (string) $count,
            'title'             => $title,
            'message'           => $message,
            'route'             => '/browse',
            'image'             => (string) ($imageUrl ?? ''),
            'images'            => json_encode(array_values($imageUrls)),
            'lines'             => json_encode($lines),
            'summary'           => 'Freegle • ' . $count . ' new post' . ($count === 1 ? '' : 's'),
            'moreCount'         => (string) $moreCount,
            'timestamp'         => (string) time(),
            'badge'             => (string) $badge,
            'content-available' => '1',
            'modtools'          => 'false',
        ];
    }

    /**
     * Format a single post into a short line for the InboxStyle lines[] array.
     *
     * Pattern: "Offer: {ItemName} ({LocationName})"
     * Mirrors V1's notification content and the app's chat-list preview style.
     */
    private function formatPostLine(\App\Models\Message $msg): string
    {
        $type = ucfirst(strtolower((string) ($msg->type ?? 'Offer')));
        $name = $this->nameWithBulk($msg);
        $location = $this->extractLocationName($msg);

        if ($location !== '') {
            return "{$type}: {$name} ({$location})";
        }

        return "{$type}: {$name}";
    }

    /**
     * Extract the bare item name from a message subject.
     *
     * V1 PostNotifications regex (sendToUser): strips "OFFER:" / "WANTED:" prefix
     * and any trailing "(Location)" suffix, returning the item name only.
     *
     * Subject format: "OFFER: Sofa (Kingston)" → "Sofa"
     */
    private function extractItemName(\App\Models\Message $msg): string
    {
        $subject = trim((string) ($msg->subject ?? ''));

        // Remove "OFFER:" / "WANTED:" prefix.
        $name = preg_replace('/^(?:OFFER|WANTED)\s*:\s*/i', '', $subject);

        // Remove trailing "(Location)" suffix.
        $name = preg_replace('/\s*\([^)]+\)\s*$/', '', (string) $name);

        return trim((string) $name) ?: $subject;
    }

    /**
     * Number of catalogue items if this is a bulk offer ("clearance"), else 0.
     */
    private function bulkItemCount(\App\Models\Message $msg): int
    {
        return (int) \Illuminate\Support\Facades\DB::table('messages_bulk_items')
            ->where('msgid', $msg->id)
            ->count();
    }

    /**
     * Item name, decorated for a clearance so the push makes clear it's a
     * multi-item offer: "Office clearance — 12 items".
     */
    private function nameWithBulk(\App\Models\Message $msg): string
    {
        $name = $this->extractItemName($msg);
        $count = $this->bulkItemCount($msg);

        return $count > 0 ? "{$name} — {$count} items" : $name;
    }

    /**
     * Extract the location name from a message subject's trailing parenthetical.
     *
     * Subject format: "OFFER: Sofa (Kingston)" → "Kingston"
     */
    private function extractLocationName(\App\Models\Message $msg): string
    {
        $subject = trim((string) ($msg->subject ?? ''));

        if (preg_match('/\(([^)]+)\)\s*$/', $subject, $m)) {
            return trim($m[1]);
        }

        return '';
    }

    /**
     * Return ['url' => string, 'ai' => bool] for the best usable attachment on a
     * post (real photo preferred over AI illustration), or null if it has no photo.
     *
     * Mirrors UnifiedDigest::getMessageImageUrl() but returns the raw source
     * URL (no delivery-service resizing) so that FCM stores it on its CDN and
     * the NSE / Android BigPicture can fetch it directly. Push images are small
     * (Android BigPicture ≤ 2 MB, iOS NSE ≤ 10 MB); the delivery service's
     * resizing step is unnecessary and adds a round-trip that can time out in
     * the NSE's short execution window.
     */
    private function extractPostImage(\App\Models\Message $msg): ?array
    {
        if (! $msg->attachments || $msg->attachments->isEmpty()) {
            return null;
        }

        // Skip attachments that haven't finished uploading (no
        // externaluid/externalurl/archived yet — same filter as prepareCard).
        $usable = $msg->attachments
            ->filter(fn ($a) => ! empty($a->externaluid) || ! empty($a->externalurl) || (int) ($a->archived ?? 0) === 1);

        if ($usable->isEmpty()) {
            return null;
        }

        // Within a post, prefer a real (non-AI) photo over an AI illustration,
        // then prefer primary=1. A post that transiently carries both contributes
        // its real photo. Rank ascending: real+primary=0, real=1, ai+primary=2, ai=3.
        $attachment = $usable
            ->sortBy(fn ($a) => ($this->attachmentIsAi($a) ? 2 : 0) + ($a->primary ? 0 : 1))
            ->first();

        if (! empty($attachment->externalurl)) {
            $url = $attachment->externalurl;
        } else {
            $imagesDomain = config('freegle.images.domain', 'https://images.ilovefreegle.org');
            $url          = "{$imagesDomain}/img_{$attachment->id}.jpg";
        }

        return ['url' => $url, 'ai' => $this->attachmentIsAi($attachment)];
    }

    /**
     * Whether an attachment is an AI-generated illustration. The flag lives in the
     * externalmods JSON as {"ai": true} (see the legacy V1 PHP messages_illustrations cron script).
     */
    private function attachmentIsAi(\App\Models\MessageAttachment $attachment): bool
    {
        if (empty($attachment->externalmods)) {
            return false;
        }

        $mods = json_decode($attachment->externalmods, true);

        return is_array($mods) && ($mods['ai'] ?? false) === true;
    }
}
