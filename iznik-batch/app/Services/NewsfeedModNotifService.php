<?php

namespace App\Services;

use App\Mail\Newsfeed\NewsfeedModNotifMail;
use App\Models\Group;
use App\Models\Membership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class NewsfeedModNotifService
{
    // System address that must be excluded from mod notifications (not a real human).
    public const SYSTEM_MOD_EMAIL = 'modtools@modtools.org';

    // Newsfeed types to include in mod notifications (top-level chitchat types only).
    private const NOTIF_TYPES = ['Message', 'Story', 'AboutMe'];

    // Time window for finding recent posts (V1: "24 hours ago").
    public const LOOKBACK_HOURS = 24;

    /**
     * Notify group mods about new chitchat (newsfeed) posts from their members.
     *
     * For each unique mod across all Freegle groups, finds new top-level newsfeed
     * posts (type: Message, Story, AboutMe) within the last 24 hours that the
     * mod has not yet seen, in any of their moderated groups or groups whose area
     * contains the post's location.
     *
     * @return array{notified: int, mods_checked: int}
     */
    public function notifyMods(bool $dryRun = false): array
    {
        $modSite = config('freegle.sites.mod');

        $notified = 0;
        $modsChecked = 0;

        $since = now()->subHours(self::LOOKBACK_HOURS)->toDateTimeString();

        // Collect unique mod IDs across all Freegle groups.
        $modIds = DB::table('memberships')
            ->join('users', 'users.id', '=', 'memberships.userid')
            ->join('groups', 'groups.id', '=', 'memberships.groupid')
            ->where('groups.type', Group::TYPE_FREEGLE)
            ->where('groups.publish', 1)
            ->where('groups.onhere', 1)
            ->whereIn('memberships.role', [Membership::ROLE_OWNER, Membership::ROLE_MODERATOR])
            ->where('memberships.collection', Membership::COLLECTION_APPROVED)
            ->whereNull('users.deleted')
            ->distinct()
            ->pluck('memberships.userid')
            ->toArray();

        foreach ($modIds as $modId) {
            $modsChecked++;

            $mod = DB::table('users')
                ->join('users_emails', function ($join) {
                    $join->on('users_emails.userid', '=', 'users.id')
                        ->where('users_emails.preferred', '=', 1);
                })
                ->where('users.id', $modId)
                ->select([
                    'users.id',
                    'users.fullname',
                    'users.settings',
                    'users.emailfrequency',
                    'users_emails.email',
                ])
                ->first();

            if (!$mod || !$mod->email) {
                continue;
            }

            // Skip the system modtools address.
            if (strtolower($mod->email) === self::SYSTEM_MOD_EMAIL) {
                continue;
            }

            // Skip mods who have opted out of email (emailfrequency = 0).
            if ((int) $mod->emailfrequency === 0) {
                continue;
            }

            // Check user's modnotifnewsfeed setting (default: TRUE).
            $settings = is_string($mod->settings) ? json_decode($mod->settings, true) : (array) $mod->settings;
            if (!($settings['modnotifnewsfeed'] ?? true)) {
                continue;
            }

            // Get the last newsfeed ID this mod has seen.
            $lastSeen = DB::table('newsfeed_users')
                ->where('userid', $modId)
                ->value('newsfeedid') ?? 0;

            // Get all group IDs this mod moderates on Freegle groups.
            $groupIds = DB::table('memberships')
                ->join('groups', 'groups.id', '=', 'memberships.groupid')
                ->where('memberships.userid', $modId)
                ->whereIn('memberships.role', [Membership::ROLE_OWNER, Membership::ROLE_MODERATOR])
                ->where('memberships.collection', Membership::COLLECTION_APPROVED)
                ->where('groups.type', Group::TYPE_FREEGLE)
                ->where('groups.publish', 1)
                ->where('groups.onhere', 1)
                ->pluck('memberships.groupid')
                ->toArray();

            if (empty($groupIds)) {
                continue;
            }

            // Build group ID placeholders for the spatial query.
            $placeholders = implode(',', array_fill(0, count($groupIds), '?'));

            // Find unseen newsfeed posts across all moderated groups (direct match or spatial containment).
            $posts = DB::select(
                "SELECT DISTINCT newsfeed.id, newsfeed.userid, newsfeed.type, newsfeed.message, newsfeed.added
                 FROM newsfeed
                 INNER JOIN `groups` ON (
                     newsfeed.groupid = groups.id
                     OR (newsfeed.position IS NOT NULL AND groups.polyindex IS NOT NULL
                         AND MBRContains(groups.polyindex, newsfeed.position))
                 )
                 WHERE newsfeed.added >= ?
                   AND newsfeed.id > ?
                   AND groups.id IN ({$placeholders})
                   AND newsfeed.deleted IS NULL
                   AND newsfeed.type IN ('Message', 'Story', 'AboutMe')
                   AND newsfeed.replyto IS NULL
                   AND newsfeed.hidden IS NULL
                   AND newsfeed.userid != ?
                 ORDER BY newsfeed.added ASC",
                array_merge([$since, $lastSeen], $groupIds, [$modId])
            );

            if (empty($posts)) {
                continue;
            }

            $count = count($posts);
            $maxId = 0;
            $textSummary = "Recent chitchat posts from your members:\r\n\r\n";

            foreach ($posts as $post) {
                $maxId = max($maxId, $post->id);

                $str = $post->message ?? '';

                switch ($post->type) {
                    case 'AboutMe':
                        $str = '"' . $str . '"';
                        break;
                    case 'Story':
                        $str = 'told their Freegle story';
                        break;
                }

                if (mb_strlen($str) > 200) {
                    $str = mb_substr($str, 0, 200) . '...';
                }

                $textSummary .= "{$str}\r\n\r\n";
            }

            $chitchatUrl = "{$modSite}/chitchat";
            $textSummary .= "Read them at: {$chitchatUrl}\r\n";

            if (!$dryRun) {
                Mail::to($mod->email)->send(new NewsfeedModNotifMail($count, $textSummary));

                // Update the last seen marker for this mod.
                DB::table('newsfeed_users')->updateOrInsert(
                    ['userid' => $modId],
                    ['newsfeedid' => $maxId]
                );
            }

            $notified++;
        }

        return ['notified' => $notified, 'mods_checked' => $modsChecked];
    }
}
