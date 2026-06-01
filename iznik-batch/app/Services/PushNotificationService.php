<?php

namespace App\Services;

use App\Models\ChatRoom;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;

/**
 * Service for sending push notifications via Firebase Cloud Messaging.
 *
 * Ported from iznik-server PushNotifications.php.
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

    private $messaging = null;

    public function __construct()
    {
        $credentialsPath = config('freegle.firebase.credentials_path', '/etc/firebase.json');

        if (file_exists($credentialsPath)) {
            try {
                $factory = (new Factory)->withServiceAccount($credentialsPath);
                $this->messaging = $factory->createMessaging();
            } catch (\Throwable $e) {
                Log::warning('Failed to initialize Firebase', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
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
            Log::debug('Firebase not configured, skipping push notification', [
                'user_id' => $userId,
            ]);

            return 0;
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
     * mirroring iznik-server PushNotifications::notify($userid, FALSE) +
     * User::getNotificationPayload(FALSE). Used after creating an onsite
     * notification (e.g. the Exhort nudge) so the device badge/banner updates.
     *
     * Returns the number of FD devices notified.
     */
    public function notifyUser(int $userId): int
    {
        if (! $this->messaging) {
            Log::debug('Firebase not configured, skipping user push notification', ['user_id' => $userId]);

            return 0;
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
    public function buildUserNotificationPayload(int $userId): array
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
            ))', [$userId])
            ->count();

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
     * - Excludes deleted (mg.deleted = 0) and system messages (fromuser IS NOT NULL)
     *
     * This prevents phantom badges caused by held messages, deleted messages, or
     * work from inactive groups inflating the count while the app shows nothing.
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
        // Get all approved mod/owner memberships with settings to determine active/inactive.
        $memberships = DB::select(
            "SELECT groupid, settings FROM memberships
             WHERE userid = ? AND role IN ('Owner', 'Moderator') AND collection = 'Approved'",
            [$userId]
        );

        if (empty($memberships)) {
            return 0;
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
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($activeGroupIds), '?'));

        // Unheld pending messages in active groups.
        $pendingParams = array_merge([$userId], $activeGroupIds);
        $pending = DB::selectOne(
            "SELECT COUNT(*) as cnt FROM messages_groups mg
             INNER JOIN messages m ON m.id = mg.msgid
             INNER JOIN memberships mem ON mem.groupid = mg.groupid AND mem.userid = ?
             WHERE mem.role IN ('Owner', 'Moderator')
             AND mem.collection = 'Approved'
             AND mg.collection = 'Pending'
             AND mg.groupid IN ({$placeholders})
             AND mg.deleted = 0
             AND m.fromuser IS NOT NULL
             AND m.heldby IS NULL",
            $pendingParams
        );

        // Spam collection messages in active groups.
        $spamParams = array_merge([$userId], $activeGroupIds);
        $spam = DB::selectOne(
            "SELECT COUNT(*) as cnt FROM messages_groups mg
             INNER JOIN messages m ON m.id = mg.msgid
             INNER JOIN memberships mem ON mem.groupid = mg.groupid AND mem.userid = ?
             WHERE mem.role IN ('Owner', 'Moderator')
             AND mem.collection = 'Approved'
             AND mg.collection = 'Spam'
             AND mg.groupid IN ({$placeholders})
             AND mg.deleted = 0
             AND m.fromuser IS NOT NULL",
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

        return ($pending->cnt ?? 0) + ($spam->cnt ?? 0) + ($volunteering->cnt ?? 0);
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
     * For ModTools, we send a simple "pending messages" notification.
     * Matches legacy User::getNotificationPayload(modtools=true).
     */
    private function buildModToolsPayload(int $userId): ?array
    {
        $total = $this->getBadgeCount($userId);

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
                'route' => '/modtools',
                'channel_id' => 'modtools',
                'notId' => (string) $userId,
            ];
        }

        $title = "$total message" . ($total > 1 ? 's' : '') . " pending";
        $message = "Open ModTools to review";

        return [
            'badge' => (string) $total,
            'count' => (string) $total,
            'chatcount' => '0',
            'notifcount' => (string) $total,
            'title' => $title,
            'message' => $message,
            'chatids' => '',
            'content-available' => '1',
            'image' => 'www/images/modtools_logo.png',
            'modtools' => '1',
            'sound' => 'default',
            'route' => '/modtools/messages/pending',
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
            $message = $message->withApnsConfig([
                'headers' => [
                    'apns-priority' => '10',
                ],
                'payload' => [
                    'aps' => [
                        'badge' => $badge,
                        'sound' => 'default',
                    ],
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
            return 0;
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
     * Compute push recipients for a chat message, mirroring V1
     * ChatRoom::notifyMembers() (iznik-server/include/chat/ChatRoom.php:1458).
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
     * Mirrors V1 PushNotifications::notifyIndividualMessages payload shape
     * (iznik-server/include/user/PushNotifications.php:474). Key fields the
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
            ->select('cm.id as msgid', 'cm.message', 'cm.date',
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
     * Title shown in the push banner. For U2M chats sent by a moderator to
     * a member, hide the mod identity and show "{Group} Volunteers" (V1).
     */
    private function resolveChatPushTitle(object $row): string
    {
        $senderName = $row->sender_name ?: 'Someone';

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

        return $senderName;
    }
}
