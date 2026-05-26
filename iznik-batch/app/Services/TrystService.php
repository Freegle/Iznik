<?php

namespace App\Services;

use App\Mail\Tryst\TrystCalendarInviteMail;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class TrystService
{
    /**
     * Send calendar invite emails for trysts that haven't been notified yet.
     *
     * Mirrors V1 Tryst::sendCalendarsDue():
     * - Skips trysts arranged for today (same-day handovers — user won't forget so no invite needed).
     * - Skips trysts with no specific time (arrangedfor like '% 00:00:00').
     *
     * @return int Number of trysts for which invites were sent.
     */
    public function sendCalendarsDue(bool $dryRun = false): int
    {
        $tomorrow = now()->setTime(0, 0)->addDay()->format('Y-m-d');

        $trysts = DB::table('trysts')
            ->where('icssent', 0)
            ->where('arrangedfor', '>=', $tomorrow)
            ->where('arrangedfor', 'NOT LIKE', '% 00:00:00')
            ->whereNotNull('user1')
            ->whereNotNull('user2')
            ->get(['id', 'user1', 'user2', 'arrangedfor']);

        $sent = 0;

        foreach ($trysts as $tryst) {
            $user1 = User::with('emails')->find($tryst->user1);
            $user2 = User::with('emails')->find($tryst->user2);

            if (!$user1 || !$user2) {
                continue;
            }

            $calendarLink = $this->buildCalendarLink($user1, $user2, $tryst->arrangedfor);

            if ($dryRun) {
                Log::info('[DRY RUN] Would send calendar invite', [
                    'tryst_id' => $tryst->id,
                    'user1' => $tryst->user1,
                    'user2' => $tryst->user2,
                    'arrangedfor' => $tryst->arrangedfor,
                ]);
                $sent++;
                continue;
            }

            $link1 = $this->sendCalendarToUser($user1, $user2, $calendarLink);
            $link2 = $this->sendCalendarToUser($user2, $user1, $calendarLink);

            DB::table('trysts')->where('id', $tryst->id)->update([
                'icssent' => 1,
                'ics1uid' => $link1,
                'ics2uid' => $link2,
            ]);

            $sent++;
        }

        return $sent;
    }

    /**
     * Send reminder chat messages for trysts due within the next 30 min–4 hours.
     *
     * Mirrors V1 Tryst::sendRemindersDue():
     * - Only for trysts arranged before today (not same-day arrangements).
     * - Inserts a Reminder chat message into the user's conversation.
     *
     * @return int Number of reminders sent.
     */
    public function sendRemindersDue(bool $dryRun = false): int
    {
        $today = now()->setTime(0, 0)->format('Y-m-d');
        $windowStart = now()->addMinutes(30)->format('Y-m-d H:i:s');
        $windowEnd = now()->addHours(4)->format('Y-m-d H:i:s');

        $trysts = DB::table('trysts')
            ->whereNull('remindersent')
            ->where('arrangedat', '<', $today)
            ->whereBetween('arrangedfor', [$windowStart, $windowEnd])
            ->whereNotNull('user1')
            ->whereNotNull('user2')
            ->get(['id', 'user1', 'user2', 'arrangedfor']);

        $sent = 0;

        foreach ($trysts as $tryst) {
            $arrangedfor = new \DateTime($tryst->arrangedfor, new \DateTimeZone('UTC'));
            $arrangedfor->setTimezone(new \DateTimeZone('Europe/London'));
            $time = $arrangedfor->format('h:i A');

            if ($dryRun) {
                Log::info('[DRY RUN] Would send reminder', [
                    'tryst_id' => $tryst->id,
                    'user1' => $tryst->user1,
                    'user2' => $tryst->user2,
                    'time' => $time,
                ]);
                $sent++;
                continue;
            }

            $chatRoomId = $this->getOrCreateConversation($tryst->user1, $tryst->user2);

            if (!$chatRoomId) {
                Log::warning('TrystService: could not get/create chat room', [
                    'tryst_id' => $tryst->id,
                    'user1' => $tryst->user1,
                    'user2' => $tryst->user2,
                ]);
                continue;
            }

            DB::table('chat_messages')->insert([
                'chatid' => $chatRoomId,
                'userid' => $tryst->user1,
                'message' => "Automatic reminder: Handover at {$time}.  Please confirm that's still ok or let them know if things have changed.  Everybody hates a no-show...",
                'type' => 'Reminder',
                'date' => now(),
                'processingrequired' => 1,
                'platform' => 0,
            ]);

            DB::table('trysts')->where('id', $tryst->id)->update([
                'remindersent' => now(),
            ]);

            Log::info('TrystService: reminder sent', [
                'tryst_id' => $tryst->id,
                'chat_room_id' => $chatRoomId,
            ]);

            $sent++;
        }

        return $sent;
    }

    /**
     * Build the calendar event link for a tryst.
     * Encodes event data as base64 JSON so the frontend can render a multi-calendar picker.
     */
    private function buildCalendarLink(User $giver, User $receiver, string $arrangedfor): string
    {
        $tz = new \DateTimeZone('Europe/London');
        $dt = new \DateTime($arrangedfor, new \DateTimeZone('UTC'));
        $dt->setTimezone($tz);

        $end = clone $dt;
        $end->add(new \DateInterval('PT15M'));

        $userSite = config('freegle.sites.user', 'https://www.ilovefreegle.org');

        $eventData = [
            'name' => 'Handover: ' . $giver->display_name . ' and ' . $receiver->display_name,
            'description' => "Please add to your calendar.  If anything changes please let them know through Chat on {$userSite}",
            'startDate' => $dt->format('Y-m-d'),
            'startTime' => $dt->format('H:i'),
            'endTime' => $end->format('H:i'),
            'timeZone' => 'Europe/London',
            'location' => '',
        ];

        return $userSite . '/calendar?data=' . base64_encode(json_encode($eventData));
    }

    /**
     * Send a calendar invite to one participant of a tryst.
     *
     * @return string|null The calendar link if sent, null if skipped.
     */
    private function sendCalendarToUser(User $recipient, User $other, string $calendarLink): ?string
    {
        if ($recipient->deleted || !$recipient->notifsOn('email')) {
            return null;
        }

        // V1 parity: skip our own per-user-alias domains so the mail can't loop back as chat.
        $emailAddr = $recipient->email_preferred;

        if (!$emailAddr) {
            return null;
        }

        $title = 'Handover: ' . $recipient->display_name . ' and ' . $other->display_name;

        try {
            Mail::send(new TrystCalendarInviteMail(
                title: $title,
                calendarLink: $calendarLink,
                recipientUserId: $recipient->id,
                recipientName: $recipient->displayname,
                recipientEmail: $emailAddr,
            ));

            return $calendarLink;
        } catch (\Throwable $e) {
            Log::error('TrystService: failed to send calendar invite', [
                'user_id' => $recipient->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get existing User2User conversation or create one.
     * Mirrors V1 ChatRoom::createConversation() — simplified for the reminder use case.
     *
     * @return int|null Chat room ID, or null if blocked/failed.
     */
    private function getOrCreateConversation(int $user1, int $user2): ?int
    {
        $existing = DB::table('chat_rooms')
            ->where(function ($q) use ($user1, $user2) {
                $q->where('user1', $user1)->where('user2', $user2);
            })
            ->orWhere(function ($q) use ($user1, $user2) {
                $q->where('user1', $user2)->where('user2', $user1);
            })
            ->where('chattype', 'User2User')
            ->value('id');

        if ($existing) {
            return $existing;
        }

        $id = DB::table('chat_rooms')->insertGetId([
            'user1' => $user1,
            'user2' => $user2,
            'chattype' => 'User2User',
            'latestmessage' => now(),
        ]);

        return $id ?: null;
    }
}
