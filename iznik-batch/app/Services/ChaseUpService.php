<?php

namespace App\Services;

use App\Helpers\MailHelper;
use App\Mail\Message\ChaseUp as ChaseUpMail;
use App\Mail\Message\ChaseUpPromised;
use App\Mail\Traits\FeatureFlags;
use App\Models\Group;
use App\Models\Message;
use App\Models\MessageGroup;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ChaseUpService
{
    use FeatureFlags;

    public const EMAIL_TYPE = 'ChaseUp';
    /**
     * Only consider messages from the last N days.
     * V1: $mindate = max('2001-01-01', 90 days ago).
     */
    public const LOOKBACK_DAYS = 90;

    /**
     * Default repost settings.
     */
    public const DEFAULT_REPOSTS = [
        'offer' => 3,
        'wanted' => 7,
        'max' => 5,
        'chaseups' => 5,
    ];

    /**
     * "Dull" outcome comments that should be NULLed out.
     *
     * V1: Message::dullComment() — these are auto-generated or meaningless
     * comments that clutter the outcomes table.
     */
    private const DULL_COMMENTS = [
        'Sorry, this is no longer available.',
        'Thanks, this has now been taken.',
        "Thanks, I'm no longer looking for this.",
        'Sorry, this has now been taken.',
        'Thanks for the interest, but this has now been taken.',
        'Thanks, these have now been taken.',
        'Thanks, this has now been received.',
        'Sorry, this is no longer available',
        'Withdrawn on user unsubscribe',
    ];

    /**
     * Tidy outcome comments by NULLing out "dull" (auto-generated/meaningless) ones.
     *
     * V1: Message::tidyOutcomes($since).
     * Scans messages_outcomes for non-NULL comments and NULLs out those that
     * match a known list of auto-generated phrases.
     */
    public function tidyOutcomes(bool $dryRun = false): int
    {
        $count = 0;

        // V1 passes '2001-01-01' as $since — effectively all time.
        $outcomes = DB::table('messages_outcomes')
            ->whereNotNull('comments')
            ->get(['id', 'comments', 'timestamp']);

        foreach ($outcomes as $outcome) {
            if ($this->isDullComment($outcome->comments)) {
                if (!$dryRun) {
                    DB::table('messages_outcomes')
                        ->where('id', $outcome->id)
                        ->update([
                            'comments' => null,
                            // V1 preserves the original timestamp to avoid changing it.
                            'timestamp' => $outcome->timestamp,
                        ]);
                }
                $count++;
            }
        }

        Log::info("Tidied {$count} dull outcome comments", ['dry_run' => $dryRun]);

        return $count;
    }

    /**
     * Check if an outcome comment is "dull" (auto-generated/meaningless).
     *
     * V1: Message::dullComment().
     * Empty/whitespace-only comments are considered dull.
     * Known auto-generated phrases are considered dull.
     */
    protected function isDullComment(?string $comment): bool
    {
        $comment = $comment ? trim($comment) : '';

        if (strlen($comment) === 0) {
            return true;
        }

        return in_array($comment, self::DULL_COMMENTS, true);
    }

    /**
     * Auto-complete intended outcomes from messages_outcomes_intended.
     *
     * V1: Message::processIntendedOutcomes().
     * When a user clicks a chaseup link but doesn't complete the process
     * within 30 minutes, we do it for them. Only processes intendeds
     * from the last 7 days.
     */
    public function processIntendedOutcomes(bool $dryRun = false): int
    {
        $count = 0;

        $intendeds = DB::table('messages_outcomes_intended')
            ->whereRaw('TIMESTAMPDIFF(MINUTE, timestamp, NOW()) > 30')
            ->whereRaw('TIMESTAMPDIFF(DAY, timestamp, NOW()) <= 7')
            ->get();

        foreach ($intendeds as $intended) {
            // Check if message already has an outcome or promises.
            $hasOutcome = DB::table('messages_outcomes')
                ->where('msgid', $intended->msgid)
                ->exists();

            $hasPromises = DB::table('messages_promises')
                ->where('msgid', $intended->msgid)
                ->exists();

            if ($hasOutcome || $hasPromises) {
                continue;
            }

            if ($dryRun) {
                Log::info("Dry run: would process intended outcome '{$intended->outcome}' for message #{$intended->msgid}");
                $count++;
                continue;
            }

            switch ($intended->outcome) {
                case 'Taken':
                    DB::table('messages_outcomes')->insert([
                        'msgid' => $intended->msgid,
                        'outcome' => Message::OUTCOME_TAKEN,
                        'timestamp' => now(),
                    ]);
                    $count++;
                    break;

                case 'Received':
                    DB::table('messages_outcomes')->insert([
                        'msgid' => $intended->msgid,
                        'outcome' => Message::OUTCOME_RECEIVED,
                        'timestamp' => now(),
                    ]);
                    $count++;
                    break;

                case 'Withdrawn':
                    DB::table('messages_outcomes')->insert([
                        'msgid' => $intended->msgid,
                        'outcome' => Message::OUTCOME_WITHDRAWN,
                        'timestamp' => now(),
                    ]);
                    $count++;
                    break;

                case 'Repost':
                    // V1: Only repost if the message is currently eligible.
                    if ($this->canRepostMessage($intended->msgid)) {
                        $this->repostMessage($intended->msgid);
                        $count++;
                    }
                    break;
            }

            // Clean up the intended outcome record after processing.
            DB::table('messages_outcomes_intended')
                ->where('id', $intended->id)
                ->delete();
        }

        Log::info("Processed {$count} intended outcomes", ['dry_run' => $dryRun]);

        return $count;
    }

    /**
     * Check if a message is eligible for reposting on any of its groups.
     *
     * V1: Message::canRepost() — checks all groups.
     */
    protected function canRepostMessage(int $msgid): bool
    {
        $message = Message::find($msgid);
        if (!$message) {
            return false;
        }

        $groups = DB::table('messages_groups')
            ->where('msgid', $msgid)
            ->select('groupid', DB::raw('TIMESTAMPDIFF(HOUR, arrival, NOW()) AS hoursago'))
            ->get();

        foreach ($groups as $group) {
            $groupModel = Group::find($group->groupid);
            if (!$groupModel) {
                continue;
            }

            $reposts = $groupModel->getSetting('reposts', self::DEFAULT_REPOSTS);
            $interval = $message->type === Message::TYPE_OFFER
                ? ($reposts['offer'] ?? 3)
                : ($reposts['wanted'] ?? 7);

            if ($group->hoursago > $interval * 24) {
                return true;
            }
        }

        return false;
    }

    /**
     * Repost a message by resetting its arrival time and incrementing autoreposts.
     *
     * V1: Message::repost() — resets arrival, increments autoreposts.
     */
    protected function repostMessage(int $msgid): void
    {
        DB::table('messages_groups')
            ->where('msgid', $msgid)
            ->update([
                'arrival' => now(),
                'autoreposts' => DB::raw('autoreposts + 1'),
            ]);
    }

    /**
     * Notify users about languishing (stuck) posts.
     *
     * V1: Message::notifyLanguishing().
     * Finds posts from 48 hours to 31 days ago that have no outcome,
     * no promises, no recent chat activity, and have finished autoreposting.
     * Creates an OpenPosts notification for each affected user.
     */
    public function notifyLanguishing(bool $dryRun = false): int
    {
        $count = 0;
        $start = now()->subDays(31)->format('Y-m-d');
        $end = now()->subHours(48)->format('Y-m-d');

        $msgs = DB::table('messages_groups')
            ->leftJoin('messages_outcomes', 'messages_outcomes.msgid', '=', 'messages_groups.msgid')
            ->leftJoin('messages_promises', 'messages_promises.msgid', '=', 'messages_groups.msgid')
            ->join('messages', 'messages.id', '=', 'messages_groups.msgid')
            ->select(
                'messages_groups.msgid',
                'messages_groups.msgtype',
                'messages_groups.autoreposts',
                'messages_groups.groupid',
                'messages_groups.collection',
                'messages.fromuser',
                'messages.fromaddr'
            )
            ->whereBetween('messages_groups.arrival', [$start, $end])
            ->whereNull('messages_outcomes.id')
            ->whereNull('messages_promises.id')
            ->whereNull('messages.deleted')
            ->whereNull('messages.heldby')
            ->get();

        $languishing = [];

        foreach ($msgs as $msg) {
            // V1: filter in PHP for better index usage.
            if ($msg->collection !== MessageGroup::COLLECTION_APPROVED) {
                continue;
            }
            if (!in_array($msg->msgtype, [Message::TYPE_OFFER, Message::TYPE_WANTED])) {
                continue;
            }
            if (!MailHelper::isOurDomain($msg->fromaddr)) {
                continue;
            }

            // Check if there's been any recent chat activity about this message.
            $recentChat = DB::table('chat_messages')
                ->whereIn('chatid', function ($q) use ($msg) {
                    $q->select('chatid')
                        ->from('chat_messages')
                        ->where('refmsgid', $msg->msgid);
                })
                ->where('date', '>=', $end)
                ->max('date');

            if ($recentChat) {
                continue;
            }

            // Check if autoreposting is finished for this group.
            $group = Group::find($msg->groupid);
            if (!$group) {
                continue;
            }

            $reposts = $group->getSetting('reposts', self::DEFAULT_REPOSTS);
            $maxReposts = $reposts['max'] ?? 5;

            if ($maxReposts > 0 && $msg->autoreposts <= $maxReposts) {
                continue;
            }

            $count++;
            if (!array_key_exists($msg->fromuser, $languishing)) {
                $languishing[$msg->fromuser] = 1;
            } else {
                $languishing[$msg->fromuser]++;
            }
        }

        // Create OpenPosts notifications for each user.
        foreach ($languishing as $userId => $postCount) {
            if ($dryRun) {
                Log::info("Dry run: would notify user #{$userId} about {$postCount} languishing posts");
                continue;
            }

            // V1: deleteOldUserType() — remove old OpenPosts notifications, then add new one
            // only if there wasn't a recent one.
            $recentExists = Notification::where('touser', $userId)
                ->where('type', 'OpenPosts')
                ->where('timestamp', '>=', now()->subDay())
                ->exists();

            if (!$recentExists) {
                // Delete any older OpenPosts notifications for this user.
                Notification::where('touser', $userId)
                    ->where('type', 'OpenPosts')
                    ->delete();

                Notification::create([
                    'touser' => $userId,
                    'type' => 'OpenPosts',
                    'timestamp' => now(),
                ]);
            }
        }

        Log::info("Found {$count} languishing posts for " . count($languishing) . " users", ['dry_run' => $dryRun]);

        return $count;
    }

    /**
     * Process chase-ups for all active Freegle groups.
     *
     * Matches V1 chaseup.php → Message::chaseUp().
     * Sends chase-up emails for messages with replies but no outcome,
     * after max reposts reached.
     *
     * Multi-group fix: V1 updates lastchaseup globally
     * (WHERE msgid = ?). We update per-group
     * (WHERE msgid = ? AND groupid = ?).
     *
     * V1 side effects included:
     *   - UPDATE messages_groups SET lastchaseup = NOW() (per-group)
     *   - Chase-up email: "What happened to: {subject}" with links to
     *     mark completed/repost/withdraw. Different template if promised.
     */
    public function process(bool $dryRun = false): array
    {
        $stats = [
            'chased' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        if (!self::isEmailTypeEnabled(self::EMAIL_TYPE)) {
            Log::info('ChaseUp emails disabled via FREEGLE_MAIL_ENABLED_TYPES');
            return $stats;
        }

        $mindate = now()->subDays(self::LOOKBACK_DAYS)->format('Y-m-d');

        // V1: SELECT id FROM groups WHERE type = 'Freegle' ORDER BY RAND()
        // Note: V1 doesn't filter onhere for chaseup (unlike autorepost).
        $groups = Group::freegle()->inRandomOrder()->get();

        foreach ($groups as $group) {
            if ($group->isClosed()) {
                continue;
            }

            $reposts = $group->getSetting('reposts', self::DEFAULT_REPOSTS);

            try {
                $groupStats = $this->processGroup($group, $reposts, $mindate, $dryRun);
                $stats['chased'] += $groupStats['chased'];
                $stats['skipped'] += $groupStats['skipped'];
            } catch (\Exception $e) {
                // Transient SMTP failures (mail-host:25 connection refused/timed out)
                // during container-startup ordering shouldn't escalate to Sentry —
                // log them at warning level. Genuine errors still hit Log::error.
                $level = app(\App\Services\Mail\SmtpFailureClassifier::class)
                    ->isTransient($e->getMessage()) ? 'warning' : 'error';
                Log::$level("Error processing chase-up for group #{$group->id}: " . $e->getMessage());
                $stats['errors']++;
            }
        }

        return $stats;
    }

    /**
     * Process chase-ups for a single group.
     */
    protected function processGroup(Group $group, array $reposts, string $mindate, bool $dryRun): array
    {
        $stats = ['chased' => 0, 'skipped' => 0];

        $messages = $this->getCandidates($group->id, $mindate);
        $now = time();

        foreach ($messages as $msg) {
            // V1: Mail::ourDomain check.
            if (!MailHelper::isOurDomain($msg->fromaddr)) {
                $stats['skipped']++;
                continue;
            }

            // V1: canChaseup() — max reposts reached AND enough time since last chaseup.
            if (!$this->canChaseup($msg, $reposts)) {
                $stats['skipped']++;
                continue;
            }

            // V1: find last reply time in chat.
            $lastReplyDate = DB::table('chat_messages')
                ->whereIn('chatid', function ($q) use ($msg) {
                    $q->select('chatid')
                        ->from('chat_messages')
                        ->where('refmsgid', $msg->msgid);
                })
                ->max('date');

            if (!$lastReplyDate) {
                $stats['skipped']++;
                continue;
            }

            $replyAgeHours = ($now - strtotime($lastReplyDate)) / 3600;
            $chaseupInterval = $reposts['chaseups'] ?? 2;

            // V1: $age > $interval * 24
            if ($chaseupInterval <= 0 || $replyAgeHours <= $chaseupInterval * 24) {
                $stats['skipped']++;
                continue;
            }

            // V1: canRepost() check — message must be old enough.
            if (!$this->canRepost($msg, $reposts)) {
                $stats['skipped']++;
                continue;
            }

            // Ready to chase up.
            if ($dryRun) {
                $promised = $this->isPromised($msg->msgid);
                Log::info("Dry run: would send chase-up for message #{$msg->msgid} on group #{$group->id}" . ($promised ? ' (promised)' : ''));
                $stats['chased']++;
                continue;
            }

            // Multi-group fix: update per-group, not global.
            // V1: UPDATE messages_groups SET lastchaseup = NOW() WHERE msgid = ?
            DB::table('messages_groups')
                ->where('msgid', $msg->msgid)
                ->where('groupid', $group->id)
                ->update(['lastchaseup' => now()]);

            // V1: "What happened to: {subject}" — different template if promised.
            $user = User::find($msg->fromuser);
            if ($user && $user->email_preferred) {
                $promised = $this->isPromised($msg->msgid);
                $mailable = $promised
                    ? new ChaseUpPromised(
                        messageId: $msg->msgid,
                        messageSubject: $msg->subject ?? '',
                        messageType: $msg->type,
                        userId: $msg->fromuser,
                        userName: $user->displayname,
                        userEmail: $user->email_preferred,
                        groupId: $group->id,
                    )
                    : new ChaseUpMail(
                        messageId: $msg->msgid,
                        messageSubject: $msg->subject ?? '',
                        messageType: $msg->type,
                        userId: $msg->fromuser,
                        userName: $user->displayname,
                        userEmail: $user->email_preferred,
                        groupId: $group->id,
                    );
                Mail::send($mailable);
            }

            Log::info("Chase-up sent for message #{$msg->msgid} on group #{$group->id}");
            $stats['chased']++;
        }

        return $stats;
    }

    /**
     * Get chase-up candidate messages for a specific group.
     *
     * V1: UNION query for messages_related on id1 and id2.
     * Simplified here: exclude messages that have related messages.
     * Messages must have at least one chat reply (INNER JOIN chat_messages).
     */
    protected function getCandidates(int $groupid, string $mindate)
    {
        return DB::table('messages_groups')
            ->join('messages', 'messages.id', '=', 'messages_groups.msgid')
            ->join('memberships', function ($join) {
                $join->on('memberships.userid', '=', 'messages.fromuser')
                    ->on('memberships.groupid', '=', 'messages_groups.groupid');
            })
            ->leftJoin('messages_related AS mr1', 'mr1.id1', '=', 'messages.id')
            ->leftJoin('messages_related AS mr2', 'mr2.id2', '=', 'messages.id')
            ->leftJoin('messages_outcomes', 'messages.id', '=', 'messages_outcomes.msgid')
            ->join('chat_messages', 'messages.id', '=', 'chat_messages.refmsgid')
            ->select(
                'messages_groups.msgid',
                'messages_groups.groupid',
                'messages_groups.lastchaseup',
                'messages_groups.autoreposts',
                'messages.type',
                'messages.subject',
                'messages.fromaddr',
                'messages.fromuser',
                DB::raw('TIMESTAMPDIFF(HOUR, messages_groups.arrival, NOW()) AS hoursago')
            )
            ->where('messages_groups.arrival', '>', $mindate)
            ->where('messages_groups.groupid', $groupid)
            ->where('messages_groups.collection', MessageGroup::COLLECTION_APPROVED)
            ->whereNull('mr1.id1')
            ->whereNull('mr2.id2')
            ->whereNull('messages_outcomes.msgid')
            ->whereIn('messages.type', [Message::TYPE_OFFER, Message::TYPE_WANTED])
            ->where('messages.source', Message::SOURCE_PLATFORM)
            ->whereNull('messages.deleted')
            ->groupBy(
                'messages_groups.msgid',
                'messages_groups.groupid',
                'messages_groups.lastchaseup',
                'messages_groups.autoreposts',
                'messages.type',
                'messages.subject',
                'messages.fromaddr',
                'messages.fromuser',
                'messages_groups.arrival'
            )
            ->get();
    }

    /**
     * Check if a message can be chased up on this specific group.
     *
     * V1 canChaseup(): queries ALL groups, returns TRUE if ANY passes.
     * Multi-group fix: we check only the current group. V1's cross-group
     * leak (chasing up on group A because group B hit max reposts) is not
     * reproduced — each group is evaluated independently.
     */
    protected function canChaseup(object $msg, array $reposts): bool
    {
        $maxreposts = $reposts['max'] ?? 5;

        // Must have reached max reposts (or reposts disabled).
        if ($maxreposts > 0 && $msg->autoreposts < $maxreposts) {
            return false;
        }

        // V1 had a unit-mixing bug here: max(days, chaseups * 24) compared days
        // to hours, making repeat chaseups fire after ~120 days instead of 5.
        // Fixed: both values are now in days.
        $typeIntervalDays = $msg->type === Message::TYPE_OFFER
            ? ($reposts['offer'] ?? 3)
            : ($reposts['wanted'] ?? 7);
        $chaseupDays = $reposts['chaseups'] ?? 2;
        $intervalDays = max($typeIntervalDays, $chaseupDays);

        // If last chaseup exists, must be older than interval.
        if ($msg->lastchaseup) {
            $ageHours = (time() - strtotime($msg->lastchaseup)) / 3600;
            return $ageHours > $intervalDays * 24;
        }

        return true;
    }

    /**
     * Check if a message is old enough to be reposted on this group.
     *
     * V1 canRepost(): queries ALL groups, returns TRUE if ANY passes.
     * Multi-group fix: we check only the current group's arrival time.
     */
    protected function canRepost(object $msg, array $reposts): bool
    {
        $interval = $msg->type === Message::TYPE_OFFER
            ? ($reposts['offer'] ?? 3)
            : ($reposts['wanted'] ?? 7);

        return $msg->hoursago > $interval * 24;
    }

    /**
     * Check if a message has promises.
     *
     * V1: $m->promiseCount()
     */
    protected function isPromised(int $msgid): bool
    {
        return DB::table('messages_promises')
            ->where('msgid', $msgid)
            ->exists();
    }

}
