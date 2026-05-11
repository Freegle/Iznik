<?php

namespace App\Services;

use App\Mail\Chat\ChaseupModsMail;
use App\Models\ChatRoom;
use App\Models\Group;
use App\Models\Membership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class ChatChaseupModsService
{
    // Seconds before we chase up mods — V1: $age = 566400 (6.55 days)
    public const CHASEUP_AGE_SECONDS = 566400;

    /**
     * Notify group mods about User2Mod chats where the member has had no reply.
     *
     * Finds User2Mod chats that have had recent activity (any message in last 2 days),
     * where only the initiating member has sent messages (no mod reply), and the
     * conversation started more than CHASEUP_AGE_SECONDS ago.
     *
     * @return array{notified: int, chats_checked: int}
     */
    public function chaseupMods(bool $dryRun = false): array
    {
        $noReplyAddr = config('freegle.mail.noreply_addr');
        $userDomain = config('freegle.mail.user_domain');
        $modSite = config('freegle.sites.mod');

        // Find User2Mod rooms that have had any message in the last 2 days
        $cutoff = now()->subDays(2)->toDateTimeString();
        $rooms = DB::table('chat_rooms')
            ->join('chat_messages', 'chat_rooms.id', '=', 'chat_messages.chatid')
            ->where('chat_rooms.chattype', ChatRoom::TYPE_USER2MOD)
            ->where('chat_messages.date', '>=', $cutoff)
            ->distinct()
            ->select('chat_rooms.id AS room_id', 'chat_rooms.groupid')
            ->get();

        $notified = 0;
        $chatsChecked = 0;

        foreach ($rooms as $room) {
            $chatsChecked++;

            if (!$room->groupid) {
                continue;
            }

            // Get ALL messages for this room (not filtered by date)
            $messages = DB::table('chat_messages')
                ->where('chatid', $room->room_id)
                ->whereNotNull('message')
                ->orderBy('date')
                ->get(['userid', 'message', 'date']);

            if ($messages->isEmpty()) {
                continue;
            }

            // Only chase up if exactly one user has sent messages (no mod reply)
            $senderIds = $messages->pluck('userid')->unique();
            if ($senderIds->count() !== 1) {
                continue;
            }

            $memberId = $senderIds->first();

            // Check the sole sender is ROLE_MEMBER (not a mod/owner doing the asking)
            $role = DB::table('memberships')
                ->where('userid', $memberId)
                ->where('groupid', $room->groupid)
                ->value('role');

            if ($role !== Membership::ROLE_MEMBER) {
                continue;
            }

            // Check the conversation started more than CHASEUP_AGE_SECONDS ago
            $firstMessage = $messages->first();
            $ageSeconds = abs(now()->diffInSeconds(\Carbon\Carbon::parse($firstMessage->date)));
            if ($ageSeconds < self::CHASEUP_AGE_SECONDS) {
                continue;
            }

            // Check the group is Freegle
            $group = DB::table('groups')
                ->where('id', $room->groupid)
                ->select(['id', 'nameshort', 'type'])
                ->first();

            if (!$group || $group->type !== Group::TYPE_FREEGLE) {
                continue;
            }

            // Get member details
            $memberEmail = DB::table('users_emails')
                ->where('userid', $memberId)
                ->orderByDesc('preferred')
                ->value('email');

            $member = DB::table('users')->where('id', $memberId)->first();
            $memberName = $member?->fullname
                ?? trim(($member?->firstname ?? '') . ' ' . ($member?->lastname ?? ''))
                ?: 'Member';

            // Build message summary
            $textSummary = $messages->map(fn ($m) => $m->message)->implode("\r\n");

            $replyTo = "notify-{$room->room_id}-{$memberId}@{$userDomain}";
            $chatUrl = "{$modSite}/modtools/chats/{$room->room_id}";

            // Get all group mods to notify
            $mods = DB::table('memberships')
                ->join('users', 'users.id', '=', 'memberships.userid')
                ->where('memberships.groupid', $room->groupid)
                ->whereIn('memberships.role', [Membership::ROLE_OWNER, Membership::ROLE_MODERATOR])
                ->where('memberships.collection', Membership::COLLECTION_APPROVED)
                ->whereNull('users.deleted')
                ->pluck('memberships.userid')
                ->toArray();

            foreach ($mods as $modId) {
                $modEmail = DB::table('users_emails')
                    ->where('userid', $modId)
                    ->orderByDesc('preferred')
                    ->value('email');

                if (!$modEmail) {
                    continue;
                }

                $notified++;

                if (!$dryRun) {
                    Mail::send(new ChaseupModsMail(
                        recipientEmail: $modEmail,
                        groupName: $group->nameshort,
                        memberName: $memberName,
                        memberEmail: $memberEmail ?? '',
                        textSummary: $textSummary,
                        chatUrl: $chatUrl,
                        fromAddress: $noReplyAddr,
                        replyToAddress: $replyTo,
                    ));
                }
            }
        }

        return ['notified' => $notified, 'chats_checked' => $chatsChecked];
    }
}
