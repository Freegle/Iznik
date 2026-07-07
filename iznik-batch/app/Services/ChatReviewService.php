<?php

namespace App\Services;

use App\Models\Group;
use App\Models\Membership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class ChatReviewService
{
    /**
     * Auto-reject messages stuck in review for 7+ days, then notify group mods
     * about messages pending review for 48+ hours.
     *
     * @return array{rejected: int, notified_groups: int, total_pending: int}
     */
    public function processReview(bool $dryRun = false): array
    {
        $modbotUserId = $this->getModbotUserId();
        $rejected = $this->autoRejectStale($modbotUserId, $dryRun);
        ['notified_groups' => $notifiedGroups, 'total_pending' => $totalPending] = $this->notifyMods($dryRun);

        return [
            'rejected' => $rejected,
            'notified_groups' => $notifiedGroups,
            'total_pending' => $totalPending,
        ];
    }

    private function getModbotUserId(): ?int
    {
        $moderatorEmail = config('freegle.mail.moderator_email');
        $row = DB::table('users')
            ->join('users_emails', 'users_emails.userid', '=', 'users.id')
            ->where('users_emails.email', $moderatorEmail)
            ->whereNull('users.deleted')
            ->value('users.id');

        return $row ? (int) $row : null;
    }

    private function autoRejectStale(?int $modbotUserId, bool $dryRun): int
    {
        if ($modbotUserId === null) {
            return 0;
        }

        $threshold = date('Y-m-d', strtotime('midnight 7 days ago'));

        if ($dryRun) {
            return (int) DB::table('chat_messages')
                ->where('date', '<', $threshold)
                ->where('reviewrequired', 1)
                ->whereNull('reviewedby')
                ->count();
        }

        return DB::table('chat_messages')
            ->where('date', '<', $threshold)
            ->where('reviewrequired', 1)
            ->whereNull('reviewedby')
            ->update([
                'reviewedby' => $modbotUserId,
                'reviewrejected' => 1,
            ]);
    }

    private function notifyMods(bool $dryRun): array
    {
        $threshold = date('Y-m-d H:i:s', strtotime('48 hours ago'));
        $supportAddr = config('freegle.mail.support_addr');
        $mentorsAddr = config('freegle.mail.mentors_addr');
        $modSite = config('freegle.sites.mod');

        $groups = DB::select(
            "SELECT DISTINCT(memberships.groupid), COUNT(*) AS count
             FROM chat_messages
             INNER JOIN chat_rooms ON chat_rooms.id = chat_messages.chatid
             INNER JOIN users ON users.id = chat_messages.userid AND users.deleted IS NULL
             INNER JOIN memberships ON memberships.userid = (
                 CASE WHEN chat_rooms.user1 = chat_messages.userid
                 THEN chat_rooms.user2 ELSE chat_rooms.user1 END
             )
             LEFT JOIN chat_messages_held ON chat_messages_held.msgid = chat_messages.id
             WHERE chat_messages.date < ?
               AND reviewrequired = 1
               AND reviewedby IS NULL
               AND reviewrejected = 0
               AND chat_messages_held.id IS NULL
             GROUP BY groupid
             ORDER BY RAND()",
            [$threshold]
        );

        $totalPending = 0;
        $notifiedGroups = 0;
        $mentorsSummary = '';

        foreach ($groups as $group) {
            $groupData = DB::table('groups')
                ->where('id', $group->groupid)
                ->select(['id', 'nameshort', 'type', 'publish', 'contactmail'])
                ->first();

            if (!$groupData) {
                continue;
            }

            if ($groupData->type !== Group::TYPE_FREEGLE || !$groupData->publish) {
                continue;
            }

            $count = (int) $group->count;
            $totalPending += $count;
            $notifiedGroups++;

            $modsEmail = $groupData->contactmail
                ?: ($groupData->nameshort . '-volunteers@' . config('freegle.mail.group_domain'));

            $subject = $count . ' message' . ($count === 1 ? '' : 's')
                . ' between members waiting for your review on ' . $groupData->nameshort;

            $body = "Dear {$groupData->nameshort} Volunteers,\r\n\r\n"
                . "Messages between members are scanned to spot spam.  For some of these, we need you to review them to check whether they are really spam or not.\r\n\r\n"
                . "You currently have some messages which have been waiting for 48 hours for review.  Some of these may be real messages which members won't have received yet, so they may be wondering what's going on.\r\n\r\n"
                . "Please can you review these at {$modSite}/modtools/chats/review?  They will automatically be deleted after 7 days.\r\n\r\n"
                . "Thanks.\r\n\r\n"
                . "P.S. This is an automated mail sent once a day.  If you need help using ModTools, please ask the Mentor folk at {$mentorsAddr}.";

            $mentorsSummary .= $groupData->nameshort . ' count ' . $count . "\r\n";

            if (!$dryRun) {
                Mail::raw($body, function ($message) use ($subject, $supportAddr, $modsEmail) {
                    $message->from($supportAddr, 'Freegle')
                        ->to($modsEmail)
                        ->subject($subject);
                });
            }
        }

        if ($totalPending > 0 && !$dryRun) {
            $summaryBody = "Here's a list of groups which have chat messages pending review for more than 48 hours:\r\n\r\n{$mentorsSummary}";
            Mail::raw($summaryBody, function ($message) use ($supportAddr, $mentorsAddr) {
                $message->from($supportAddr, 'Freegle')
                    ->to($mentorsAddr)
                    ->subject('Summary of chat messages waiting for review');
            });
        }

        return ['notified_groups' => $notifiedGroups, 'total_pending' => $totalPending];
    }
}
