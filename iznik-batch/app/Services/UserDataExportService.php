<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Process pending GDPR data export requests (users_exports.completed IS NULL).
 *
 * Mirrors V1 cron/exports.php + User::export().
 *
 * For each pending export:
 * - Mark as started
 * - Assemble all user data into a structured array
 * - Compress as gzdeflate'd JSON and store in users_exports.data
 * - Mark as completed
 *
 * Also purges data (sets data = NULL) for exports completed more than 7 days ago.
 */
class UserDataExportService
{
    public function processAll(): int
    {
        $this->purgeOldExportData();

        $exports = DB::table('users_exports')
            ->whereNull('completed')
            ->orderBy('id', 'asc')
            ->get();

        $count = 0;

        foreach ($exports as $export) {
            try {
                $this->processExport($export);
                $count++;
            } catch (\Throwable $e) {
                Log::error("UserDataExport: export {$export->id} failed", [
                    'userid' => $export->userid,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info("UserDataExport: processed {$count} exports");

        return $count;
    }

    private function purgeOldExportData(): void
    {
        DB::table('users_exports')
            ->whereNotNull('completed')
            ->where('completed', '<', now()->subDays(7))
            ->update(['data' => null]);
    }

    private function processExport(object $export): void
    {
        $userId = $export->userid;
        $exportId = $export->id;
        $tag = $export->tag;

        DB::table('users_exports')
            ->where('id', $exportId)
            ->where('tag', $tag)
            ->update(['started' => now()]);

        $data = $this->assembleUserData($userId);

        $json = json_encode($data, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $compressed = gzdeflate($json);

        DB::table('users_exports')
            ->where('id', $exportId)
            ->where('tag', $tag)
            ->update([
                'completed' => now(),
                'data' => $compressed,
            ]);

        Log::info("UserDataExport: completed export {$exportId}", ['userid' => $userId]);
    }

    private function assembleUserData(int $userId): array
    {
        $data = [];

        $data['basic'] = $this->getBasicInfo($userId);
        $data['emails'] = $this->getEmails($userId);
        $data['logins'] = $this->getLogins($userId);
        $data['invitations'] = $this->getInvitations($userId);
        $data['memberships'] = $this->getMemberships($userId);
        $data['memberships_history'] = $this->getMembershipHistory($userId);
        $data['searches'] = $this->getSearches($userId);
        $data['alerts'] = $this->getAlerts($userId);
        $data['donations'] = $this->getDonations($userId);
        $data['giftaid'] = $this->getGiftAid($userId);
        $data['bans'] = $this->getBans($userId);
        $data['spam_reports'] = $this->getSpamReports($userId);
        $data['spam_domains'] = $this->getSpamDomains($userId);
        $data['images'] = $this->getImages($userId);
        $data['notifications'] = $this->getNotifications($userId);
        $data['addresses'] = $this->getAddresses($userId);
        $data['community_events'] = $this->getCommunityEvents($userId);
        $data['volunteering'] = $this->getVolunteering($userId);
        $data['comments'] = $this->getComments($userId);
        $data['ratings'] = $this->getRatings($userId);
        $data['excluded_locations'] = $this->getExcludedLocations($userId);
        $data['messages'] = $this->getMessages($userId);
        $data['chats'] = $this->getChats($userId);
        $data['newsfeed'] = $this->getNewsfeed($userId);
        $data['aboutme'] = $this->getAboutMe($userId);
        $data['stories'] = $this->getStories($userId);
        $data['exports'] = $this->getExportHistory($userId);
        $data['logs'] = $this->getLogs($userId);

        return $data;
    }

    private function getBasicInfo(int $userId): array
    {
        $user = DB::table('users')->where('id', $userId)->first();

        if (!$user) {
            return ['id' => $userId];
        }

        $d = [
            'id' => $user->id,
            'fullname' => $user->fullname,
            'firstname' => $user->firstname,
            'lastname' => $user->lastname,
            'systemrole' => $user->systemrole,
            'added' => $user->added,
            'lastaccess' => $user->lastaccess,
            'bouncing' => $user->bouncing ? 'Yes' : 'No',
            'permissions' => $user->permissions,
            'invitesleft' => $user->invitesleft,
        ];

        if ($user->lastlocation) {
            $loc = DB::table('locations')->where('id', $user->lastlocation)->first();
            if ($loc) {
                $d['last_posted_location'] = "{$loc->name} ({$loc->lat}, {$loc->lng})";
            }
        }

        return $d;
    }

    private function getEmails(int $userId): array
    {
        return DB::table('users_emails')
            ->where('userid', $userId)
            ->select('email', 'preferred', 'added', 'validated', 'bounced')
            ->orderBy('id', 'asc')
            ->get()
            ->map(fn($e) => (array) $e)
            ->toArray();
    }

    private function getLogins(int $userId): array
    {
        return DB::table('users_logins')
            ->where('userid', $userId)
            ->select('type', 'uid', 'added', 'lastaccess')
            ->orderBy('added', 'asc')
            ->get()
            ->map(fn($e) => (array) $e)
            ->toArray();
    }

    private function getInvitations(int $userId): array
    {
        return DB::table('users_invitations')
            ->where('userid', $userId)
            ->select('email', 'date')
            ->orderBy('date', 'asc')
            ->get()
            ->map(fn($e) => (array) $e)
            ->toArray();
    }

    private function getMemberships(int $userId): array
    {
        return DB::table('memberships')
            ->join('groups', 'memberships.groupid', '=', 'groups.id')
            ->where('memberships.userid', $userId)
            ->select(
                'memberships.groupid',
                'memberships.collection',
                'memberships.added',
                DB::raw('COALESCE(groups.namefull, groups.nameshort) AS groupname')
            )
            ->orderBy('memberships.added', 'asc')
            ->get()
            ->map(fn($e) => (array) $e)
            ->toArray();
    }

    private function getMembershipHistory(int $userId): array
    {
        return DB::table('memberships_history')
            ->join('groups', 'memberships_history.groupid', '=', 'groups.id')
            ->where('memberships_history.userid', $userId)
            ->select(
                'memberships_history.groupid',
                'memberships_history.collection',
                'memberships_history.added',
                DB::raw('COALESCE(groups.namefull, groups.nameshort) AS groupname')
            )
            ->orderBy('memberships_history.added', 'asc')
            ->get()
            ->map(fn($e) => (array) $e)
            ->toArray();
    }

    private function getSearches(int $userId): array
    {
        return DB::table('search_history')
            ->leftJoin('locations', 'search_history.locationid', '=', 'locations.id')
            ->where('search_history.userid', $userId)
            ->select('search_history.date', 'search_history.term', 'locations.name as location')
            ->orderBy('search_history.date', 'asc')
            ->get()
            ->map(fn($e) => (array) $e)
            ->toArray();
    }

    private function getAlerts(int $userId): array
    {
        return DB::table('alerts_tracking')
            ->join('alerts', 'alerts_tracking.alertid', '=', 'alerts.id')
            ->where('alerts_tracking.userid', $userId)
            ->whereNotNull('alerts_tracking.responded')
            ->select('alerts.subject', 'alerts_tracking.responded', 'alerts_tracking.response')
            ->orderBy('alerts_tracking.responded', 'asc')
            ->get()
            ->map(fn($e) => (array) $e)
            ->toArray();
    }

    private function getDonations(int $userId): array
    {
        return DB::table('users_donations')
            ->where('userid', $userId)
            ->orderBy('timestamp', 'asc')
            ->get()
            ->map(fn($e) => (array) $e)
            ->toArray();
    }

    private function getGiftAid(int $userId): array
    {
        return DB::table('giftaid')
            ->where('userid', $userId)
            ->orderBy('timestamp', 'asc')
            ->get()
            ->map(fn($e) => (array) $e)
            ->toArray();
    }

    private function getBans(int $userId): array
    {
        $bans = DB::table('users_banned')
            ->leftJoin('groups', 'users_banned.groupid', '=', 'groups.id')
            ->leftJoin('users_emails', function ($join) {
                $join->on('users_banned.userid', '=', 'users_emails.userid')
                    ->where('users_emails.preferred', '=', 1);
            })
            ->where('users_banned.byuser', $userId)
            ->select(
                'users_banned.date',
                'users_banned.userid',
                'users_emails.email',
                DB::raw('COALESCE(groups.namefull, groups.nameshort) AS groupname')
            )
            ->orderBy('users_banned.date', 'asc')
            ->get()
            ->map(fn($e) => (array) $e)
            ->toArray();

        return $bans;
    }

    private function getSpamReports(int $userId): array
    {
        return DB::table('spam_users')
            ->where('byuserid', $userId)
            ->leftJoin('users_emails', function ($join) {
                $join->on('spam_users.userid', '=', 'users_emails.userid')
                    ->where('users_emails.preferred', '=', 1);
            })
            ->select('spam_users.userid', 'spam_users.added', 'spam_users.reason', 'users_emails.email')
            ->orderBy('spam_users.added', 'asc')
            ->get()
            ->map(fn($e) => (array) $e)
            ->toArray();
    }

    private function getSpamDomains(int $userId): array
    {
        return DB::table('spam_whitelist_links')
            ->where('userid', $userId)
            ->select('domain', 'date')
            ->orderBy('date', 'asc')
            ->get()
            ->map(fn($e) => (array) $e)
            ->toArray();
    }

    private function getImages(int $userId): array
    {
        return DB::table('users_images')
            ->where('userid', $userId)
            ->select('id', 'url')
            ->get()
            ->map(fn($e) => (array) $e)
            ->toArray();
    }

    private function getNotifications(int $userId): array
    {
        return DB::table('users_notifications')
            ->where('touser', $userId)
            ->where('seen', 1)
            ->select('timestamp', 'url')
            ->orderBy('timestamp', 'asc')
            ->get()
            ->map(fn($e) => (array) $e)
            ->toArray();
    }

    private function getAddresses(int $userId): array
    {
        return DB::table('users_addresses')
            ->where('userid', $userId)
            ->select('id', 'pafid', 'lat', 'lng')
            ->get()
            ->map(fn($e) => (array) $e)
            ->toArray();
    }

    private function getCommunityEvents(int $userId): array
    {
        return DB::table('communityevents')
            ->where('userid', $userId)
            ->select('id', 'title', 'description', 'location', 'added')
            ->orderBy('added', 'asc')
            ->get()
            ->map(fn($e) => (array) $e)
            ->toArray();
    }

    private function getVolunteering(int $userId): array
    {
        return DB::table('volunteering')
            ->where('userid', $userId)
            ->select('id', 'title', 'description', 'location', 'added')
            ->orderBy('added', 'asc')
            ->get()
            ->map(fn($e) => (array) $e)
            ->toArray();
    }

    private function getComments(int $userId): array
    {
        // users_comments columns are user1-user11 (one per flag/comment category).
        // Export the flag status and comment text columns.
        return DB::table('users_comments')
            ->where('byuserid', $userId)
            ->leftJoin('users_emails', function ($join) {
                $join->on('users_comments.userid', '=', 'users_emails.userid')
                    ->where('users_emails.preferred', '=', 1);
            })
            ->select(
                'users_comments.userid',
                'users_emails.email',
                'users_comments.date',
                'users_comments.flag',
                'users_comments.user1',
                'users_comments.user2',
                'users_comments.user3'
            )
            ->orderBy('users_comments.date', 'asc')
            ->get()
            ->map(fn($e) => (array) $e)
            ->toArray();
    }

    private function getRatings(int $userId): array
    {
        return DB::table('ratings')
            ->where('ratings.rater', $userId)
            ->leftJoin('users_emails', function ($join) {
                $join->on('ratings.ratee', '=', 'users_emails.userid')
                    ->where('users_emails.preferred', '=', 1);
            })
            ->select('ratings.ratee', 'users_emails.email', 'ratings.rating', 'ratings.timestamp')
            ->orderBy('ratings.timestamp', 'asc')
            ->get()
            ->map(fn($e) => (array) $e)
            ->toArray();
    }

    private function getExcludedLocations(int $userId): array
    {
        return DB::table('locations_excluded')
            ->where('locations_excluded.userid', $userId)
            ->leftJoin('groups', 'locations_excluded.groupid', '=', 'groups.id')
            ->leftJoin('locations', 'locations_excluded.locationid', '=', 'locations.id')
            ->select(
                DB::raw('COALESCE(groups.namefull, groups.nameshort) AS groupname'),
                'locations.name as location',
                'locations_excluded.date'
            )
            ->orderBy('locations_excluded.date', 'asc')
            ->get()
            ->map(fn($e) => (array) $e)
            ->toArray();
    }

    private function getMessages(int $userId): array
    {
        return DB::table('messages')
            ->join('messages_groups', 'messages.id', '=', 'messages_groups.msgid')
            ->join('groups', 'messages_groups.groupid', '=', 'groups.id')
            ->where('messages.fromuser', $userId)
            ->select(
                'messages.id',
                'messages.subject',
                'messages.type',
                'messages.arrival',
                'messages_groups.collection',
                'messages_groups.groupid',
                DB::raw('COALESCE(groups.namefull, groups.nameshort) AS groupname')
            )
            ->orderBy('messages.arrival', 'asc')
            ->get()
            ->map(fn($e) => (array) $e)
            ->toArray();
    }

    private function getChats(int $userId): array
    {
        $chatIds = DB::select(
            "SELECT DISTINCT chat_rooms.id FROM chat_rooms
             INNER JOIN (
                 SELECT DISTINCT chatid FROM chat_roster WHERE userid = ?
                 UNION
                 SELECT DISTINCT chatid FROM chat_messages WHERE userid = ? OR reviewedby = ?
             ) t ON t.chatid = chat_rooms.id
             ORDER BY chat_rooms.id ASC",
            [$userId, $userId, $userId]
        );

        $chats = [];

        foreach ($chatIds as $row) {
            $chatId = $row->id;

            $room = DB::table('chat_rooms')->where('id', $chatId)->first();
            if (!$room) {
                continue;
            }

            $roster = DB::table('chat_roster')
                ->where('chatid', $chatId)
                ->where('userid', $userId)
                ->select('date', 'lastip')
                ->first();

            $messages = DB::table('chat_messages')
                ->where('chatid', $chatId)
                ->where(function ($q) use ($userId) {
                    $q->where('userid', $userId)->orWhere('reviewedby', $userId);
                })
                ->select('id', 'date', 'message', 'type', 'userid', 'reviewedby')
                ->orderBy('date', 'asc')
                ->get()
                ->map(fn($m) => (array) $m)
                ->toArray();

            if (count($messages) > 0) {
                $chats[] = [
                    'id' => $chatId,
                    'chattype' => $room->chattype,
                    'date' => $roster ? $roster->date : null,
                    'lastip' => $roster ? $roster->lastip : null,
                    'messages' => $messages,
                ];
            }
        }

        return $chats;
    }

    private function getNewsfeed(int $userId): array
    {
        $items = DB::table('newsfeed')
            ->where('userid', $userId)
            ->select('id', 'timestamp', 'type', 'message', 'msgid', 'groupid')
            ->orderBy('timestamp', 'asc')
            ->get()
            ->map(fn($e) => (array) $e)
            ->toArray();

        $unfollows = DB::table('newsfeed_unfollow')
            ->where('userid', $userId)
            ->select('newsfeedid', 'timestamp')
            ->orderBy('timestamp', 'asc')
            ->get()
            ->map(fn($e) => (array) $e)
            ->toArray();

        $likes = DB::table('newsfeed_likes')
            ->where('userid', $userId)
            ->select('newsfeedid', 'timestamp')
            ->orderBy('timestamp', 'asc')
            ->get()
            ->map(fn($e) => (array) $e)
            ->toArray();

        $reports = DB::table('newsfeed_reports')
            ->where('userid', $userId)
            ->select('newsfeedid', 'timestamp')
            ->orderBy('timestamp', 'asc')
            ->get()
            ->map(fn($e) => (array) $e)
            ->toArray();

        return [
            'items' => $items,
            'unfollows' => $unfollows,
            'likes' => $likes,
            'reports' => $reports,
        ];
    }

    private function getAboutMe(int $userId): array
    {
        return DB::table('users_aboutme')
            ->where('userid', $userId)
            ->whereRaw('LENGTH(text) > 5')
            ->select('timestamp', 'text')
            ->orderBy('timestamp', 'asc')
            ->get()
            ->map(fn($e) => (array) $e)
            ->toArray();
    }

    private function getStories(int $userId): array
    {
        $stories = DB::table('users_stories')
            ->where('userid', $userId)
            ->select('date', 'headline', 'story')
            ->orderBy('date', 'asc')
            ->get()
            ->map(fn($e) => (array) $e)
            ->toArray();

        $likes = DB::table('users_stories_likes')
            ->where('userid', $userId)
            ->select('storyid')
            ->get()
            ->map(fn($e) => (array) $e)
            ->toArray();

        return [
            'stories' => $stories,
            'likes' => $likes,
        ];
    }

    private function getExportHistory(int $userId): array
    {
        return DB::table('users_exports')
            ->where('userid', $userId)
            ->select('userid', 'started', 'completed')
            ->orderBy('requested', 'asc')
            ->get()
            ->map(fn($e) => (array) $e)
            ->toArray();
    }

    private function getLogs(int $userId): array
    {
        return DB::table('logs')
            ->where('byuser', $userId)
            ->orWhere('user', $userId)
            ->select('id', 'timestamp', 'type', 'subtype', 'user', 'byuser', 'groupid', 'text')
            ->orderBy('timestamp', 'asc')
            ->limit(1000)
            ->get()
            ->map(fn($e) => (array) $e)
            ->toArray();
    }
}
