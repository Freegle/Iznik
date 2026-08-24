<?php

namespace App\Services\Mail\Deferrals;

use App\Mail\Deferrals\UnreadChatCatchUpMail;
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

                $summary = $this->unreadChatSummary((int) $userId, $row->firstat ?? null);

                if ($summary['chats'] === 0 || $summary['messages'] === 0) {
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
                            delayedSince: $this->formatSince($row->firstat),
                            provider: $this->providerFor($row->suppressionid ?? null),
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
     * read.
     *
     * Both halves of that are load-bearing, and getting either wrong makes the
     * email lie in the alarming direction.
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
     * @return array{chats:int, messages:int}
     */
    private function unreadChatSummary(int $userId, ?string $since): array
    {
        $q = DB::table('chat_roster')
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

        $row = $q->selectRaw('COUNT(DISTINCT chat_roster.chatid) AS chats, COUNT(*) AS messages')
            ->first();

        return [
            'chats' => (int) ($row->chats ?? 0),
            'messages' => (int) ($row->messages ?? 0),
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
     * The provider that was refusing us, from the suppression we recorded at
     * the time we declined to send.
     *
     * Recorded rather than re-derived: by the time the catch-up runs the
     * suppression has been released, and working backwards from the member's
     * address would mean reimplementing the mailer's own address-ranking
     * rules against history that has already moved on.
     */
    private function providerFor(?int $suppressionId): ?string
    {
        if ($suppressionId === null || $suppressionId <= 0) {
            return null;
        }

        return DB::table('mail_suppressions')->where('id', $suppressionId)->value('provider');
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
