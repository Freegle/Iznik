<?php

namespace App\Services;

use App\Mail\Notification\ChaseUpMail;
use App\Mail\Traits\AvatarResolver;
use App\Mail\Traits\FeatureFlags;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class NotificationChaseUpService
{
    use AvatarResolver;
    use FeatureFlags;

    public const EMAIL_TYPE = 'NotificationChaseUp';

    /**
     * How far back to look for notifications (oldest that qualify).
     */
    public const DEFAULT_SINCE_HOURS = 24;

    /**
     * Minimum age before sending — gives the user time to see the notification first.
     */
    public const DEFAULT_BEFORE_MINUTES = 5;

    /**
     * How long before a user is considered inactive (6 months).
     */
    private const INACTIVE_DAYS = 183;

    /**
     * Notification types that are never included in chaseup emails (mirrors V1).
     */
    public const EXCLUDED_TYPES = ['TryFeed', 'AboutMe', 'GiftAid', 'OpenPosts'];

    /**
     * Spam-user collection types — notifications from these users are excluded.
     */
    private const SPAM_COLLECTIONS = ['Spammer', 'PendingAdd'];

    /**
     * Send chaseup emails for unmailed, unseen notifications.
     *
     * Mirrors V1 Notifications::sendEmails() called from notification_chaseup.php.
     */
    public function sendEmails(
        ?int $userId = null,
        int $beforeMinutes = self::DEFAULT_BEFORE_MINUTES,
        int $sinceHours = self::DEFAULT_SINCE_HOURS,
        bool $dryRun = false
    ): int {
        if (! self::isEmailTypeEnabled(self::EMAIL_TYPE)) {
            Log::info('NotificationChaseUp emails disabled via FREEGLE_MAIL_ENABLED_TYPES');
            return 0;
        }

        $users = $this->getUsersWithPendingNotifications($userId, $beforeMinutes, $sinceHours);

        $total = 0;

        foreach ($users as $user) {
            $total += $this->processUser($user, $dryRun);
        }

        return $total;
    }

    /**
     * Find users with unmailed, unseen notifications in the given time window,
     * excluding spammers (from-user check, same as V1 SQL JOIN).
     */
    private function getUsersWithPendingNotifications(
        ?int $userId,
        int $beforeMinutes,
        int $sinceHours
    ): Collection {
        $before = now()->subMinutes($beforeMinutes);
        $since  = now()->subHours($sinceHours);

        $query = DB::table('users_notifications')
            ->leftJoin('spam_users', function ($join) {
                $join->on('spam_users.userid', '=', 'users_notifications.fromuser')
                    ->whereIn('spam_users.collection', self::SPAM_COLLECTIONS);
            })
            ->join('users', 'users.id', '=', 'users_notifications.touser')
            ->where('users_notifications.timestamp', '<=', $before)
            ->where('users_notifications.timestamp', '>=', $since)
            ->where('users_notifications.seen', 0)
            ->where('users_notifications.mailed', 0)
            ->whereNotIn('users_notifications.type', self::EXCLUDED_TYPES)
            ->whereNull('spam_users.userid')
            ->whereNull('users.deleted')
            ->select('users_notifications.touser')
            ->distinct();

        if ($userId !== null) {
            $query->where('users_notifications.touser', $userId);
        }

        $userIds = $query->pluck('touser');

        return User::whereIn('id', $userIds)->get();
    }

    /**
     * Process a single user: check eligibility, prepare notifications, send email.
     */
    private function processUser(User $user, bool $dryRun): int
    {
        if (! $this->isUserEligible($user)) {
            return 0;
        }

        if (! $this->wantsNotificationEmails($user)) {
            return 0;
        }

        $notifications = $this->getUserNotifications($user->id);
        if ($notifications->isEmpty()) {
            return 0;
        }

        $notifData = $this->prepareNotifications($notifications);
        if (empty($notifData)) {
            return 0;
        }

        $subject = $this->getNotifTitle($notifData);
        if (! $subject) {
            return 0;
        }

        $email = $user->email_preferred;
        if (! $email) {
            return 0;
        }

        if (! $dryRun) {
            // Mark as mailed before sending (like V1).
            $notifIds = array_column($notifData, 'id');
            DB::table('users_notifications')
                ->whereIn('id', $notifIds)
                ->update(['mailed' => 1]);

            Mail::to($email)->send(new ChaseUpMail($user, $notifData, $subject));
        }

        return 1;
    }

    /**
     * Check whether the user should receive emails at all.
     * Mirrors V1 User::sendOurMails() checks.
     */
    private function isUserEligible(User $user): bool
    {
        if ($user->deleted) {
            return false;
        }

        if ($user->getSimpleMail() === User::SIMPLE_MAIL_NONE) {
            return false;
        }

        // Must have been active in the last ~6 months.
        if (! $user->lastaccess || $user->lastaccess->diffInDays(now()) > self::INACTIVE_DAYS) {
            return false;
        }

        // Not on holiday.
        if ($user->onholidaytill && now()->lt($user->onholidaytill)) {
            return false;
        }

        // Not bouncing.
        if ($user->bouncing) {
            return false;
        }

        return true;
    }

    /**
     * Check the user's notification-mail preference (V1 settings.notificationmails, default true).
     */
    private function wantsNotificationEmails(User $user): bool
    {
        $settings = $user->settings ?? [];
        return (bool) ($settings['notificationmails'] ?? true);
    }

    /**
     * Fetch the latest unseen notifications for this user (mirrors V1 Notifications::get()).
     */
    private function getUserNotifications(int $userId): Collection
    {
        return Notification::where('touser', $userId)
            ->where('seen', 0)
            ->where('mailed', 0)
            ->orderByDesc('id')
            ->limit(10)
            ->get();
    }

    /**
     * Enrich notification rows with from-user display names and newsfeed content.
     * Skips deleted items. Returns array suitable for template rendering.
     */
    private function prepareNotifications(Collection $notifications): array
    {
        // Bulk-load from-users.
        $fromUserIds = $notifications->pluck('fromuser')->filter()->unique()->values()->toArray();
        $fromUsers = User::whereIn('id', $fromUserIds)->select('id', 'fullname')->get()->keyBy('id');

        // Bulk-load newsfeed items and their reply-tos.
        $newsfeedIds = $notifications->pluck('newsfeedid')->filter()->unique()->values()->toArray();
        $newsfeeds = [];

        if (! empty($newsfeedIds)) {
            $rows = DB::table('newsfeed')->whereIn('id', $newsfeedIds)->get();

            foreach ($rows as $row) {
                $newsfeeds[$row->id] = $row;
            }

            $replytoIds = $rows->pluck('replyto')->filter()->unique()->values()->toArray();

            if (! empty($replytoIds)) {
                $replyRows = DB::table('newsfeed')->whereIn('id', $replytoIds)->get();
                foreach ($replyRows as $row) {
                    $newsfeeds[$row->id] = $row;
                }
            }
        }

        $result = [];

        foreach ($notifications as $notif) {
            if (in_array($notif->type, self::EXCLUDED_TYPES, true)) {
                continue;
            }

            $newsfeed = $notif->newsfeedid ? ($newsfeeds[$notif->newsfeedid] ?? null) : null;

            // Skip if the newsfeed item has been deleted.
            if ($newsfeed && $newsfeed->deleted) {
                continue;
            }

            // Skip if the thread being replied to has been deleted.
            $replyto = null;
            if ($newsfeed && $newsfeed->replyto) {
                $replyto = $newsfeeds[$newsfeed->replyto] ?? null;
                if ($replyto && $replyto->deleted) {
                    continue;
                }
            }

            $fromUser = isset($fromUsers[$notif->fromuser]) ? $fromUsers[$notif->fromuser] : null;

            $result[] = [
                'id'        => $notif->id,
                'type'      => $notif->type,
                'fromname'  => $fromUser ? $fromUser->fullname : 'Someone',
                'fromimage' => $this->resolveAvatarUrl($fromUser),
                'title'    => $notif->title,
                'text'     => $notif->text,
                'url'      => $notif->url,
                'timestamp' => \Carbon\Carbon::parse($notif->timestamp, 'UTC')
                                   ->setTimezone('Europe/London')
                                   ->format('D, jS F g:ia'),
                'seen'     => $notif->seen,
                'newsfeed' => $newsfeed ? [
                    'id'      => $newsfeed->id,
                    'type'    => $newsfeed->type ?? 'Message',
                    'message' => $this->snip($newsfeed->message),
                    'replyto' => $replyto ? [
                        'id'      => $replyto->id,
                        'message' => $this->snip($replyto->message),
                    ] : null,
                ] : null,
            ];
        }

        return $result;
    }

    /**
     * Generate the email subject line. Mirrors V1 Notifications::getNotifTitle().
     *
     * Priority: CommentOnCommented > CommentOnYourPost > LovedPost/LovedComment > Exhort.
     * If multiple notifications, appends "+ N more...".
     */
    public function getNotifTitle(array $notifs): string
    {
        $title = '';
        $count = 0;

        foreach ($notifs as $notif) {
            if ($notif['type'] === 'TryFeed') {
                continue;
            }

            $fromname = $notif['fromname'];
            $shortmsg = null;

            if (
                ! empty($notif['newsfeed']['message']) &&
                ($notif['newsfeed']['type'] ?? '') !== 'Noticeboard'
            ) {
                $msg      = $notif['newsfeed']['message'];
                $shortmsg = strlen($msg) > 30 ? (substr($msg, 0, 30) . '...') : $msg;
            }

            switch ($notif['type']) {
                case 'CommentOnCommented':
                    $title = "{$fromname} replied: {$shortmsg}";
                    $count++;
                    break;
                case 'CommentOnYourPost':
                    $title = "{$fromname} commented: {$shortmsg}";
                    $count++;
                    break;
                case 'LovedPost':
                    if (! $title) {
                        $title = "{$fromname} loved your post" . ($shortmsg ? " '{$shortmsg}'" : '');
                    }
                    $count++;
                    break;
                case 'LovedComment':
                    if (! $title) {
                        $title = "{$fromname} loved your comment" . ($shortmsg ? " '{$shortmsg}'" : '');
                    }
                    $count++;
                    break;
                case 'AboutMe':
                    if (! $title) {
                        $title = "Why not introduce yourself to other freeglers? You'll get a better response.";
                    }
                    $count++;
                    break;
                case 'Exhort':
                    if (! $title) {
                        $title = $notif['title'] ?? '';
                    }
                    $count++;
                    break;
                case 'MembershipPending':
                    if (! $title) {
                        $title = "Your application to {$notif['url']} requires approval";
                    }
                    $count++;
                    break;
                case 'MembershipApproved':
                    if (! $title) {
                        $title = "Your application to {$notif['url']} has been approved!";
                    }
                    $count++;
                    break;
                case 'MembershipRejected':
                    if (! $title) {
                        $title = "Sorry, your application to {$notif['url']} was rejected";
                    }
                    $count++;
                    break;
            }
        }

        if ($count > 1) {
            $title = $title . ' +' . ($count - 1) . ' more...';
        }

        return $title;
    }

    /**
     * Truncate a message to ~57 chars at a word boundary, matching V1 Notifications::snip().
     */
    private function snip(?string $msg): ?string
    {
        if (! $msg) {
            return $msg;
        }

        if (strlen($msg) > 57) {
            $msg = wordwrap($msg, 60);
            $p   = strpos($msg, "\n");
            $msg = ($p !== false) ? substr($msg, 0, $p) : $msg;
            $msg .= '...';
        }

        return $msg;
    }
}
