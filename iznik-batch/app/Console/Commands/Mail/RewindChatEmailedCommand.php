<?php

namespace App\Console\Commands\Mail;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Make a relay purge safe for chat notifications.
 *
 * When a provider stops accepting our mail, the messages we had ALREADY
 * generated sit in the relay queue until they expire. Purging that queue is
 * usually right - they cannot be delivered and each expiry costs a DSN - but
 * chat notifications are a special case, because generating one advances
 * chat_roster.lastmsgemailed. Delete the queued mail and the member looks
 * caught up on a reply they never saw: the deferral catch-up computes unread
 * from lastmsgemailed, finds nothing, and nobody ever tells them.
 *
 * So before purging, do two things.
 *
 * Rewind lastmsgemailed past anything we generated but could not deliver, so
 * the member looks un-notified again, which is the truth.
 *
 * Then record a chat debt row, because the rewind alone is not enough: the
 * ordinary notifier only looks back a few hours (NotifyUser2UserCommand
 * defaults to --since=4), and a queue that reached maximal_queue_lifetime is
 * five days deep, so every one of these messages has aged out of it and will
 * never be picked up again. The deferral catch-up has no such window - it
 * counts anything newer than lastmsgemailed - but it only runs for members who
 * are owed something. The debt row is what makes it run, and on release they
 * get one "you have unread messages" summary rather than a replay.
 *
 * Only ever moves the marker BACKWARDS. A chat whose marker is already behind
 * the stuck message, or absent, is left alone.
 */
class RewindChatEmailedCommand extends Command
{
    protected $signature = 'mail:deferrals:rewind-chat
        {--start= : Earliest notification to consider (Y-m-d H:i:s)}
        {--end= : Latest notification to consider (Y-m-d H:i:s)}
        {--provider= : Only domains suppressed for this provider, e.g. Yahoo}
        {--domains= : Comma-separated recipient domains, instead of --provider}
        {--limit=20000 : Maximum chat rosters to rewind}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Rewind chat_roster.lastmsgemailed for undeliverable chat notifications, so a relay purge cannot silently swallow a reply';

    public function handle(): int
    {
        $start = (string) $this->option('start');
        $end = (string) $this->option('end');

        if ($start === '' || $end === '') {
            $this->error('--start and --end are required: this rewinds live notification state, so the window must be deliberate.');

            return self::FAILURE;
        }

        $domains = $this->resolveDomains();

        if ($domains === []) {
            $this->error('No domains resolved. Pass --domains, or --provider matching an active suppression.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');

        $this->info(sprintf(
            'Window %s .. %s over %d domain(s)%s',
            $start,
            $end,
            count($domains),
            $dryRun ? ' [DRY RUN]' : ''
        ));

        // One row per (member, chat): the earliest notification we generated in
        // the window. Rewinding to just before it re-opens every later message
        // in that chat too, which is what we want - they were all equally
        // undeliverable.
        $rows = DB::table('email_tracking')
            ->selectRaw("userid,
                 SUBSTRING_INDEX(recipient_email, '@', -1) AS domain,
                 JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.chat_id')) AS chatid,
                 MIN(CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.message_id')) AS UNSIGNED)) AS firstmsg,
                 COUNT(*) AS notifications")
            ->where('email_type', 'ChatNotification')
            ->whereBetween('sent_at', [$start, $end])
            ->whereIn(DB::raw("SUBSTRING_INDEX(recipient_email, '@', -1)"), $domains)
            ->whereNotNull('userid')
            ->whereNotNull('metadata')
            ->groupBy('userid', 'domain', 'chatid')
            ->limit($limit)
            ->get();

        $this->info(sprintf('%d (member, chat) pairs generated but undeliverable.', $rows->count()));

        $rewound = 0;
        $alreadyBehind = 0;
        $missing = 0;

        // domain => active suppression id, for attributing the debt.
        $suppressionByDomain = DB::table('mail_suppressions')
            ->where('scope', 'domain')
            ->whereNull('released_at')
            ->pluck('id', 'value');

        // member => notifications we could not deliver, summed across chats.
        $owed = [];

        foreach ($rows as $row) {
            $chatid = (int) $row->chatid;
            $firstmsg = (int) $row->firstmsg;

            if ($chatid === 0 || $firstmsg === 0) {
                $missing++;

                continue;
            }

            $target = $firstmsg - 1;

            // Accumulated before the dry-run branch below: doing it after means
            // a dry run always reports zero debt, which reads as "nothing to
            // do" rather than "this check did not run".
            $uid = (int) $row->userid;
            $owed[$uid]['count'] = ($owed[$uid]['count'] ?? 0) + (int) $row->notifications;
            $owed[$uid]['domain'] = strtolower((string) $row->domain);

            // Rewind ONLY. A marker already at or behind the target means the
            // member is still due this message by the ordinary route, and
            // moving it would be the very data loss this command exists to
            // prevent.
            $query = DB::table('chat_roster')
                ->where('chatid', $chatid)
                ->where('userid', (int) $row->userid)
                ->whereNotNull('lastmsgemailed')
                ->where('lastmsgemailed', '>', $target);

            if ($dryRun) {
                if ($query->exists()) {
                    $rewound++;
                } else {
                    $alreadyBehind++;
                }

                continue;
            }

            // One row at a time, by unique key (chatid, userid).
            $affected = $query->update(['lastmsgemailed' => $target]);

            if ($affected > 0) {
                $rewound++;
            } else {
                $alreadyBehind++;
            }
        }

        $debtRows = $this->recordChatDebt($owed, $suppressionByDomain, $dryRun);

        $this->info(sprintf(
            'rewound=%d already-behind=%d unparseable=%d chat-debt-rows=%d%s',
            $rewound,
            $alreadyBehind,
            $missing,
            $debtRows,
            $dryRun ? ' [DRY RUN - nothing written]' : ''
        ));

        if (! $dryRun) {
            Log::info('Rewound chat notification markers before relay purge', [
                'window_start' => $start,
                'window_end' => $end,
                'domains' => count($domains),
                'rewound' => $rewound,
                'already_behind' => $alreadyBehind,
            ]);
        }

        return self::SUCCESS;
    }

    /**
     * Record what each member is owed, so the release catch-up runs for them.
     *
     * insertOrIgnore against the unique (userid, emailtype) key: a member who
     * already has a live chat debt is already covered, and overwriting their
     * row would reset counters the catch-up is still accruing.
     *
     * @param  array<int, array{count:int, domain:string}>  $owed
     */
    private function recordChatDebt(array $owed, $suppressionByDomain, bool $dryRun): int
    {
        $rows = [];

        foreach ($owed as $userId => $info) {
            $suppressionId = $suppressionByDomain[$info['domain']] ?? null;

            if ($suppressionId === null) {
                // Their provider is no longer suppressed, so there is no
                // release still to come and nothing to hang a catch-up on.
                continue;
            }

            $rows[] = [
                'userid' => $userId,
                'emailtype' => 'chat',
                'suppressionid' => $suppressionId,
                'count' => $info['count'],
                'firstat' => now(),
                'lastat' => now(),
            ];
        }

        if ($dryRun || $rows === []) {
            return count($rows);
        }

        $written = 0;

        foreach (array_chunk($rows, 200) as $chunk) {
            $written += DB::table('mail_suppressed_counts')->insertOrIgnore($chunk);
        }

        return $written;
    }

    /**
     * @return string[]
     */
    private function resolveDomains(): array
    {
        $explicit = trim((string) $this->option('domains'));

        if ($explicit !== '') {
            return array_values(array_filter(array_map(
                static fn ($d) => strtolower(trim($d)),
                explode(',', $explicit)
            )));
        }

        $provider = trim((string) $this->option('provider'));

        if ($provider === '') {
            return [];
        }

        return DB::table('mail_suppressions')
            ->where('scope', 'domain')
            ->whereNull('released_at')
            ->where('provider', $provider)
            ->pluck('value')
            ->map(static fn ($v) => strtolower((string) $v))
            ->all();
    }
}
