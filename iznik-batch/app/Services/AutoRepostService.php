<?php

namespace App\Services;

use App\Helpers\MailHelper;
use App\Mail\Message\AutoRepostWarning;
use App\Mail\Traits\FeatureFlags;
use App\Models\Group;
use App\Models\Message;
use App\Models\MessageGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AutoRepostService
{
    use FeatureFlags;

    public const EMAIL_TYPE = 'AutoRepost';
    /**
     * Only consider messages from the last N days.
     * V1: $mindate = 90 days ago (Message::EXPIRE_TIME).
     */
    public const LOOKBACK_DAYS = 90;

    /**
     * Default repost settings per group.
     * V1: $g->getSetting('reposts', ['offer' => 3, 'wanted' => 7, 'max' => 5, 'chaseups' => 5])
     */
    public const DEFAULT_REPOSTS = [
        'offer' => 3,
        'wanted' => 7,
        'max' => 5,
        'chaseups' => 5,
    ];

    /**
     * Process auto-reposts for all active Freegle groups.
     *
     * Matches V1 autorepost.php → Message::autoRepostGroup().
     *
     * Multi-group fix: V1 autoRepost() updates ALL messages_groups rows
     * (WHERE msgid = ?). We update only the specific group's row
     * (WHERE msgid = ? AND groupid = ?).
     *
     * V1 side effects included:
     *   - UPDATE messages_groups SET arrival=NOW(), autoreposts=autoreposts+1 (per-group)
     *   - Log AUTOREPOSTED entry per group
     *   - INSERT messages_postings per group
     *   - UPDATE lastautopostwarning for warning emails
     *   - Warning email: "Will Repost: {subject}" with completed/withdraw/promise buttons
     *
     * V1 side effects NOT included:
     *   - Search index bump: V1 calls $this->s->bump() to update arrival in
     *     messages_index. Not critical: Go API search sorts by wordmatch/popularity,
     *     not arrival. messages_groups.arrival IS updated (the source of truth).
     */
    public function process(bool $dryRun = false): array
    {
        $stats = [
            'reposted' => 0,
            'warned' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        // The feature flag controls warning EMAILS only, not the repost itself.
        // Reposts must happen even when warning emails are disabled — otherwise
        // removing AutoRepost from FREEGLE_MAIL_ENABLED_TYPES silently halts
        // all auto-reposting (the root cause of the missed reposts in #9481).
        $warningEmailEnabled = self::isEmailTypeEnabled(self::EMAIL_TYPE);

        if (!$warningEmailEnabled) {
            Log::info('AutoRepost warning emails disabled via FREEGLE_MAIL_ENABLED_TYPES; reposts will still run');
        }

        $mindate = now()->subDays(self::LOOKBACK_DAYS)->format('Y-m-d');

        // V1: SELECT id FROM groups WHERE type = 'Freegle' AND onhere = 1 ORDER BY RAND()
        $groups = Group::freegle()->onHere()->inRandomOrder()->get();

        foreach ($groups as $group) {
            if ($group->isClosed() || $group->getAttribute('autofunctionoverride')) {
                continue;
            }

            $reposts = $group->getSetting('reposts', self::DEFAULT_REPOSTS);

            try {
                $groupStats = $this->processGroup($group, $reposts, $mindate, $dryRun, $warningEmailEnabled);
                $stats['reposted'] += $groupStats['reposted'];
                $stats['warned'] += $groupStats['warned'];
                $stats['skipped'] += $groupStats['skipped'];
            } catch (\Exception $e) {
                Log::error("Error processing auto-repost for group #{$group->id}: " . $e->getMessage());
                $stats['errors']++;
            }
        }

        return $stats;
    }

    /**
     * Process auto-reposts for a single group.
     */
    protected function processGroup(Group $group, array $reposts, string $mindate, bool $dryRun, bool $warningEmailEnabled = true): array
    {
        $stats = ['reposted' => 0, 'warned' => 0, 'skipped' => 0];

        // V1 query: approved messages with no outcome, no promise, source=Platform,
        // not deleted, poster still a member (not PROHIBITED), poster not deleted,
        // no deadline or future deadline.
        $messages = $this->getCandidates($group->id, $mindate);

        $now = time();

        foreach ($messages as $msg) {
            // V1: Mail::ourDomain($message['fromaddr'])
            if (!MailHelper::isOurDomain($msg->fromaddr)) {
                $stats['skipped']++;
                continue;
            }

            if ($msg->autoreposts >= $reposts['max']) {
                $stats['skipped']++;
                continue;
            }

            $interval = $msg->type === Message::TYPE_OFFER
                ? ($reposts['offer'] ?? 3)
                : ($reposts['wanted'] ?? 7);

            // V1: check for recent replies in chat about this message.
            $recentReply = $this->hasRecentReply($msg->msgid, $interval);

            // V1: max age check — messages older than interval * (max + 1) days.
            $maxAge = $interval * (($reposts['max'] ?? 5) + 1);
            if ($msg->hoursago >= $maxAge * 24) {
                $stats['skipped']++;
                continue;
            }

            // V1: user must have been active since the original post.
            // V1: NULL lastaccess → PHP NULL+1=1 → hoursago >= 1 (treated as "active").
            // We use ?? 0 to match: hoursago < 0+1 is only true for sub-hour messages.
            if ($msg->hoursago < ($msg->activehoursago ?? 0) + 1) {
                $stats['skipped']++;
                continue;
            }

            if ($recentReply) {
                $stats['skipped']++;
                continue;
            }

            // V1: reposts might be turned off.
            if ($interval <= 0 || ($reposts['max'] ?? 5) <= 0) {
                $stats['skipped']++;
                continue;
            }

            // V1: check user hasn't disabled autoreposts.
            $userDisabled = DB::table('users')
                ->where('id', $msg->fromuser)
                ->whereRaw("JSON_EXTRACT(settings, '$.autorepostsdisable') = true")
                ->exists();

            if ($userDisabled) {
                $stats['skipped']++;
                continue;
            }

            $lastwarnago = $msg->lastautopostwarning
                ? ($now - strtotime($msg->lastautopostwarning))
                : null;

            // V1 WARNING: within 24h window before the next repost is due.
            // Use autoreposts count so that message #N triggers warning before window N+1,
            // not always before window 1.
            // Warning emails are gated on $warningEmailEnabled; the repost itself is not.
            if ($msg->hoursago <= ($msg->autoreposts + 1) * $interval * 24
                && $msg->hoursago > (($msg->autoreposts + 1) * $interval - 1) * 24
                && (is_null($lastwarnago) || $lastwarnago > 24 * 60 * 60)
            ) {
                if (!$msg->lastautopostwarning || ($lastwarnago > 24 * 60 * 60)) {
                    if ($dryRun) {
                        Log::info("Dry run: would send repost warning for message #{$msg->msgid} on group #{$group->id}");
                    } elseif ($warningEmailEnabled) {
                        // Multi-group fix: update per-group, not global.
                        // V1: UPDATE messages_groups SET lastautopostwarning = NOW() WHERE msgid = ?
                        DB::table('messages_groups')
                            ->where('msgid', $msg->msgid)
                            ->where('groupid', $group->id)
                            ->update(['lastautopostwarning' => now()]);

                        // V1: "Will Repost: {subject}" with links to mark completed/withdraw/promise.
                        $user = User::find($msg->fromuser);
                        if ($user && $user->email_preferred) {
                            Mail::send(new AutoRepostWarning(
                                messageId: $msg->msgid,
                                messageSubject: $msg->subject ?? '',
                                messageType: $msg->type,
                                userId: $msg->fromuser,
                                userName: $user->displayname,
                                userEmail: $user->email_preferred,
                                groupId: $group->id,
                            ));
                        }
                    }
                    $stats['warned']++;
                }
            } elseif ($msg->hoursago > ($msg->autoreposts + 1) * $interval * 24) {
                // V1 REPOST: message is past the next repost window.
                if ($dryRun) {
                    Log::info("Dry run: would auto-repost message #{$msg->msgid} on group #{$group->id}");
                } else {
                    $this->repost($msg, $group->id, $msg->autoreposts + 1, $reposts['max'] ?? 5);
                }
                $stats['reposted']++;
            } else {
                $stats['skipped']++;
            }
        }

        return $stats;
    }

    /**
     * Get repost candidate messages for a specific group.
     *
     * V1 query from autoRepostGroup().
     */
    protected function getCandidates(int $groupid, string $mindate)
    {
        return DB::table('messages_groups')
            ->join('messages', 'messages.id', '=', 'messages_groups.msgid')
            ->join('users', 'messages.fromuser', '=', 'users.id')
            ->join('memberships', function ($join) {
                $join->on('memberships.userid', '=', 'messages.fromuser')
                    ->on('memberships.groupid', '=', 'messages_groups.groupid');
            })
            ->leftJoin('messages_outcomes', 'messages.id', '=', 'messages_outcomes.msgid')
            ->leftJoin('messages_promises', 'messages_promises.msgid', '=', 'messages.id')
            ->select(
                'messages_groups.msgid',
                'messages_groups.groupid',
                'messages_groups.autoreposts',
                'messages_groups.lastautopostwarning',
                'messages.type',
                'messages.subject',
                'messages.fromaddr',
                'messages.fromuser',
                DB::raw('TIMESTAMPDIFF(HOUR, messages_groups.arrival, NOW()) AS hoursago'),
                DB::raw('TIMESTAMPDIFF(HOUR, users.lastaccess, NOW()) AS activehoursago')
            )
            ->where('messages_groups.arrival', '>', $mindate)
            ->where('messages_groups.groupid', $groupid)
            ->where('messages_groups.collection', MessageGroup::COLLECTION_APPROVED)
            ->whereNull('messages_outcomes.msgid')
            ->whereNull('messages_promises.msgid')
            ->whereIn('messages.type', [Message::TYPE_OFFER, Message::TYPE_WANTED])
            ->where('messages.source', Message::SOURCE_PLATFORM)
            ->whereNull('messages.deleted')
            ->where(function ($q) {
                $q->whereNull('memberships.ourPostingStatus')
                    ->orWhere('memberships.ourPostingStatus', '!=', 'PROHIBITED');
            })
            ->whereNull('users.deleted')
            ->where(function ($q) {
                $q->whereNull('messages.deadline')
                    ->orWhereRaw('messages.deadline > DATE(NOW())');
            })
            ->get();
    }

    /**
     * Check for recent chat replies about this message.
     *
     * V1: SELECT MAX(chat_messages.date) AS max FROM chat_messages
     * WHERE chatid IN (SELECT chatid FROM chat_messages WHERE refmsgid = ? AND type != 'ModMail')
     */
    protected function hasRecentReply(int $msgid, int $intervalDays): bool
    {
        $maxDate = DB::table('chat_messages')
            ->whereIn('chatid', function ($q) use ($msgid) {
                $q->select('chatid')
                    ->from('chat_messages')
                    ->where('refmsgid', $msgid)
                    ->where('type', '!=', 'ModMail');
            })
            ->max('date');

        if (!$maxDate) {
            return false;
        }

        // V1 bug: used $interval * 60 * 60 where $interval is in days, so offer(3) checked
        // 3 hours instead of 3 days. We fix this to use the correct day-to-seconds conversion.
        return (time() - strtotime($maxDate)) < $intervalDays * 24 * 60 * 60;
    }

    /**
     * Repost a message on a specific group.
     *
     * Multi-group fix: V1 does WHERE msgid = ? (all groups).
     * We do WHERE msgid = ? AND groupid = ? (specific group only).
     *
     * V1 side effects:
     *   - UPDATE messages_groups SET arrival=NOW(), autoreposts+1
     *   - Log AUTOREPOSTED
     *   - INSERT messages_postings
     *   - Bump search index (skipped — handled by cron)
     */
    protected function repost(object $msg, int $groupid, int $newReposts, int $maxReposts): void
    {
        // Multi-group fix: only update the specific group's row.
        // V1: UPDATE messages_groups SET arrival = NOW(), autoreposts = autoreposts + 1 WHERE msgid = ?
        DB::table('messages_groups')
            ->where('msgid', $msg->msgid)
            ->where('groupid', $groupid)
            ->update([
                'arrival' => now(),
                'autoreposts' => DB::raw('autoreposts + 1'),
            ]);

        // V1: log per group.
        DB::table('logs')->insert([
            'timestamp' => now(),
            'type' => 'Message',
            'subtype' => 'Autoreposted',
            'msgid' => $msg->msgid,
            'groupid' => $groupid,
            'user' => $msg->fromuser,
            'text' => "$newReposts / $maxReposts",
        ]);

        // V1: INSERT INTO messages_postings (msgid, groupid, repost, autorepost)
        DB::table('messages_postings')->insert([
            'msgid' => $msg->msgid,
            'groupid' => $groupid,
            'repost' => 1,
            'autorepost' => 1,
        ]);

        Log::info("Auto-reposted message #{$msg->msgid} on group #{$groupid} ({$newReposts}/{$maxReposts})");
    }

}
