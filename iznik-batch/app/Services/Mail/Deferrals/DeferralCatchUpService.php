<?php

namespace App\Services\Mail\Deferrals;

use App\Mail\Deferrals\UnreadChatCatchUpMail;
use App\Models\ChatRoom;
use App\Models\User;
use App\Services\EmailSpoolerService;
use App\Services\Mail\MailSuppressionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * What we send once a provider starts accepting our mail again.
 *
 * The rule is that we do not replay the backlog. We never stored the mail we
 * declined to render - storing it would have defeated the point - and even if
 * we had, most of it would be worse than useless by now. So the policy is per
 * type, and it is deliberately asymmetric:
 *
 *   OFFER/WANTED posts   dropped. A three-day-old post is taken or gone, and
 *                        the immediate-mail cursor is per group rather than
 *                        per member, so it has already moved on regardless.
 *   Community News,      dropped. All of them are periodic; the next one
 *   WeMissYou,           along is a better email than a stale one.
 *   volunteering
 *   Daily digest         one catch-up covering the whole window. This needs
 *                        no code here: the suppression skip returns before
 *                        the digest tracker is advanced, so the next daily
 *                        run naturally spans the gap and sends exactly one.
 *   Chat notifications   one "you have unread messages" summary. Chat is the
 *                        only one where the member is waiting on a person
 *                        rather than on us, so silence is the costly outcome.
 *                        Chats with members and a moderator's chats on the
 *                        volunteers' side are counted apart, because they
 *                        are read in different places (the member site and
 *                        ModTools).
 *
 * Which leaves this class with one real job: the chat summary.
 */
class DeferralCatchUpService
{
    public function __construct(
        private readonly MailSuppressionService $suppressions,
    ) {}

    /**
     * Send catch-ups to everyone whose suppression has lifted.
     *
     * @return array{sent: int, dropped: int, skipped: int}
     */
    public function run(bool $dryRun = false, int $limit = 5000): array
    {
        $sent = 0;
        $dropped = 0;
        $skipped = 0;

        $owed = DB::table('mail_suppressed_counts')
            ->whereNull('caughtup_at')
            ->orderBy('userid')
            ->limit($limit)
            ->get();

        // Group by member so someone who missed digests AND chat gets one
        // decision, not one per row.
        $byUser = [];
        foreach ($owed as $row) {
            $byUser[$row->userid][$row->emailtype] = $row;
        }

        foreach ($byUser as $userId => $rows) {
            $user = User::find($userId);
            $email = $user?->email_preferred;

            if ($user === null || ! $email) {
                // Nothing we can do for them; clear the debt so it does not
                // sit in the table for ever.
                $dropped += count($rows);
                if (! $dryRun) {
                    $this->markCaughtUp((int) $userId, array_keys($rows));
                }

                continue;
            }

            if ($this->suppressions->isSuppressed($email)) {
                // Still blocked - either the release has not reached this
                // member's provider or a second episode started. Leave the
                // counters alone; they are still accruing.
                $skipped += count($rows);

                continue;
            }

            foreach ($rows as $type => $row) {
                if ($type !== 'chat') {
                    // Everything except chat is dropped by policy. Recorded
                    // rather than silently forgotten, so the numbers in the
                    // log add up.
                    $dropped++;

                    continue;
                }

                $suppression = $this->suppressionFor($row->suppressionid ?? null);

                // The count is bounded to what WE held back (firstat); the date
                // is when the PROVIDER started refusing us. They differ when the
                // block began hours before this member next had mail due.
                $summary = $this->unreadChatSummary((int) $userId, $row->firstat ?? null);

                if ($summary['messages'] + $summary['modMessages'] === 0) {
                    // Nothing arrived while we were silent that they have not
                    // since read - they went to the website, or the other side
                    // gave up. No email is the right email.
                    $dropped++;

                    continue;
                }

                if ($dryRun) {
                    $sent++;

                    continue;
                }

                try {
                    // spool() returns the empty string rather than throwing
                    // when it declines a message - a permanently bad address,
                    // or the deferral backstop if a fresh episode started
                    // since we checked. Counting that as sent is how a member
                    // ends up permanently owed a catch-up nobody knows about.
                    $spoolId = app(EmailSpoolerService::class)->spool(
                        new UnreadChatCatchUpMail(
                            recipientUserId: (int) $userId,
                            recipientEmail: $email,
                            recipientName: $user->displayname ?: 'there',
                            chatCount: $summary['chats'],
                            messageCount: $summary['messages'],
                            delayedSince: $this->formatSince($suppression?->deferred_since ?? $row->firstat ?? null),
                            provider: $suppression?->provider,
                            modChatCount: $summary['modChats'],
                            modMessageCount: $summary['modMessages'],
                        ),
                        $email,
                        emailType: 'chat_catchup'
                    );

                    if ($spoolId === '') {
                        Log::warning('Mail deferrals: the spooler declined the catch-up', [
                            'userid' => $userId,
                        ]);
                        $dropped++;
                    } else {
                        $sent++;
                    }
                } catch (\Throwable $e) {
                    Log::error('Mail deferrals: catch-up send failed', [
                        'userid' => $userId,
                        'error' => $e->getMessage(),
                    ]);

                    // Leave the row unclaimed so the next pass retries it.
                    $skipped++;

                    continue 2;
                }
            }

            if (! $dryRun) {
                $this->markCaughtUp((int) $userId, array_keys($rows));
            }
        }

        Log::info('Mail deferrals: catch-up pass complete', [
            'sent' => $sent,
            'dropped' => $dropped,
            'skipped' => $skipped,
            'dry_run' => $dryRun,
        ]);

        return ['sent' => $sent, 'dropped' => $dropped, 'skipped' => $skipped];
    }

    /**
     * What this member missed WHILE WE WERE NOT EMAILING THEM, and has still not
     * read - split by where they would go to read it.
     *
     * Both halves of "missed and unread" are load-bearing, and getting either
     * wrong makes the email lie in the alarming direction.
     *
     * NOT lastmsgemailed. That watermark records what we have EMAILED, not what
     * they have SEEN, so for anyone who reads on the website - who is therefore
     * rarely emailed - it sits far behind for ever, and where it is NULL the
     * whole chat counts from its first message. Measured on 2026-08-22, member
     * 420 was told "1,247 messages across 7 chats"; the true unread figure was
     * 8 across 3, the oldest message it counted was from January 2019, and the
     * number that arrived during the outage and is still unread was ZERO. He
     * should not have had this email at all.
     *
     * And bounded to the outage. The sentence this feeds is "while it was going
     * on you had N messages", so counting anything from before the provider
     * started refusing us is counting something else entirely.
     *
     * Member side vs mod side. A moderator is on the roster of every User2Mod
     * chat on their groups, on the volunteers' side, and of every Mod2Mod chat.
     * Those are only visible in ModTools: the member site lists User2Mod chats
     * where the viewer IS the member (user1) and nothing else. On 2026-09-12 a
     * moderator was told "a message in one chat", followed the button to the
     * member site, and found only a two-month-old chat of her own; the message
     * was a member writing to her group's volunteers. So the two are counted
     * apart and the email sends each to where it can be read.
     *
     * @return array{chats:int, messages:int, modChats:int, modMessages:int}
     */
    private function unreadChatSummary(int $userId, ?string $since): array
    {
        $modSide = sprintf(
            "(chat_rooms.chattype = '%s' OR (chat_rooms.chattype = '%s' AND chat_rooms.user1 <> chat_roster.userid))",
            ChatRoom::TYPE_MOD2MOD,
            ChatRoom::TYPE_USER2MOD
        );

        $q = DB::table('chat_roster')
            ->join('chat_rooms', 'chat_rooms.id', '=', 'chat_roster.chatid')
            ->join('chat_messages', 'chat_messages.chatid', '=', 'chat_roster.chatid')
            ->where('chat_roster.userid', $userId)
            // Their own messages are not something to catch up on.
            ->where('chat_messages.userid', '!=', $userId)
            ->where('chat_messages.reviewrejected', 0)
            // Unread: never seen this chat at all, or seen it only up to an
            // earlier message than this one.
            ->where(function ($w) {
                $w->whereNull('chat_roster.lastmsgseen')
                    ->orWhereColumn('chat_messages.id', '>', 'chat_roster.lastmsgseen');
            });

        if ($since !== null) {
            $q->where('chat_messages.date', '>=', $since);
        }

        $row = $q->selectRaw(
            "COUNT(DISTINCT CASE WHEN NOT {$modSide} THEN chat_roster.chatid END) AS chats,"
            ." SUM(CASE WHEN NOT {$modSide} THEN 1 ELSE 0 END) AS messages,"
            ." COUNT(DISTINCT CASE WHEN {$modSide} THEN chat_roster.chatid END) AS modchats,"
            ." SUM(CASE WHEN {$modSide} THEN 1 ELSE 0 END) AS modmessages"
        )->first();

        return [
            'chats' => (int) ($row->chats ?? 0),
            'messages' => (int) ($row->messages ?? 0),
            'modChats' => (int) ($row->modchats ?? 0),
            'modMessages' => (int) ($row->modmessages ?? 0),
        ];
    }

    /**
     * @param  string[]  $types
     */
    private function markCaughtUp(int $userId, array $types): void
    {
        DB::table('mail_suppressed_counts')
            ->where('userid', $userId)
            ->whereIn('emailtype', $types)
            ->whereNull('caughtup_at')
            ->update(['caughtup_at' => now()]);
    }

    /**
     * The suppression we recorded at the time we declined to send: who was
     * refusing us, and since when.
     *
     * Recorded rather than re-derived: by the time the catch-up runs the
     * suppression has been released, and working backwards from the member's
     * address would mean reimplementing the mailer's own address-ranking
     * rules against history that has already moved on.
     *
     * deferred_since is the provider's date, not ours. mail_suppressed_counts
     * .firstat is merely the first time we happened to have something to hold
     * back for this member, which can be a day or more after the provider
     * started refusing us; told "stopped accepting our emails on 11 September"
     * in an email sent on 11 September, a member reasonably concluded the
     * email was nonsense.
     *
     * @return object{provider: ?string, deferred_since: ?string}|null
     */
    private function suppressionFor(?int $suppressionId): ?object
    {
        if ($suppressionId === null || $suppressionId <= 0) {
            return null;
        }

        return DB::table('mail_suppressions')
            ->where('id', $suppressionId)
            ->select(['provider', 'deferred_since'])
            ->first();
    }

    private function formatSince(?string $when): string
    {
        if ($when === null) {
            return 'recently';
        }

        try {
            return Carbon::parse($when)->format('j F');
        } catch (\Throwable) {
            return 'recently';
        }
    }
}
