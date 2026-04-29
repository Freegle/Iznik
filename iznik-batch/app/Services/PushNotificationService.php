<?php

namespace App\Services;

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

                // Remove invalid/unregistered tokens
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
            ];
        }

        $title = "$total message" . ($total > 1 ? 's' : '') . " pending";
        $message = "$total pending";

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
            // Fixed notId per user so each new notification replaces the previous one
            // on Android instead of stacking multiple "N pending" badges.
            'notId' => (string) $userId,
        ];
    }

    /**
     * Send FCM notification to a device.
     */
    private function sendFcm(int $userId, string $type, string $token, array $payload): void
    {
        if ($type === self::PUSH_FCM_ANDROID) {
            // Android: data-only message (no notification block)
            $message = CloudMessage::fromArray([
                'token' => $token,
                'data' => $payload,
            ]);

            $message = $message->withAndroidConfig([
                'ttl' => '3600s',
                'priority' => 'normal',
            ]);
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
}
