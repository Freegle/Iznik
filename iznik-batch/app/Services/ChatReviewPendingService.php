<?php

namespace App\Services;

use App\Models\Group;
use App\Models\Membership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class ChatReviewPendingService
{
    // Email of the system modtools user used to mark auto-rejected messages.
    public const SYSTEM_MOD_EMAIL = 'modtools@modtools.org';

    // Messages stuck in review for more than this many days are auto-rejected.
    public const AUTO_REJECT_DAYS = 7;

    // Messages pending review for more than this many hours trigger a mod notification.
    public const NOTIFY_HOURS = 48;

    /**
     * Auto-reject stale review messages and notify mods about pending ones.
     *
     * @return array{auto_rejected: int, groups_notified: int}
     */
    public function processReview(bool $dryRun = false): array
    {
        $supportAddr = config('freegle.mail.support_addr');
        $mentorsAddr = config('freegle.mail.mentors_addr');
        $modSite     = config('freegle.sites.mod');

        $systemUserId = $this->getSystemUserId();

        $autoRejected   = $this->autoRejectStale($systemUserId, $dryRun);
        $groupsNotified = $this->notifyMods($supportAddr, $mentorsAddr, $modSite, $dryRun);

        return [
            'auto_rejected'   => $autoRejected,
            'groups_notified' => $groupsNotified,
        ];
    }

    private function getSystemUserId(): ?int
    {
        $row = DB::table('users_emails')
            ->where('email', self::SYSTEM_MOD_EMAIL)
            ->value('userid');

        return $row ? (int) $row : null;
    }

    private function autoRejectStale(?int $systemUserId, bool $dryRun): int
    {
        $cutoff = now()->subDays(self::AUTO_REJECT_DAYS)->toDateTimeString();

        if ($dryRun) {
            return DB::table('chat_messages')
                ->where('date', '<', $cutoff)
                ->where('reviewrequired', 1)
                ->whereNull('reviewedby')
                ->count();
        }

        return DB::table('chat_messages')
            ->where('date', '<', $cutoff)
            ->where('reviewrequired', 1)
            ->whereNull('reviewedby')
            ->update([
                'reviewedby'     => $systemUserId,
                'reviewrejected' => 1,
            ]);
    }

    private function notifyMods(string $supportAddr, string $mentorsAddr, string $modSite, bool $dryRun): int
    {
        $cutoff = now()->subHours(self::NOTIFY_HOURS)->toDateTimeString();

        $groups = DB::select(
            "SELECT memberships.groupid, COUNT(DISTINCT chat_messages.id) AS cnt
             FROM chat_messages
             INNER JOIN chat_rooms ON chat_rooms.id = chat_messages.chatid
             INNER JOIN users ON users.id = chat_messages.userid AND users.deleted IS NULL
             INNER JOIN memberships ON memberships.userid = (
                 CASE WHEN chat_rooms.user1 = chat_messages.userid THEN chat_rooms.user2 ELSE chat_rooms.user1 END
             )
             LEFT JOIN chat_messages_held ON chat_messages_held.msgid = chat_messages.id
             WHERE chat_messages.date < ?
               AND chat_messages.reviewrequired = 1
               AND chat_messages.reviewedby IS NULL
               AND chat_messages.reviewrejected = 0
               AND chat_messages_held.id IS NULL
             GROUP BY memberships.groupid
             ORDER BY RAND()",
            [$cutoff]
        );

        $mentorsSummary = '';
        $groupsNotified = 0;

        foreach ($groups as $group) {
            $groupRow = DB::table('groups')
                ->where('id', $group->groupid)
                ->first(['id', 'nameshort', 'type', 'publish']);

            if (!$groupRow
                || $groupRow->type !== Group::TYPE_FREEGLE
                || !$groupRow->publish
            ) {
                continue;
            }

            $count      = (int) $group->cnt;
            $groupName  = $groupRow->nameshort;
            $modEmails  = $this->getModEmails((int) $group->groupid);

            if (empty($modEmails)) {
                continue;
            }

            $mentorsSummary .= "{$groupName} — {$count} message" . ($count !== 1 ? 's' : '') . "\r\n";
            $groupsNotified++;

            if (!$dryRun) {
                $body = "Dear {$groupName} Volunteers,\r\n\r\n"
                    . "Messages between members are scanned to spot spam. For some of these, we need you to review them "
                    . "to check whether they are really spam or not.\r\n\r\n"
                    . "You currently have some messages which have been waiting for 48 hours for review. Some of these may "
                    . "be real messages which members won't have received yet, so they may be wondering what's going on.\r\n\r\n"
                    . "Please can you review these at {$modSite}/modtools/chats/review? They will automatically be deleted "
                    . "after 7 days.\r\n\r\nThanks.\r\n\r\n"
                    . "P.S. This is an automated mail sent once a day. If you need help using ModTools, please ask the "
                    . "Mentor folk at {$mentorsAddr}.";

                $subject = "{$count} message" . ($count !== 1 ? 's' : '')
                    . ' between members waiting for your review on ' . $groupName;

                foreach ($modEmails as $email) {
                    Mail::raw($body, function ($message) use ($subject, $supportAddr, $email) {
                        $message->from($supportAddr, 'Freegle')
                            ->to($email)
                            ->subject($subject);
                    });
                }
            }
        }

        if ($groupsNotified > 0 && !$dryRun && $mentorsSummary) {
            $total = collect($groups)->sum('cnt');
            Mail::raw(
                "Here's a list of groups which have chat messages pending review for more than "
                . self::NOTIFY_HOURS . " hours:\r\n\r\n{$mentorsSummary}",
                function ($message) use ($supportAddr, $mentorsAddr, $total) {
                    $message->from($supportAddr, 'Freegle')
                        ->to($mentorsAddr)
                        ->subject("Summary of chat messages waiting for review ({$total} total)");
                }
            );
        }

        return $groupsNotified;
    }

    private function getModEmails(int $groupId): array
    {
        return DB::table('memberships')
            ->join('users_emails', function ($join) {
                $join->on('users_emails.userid', '=', 'memberships.userid')
                    ->where('users_emails.preferred', '=', 1);
            })
            ->join('users', 'users.id', '=', 'memberships.userid')
            ->where('memberships.groupid', $groupId)
            ->whereIn('memberships.role', [Membership::ROLE_OWNER, Membership::ROLE_MODERATOR])
            ->where('memberships.collection', Membership::COLLECTION_APPROVED)
            ->whereNull('users.deleted')
            ->where('users_emails.email', '!=', self::SYSTEM_MOD_EMAIL)
            ->pluck('users_emails.email')
            ->toArray();
    }
}
