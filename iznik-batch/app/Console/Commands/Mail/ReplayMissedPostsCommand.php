<?php

namespace App\Console\Commands\Mail;

use App\Mail\Digest\UnifiedDigest;
use App\Models\Membership;
use App\Models\Message;
use App\Models\User;
use App\Services\EmailSpoolerService;
use App\Services\Mail\MailSuppressionService;
use App\Services\UnifiedDigestService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Re-send the per-post emails a member lost while their provider was suppressed.
 *
 * Most suppressed mail is only DELAYED: the immediate-digest cursor holds while
 * spool() declines, and once the suppression lifts the same messages go out.
 * That is what happened to the bulk of the Gmail episode on 2026-08-19 - the
 * decline counters read 84,352, but that is spool ATTEMPTS (about 52 retries of
 * the same posts), and 384 members were emailed within twelve minutes of
 * release.
 *
 * A residue really is lost, though. When the suppression lifts, the cursor
 * advances for the group as a whole, so a member whose address was still
 * suppressed at that instant is stepped over and never revisited - member
 * 43125277's preferred address was on googlemail.com, released one second after
 * gmail.com, and they got nothing.
 *
 * The cursor cannot be rewound to fix that: it is per GROUP, shared by every
 * member of it, so replaying one member's posts would re-send them to everyone
 * else who already had them. Instead reconstruct per member from
 * email_tracking, which records the exact msgids each digest carried, and mail
 * only the difference.
 */
class ReplayMissedPostsCommand extends Command
{
    protected $signature = 'mail:deferrals:replay-missed-posts
        {--suppression= : Comma-separated mail_suppressions ids whose members to check}
        {--start= : Start of the outage window (Y-m-d H:i:s)}
        {--end= : End of the outage window (Y-m-d H:i:s)}
        {--max-posts=25 : Skip anyone owed more than this, rather than send a wall of post}
        {--limit=1000 : Maximum members to process}
        {--dry-run : Report what would be sent without sending}';

    protected $description = 'Re-send per-post emails that were permanently lost while a provider was suppressed';

    /** How far back to look for a previous send of the same post. */
    private const LOOKBACK_DAYS = 180;

    public function handle(EmailSpoolerService $spooler, MailSuppressionService $suppressions): int
    {
        $ids = array_values(array_filter(array_map(
            'intval',
            explode(',', (string) $this->option('suppression'))
        )));

        $start = (string) $this->option('start');
        $end = (string) $this->option('end');

        if ($ids === [] || $start === '' || $end === '') {
            $this->error('--suppression, --start and --end are all required: this sends real mail.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $maxPosts = (int) $this->option('max-posts');

        $userIds = DB::table('mail_suppressed_counts')
            ->whereIn('suppressionid', $ids)
            ->where('emailtype', 'like', 'digest%')
            ->distinct()
            ->limit((int) $this->option('limit'))
            ->pluck('userid');

        $this->info(sprintf(
            '%d members had digest mail declined%s',
            $userIds->count(),
            $dryRun ? ' [DRY RUN]' : ''
        ));

        $sent = 0;
        $nothingMissed = 0;
        $stillSuppressed = 0;
        $tooMany = 0;

        foreach ($userIds as $userId) {
            $user = User::find($userId);
            $email = $user?->email_preferred;

            if ($user === null || ! $email) {
                continue;
            }

            if ($suppressions->isSuppressed($email)) {
                // Sending now would only be declined again, and would burn the
                // one catch-up we owe them.
                $stillSuppressed++;

                continue;
            }

            $missed = $this->missedPostsFor((int) $userId, $start, $end);

            if ($missed->isEmpty()) {
                // The overwhelming majority: delayed, then delivered on release.
                $nothingMissed++;

                continue;
            }

            if ($missed->count() > $maxPosts) {
                $this->warn(sprintf('  user %d owed %d posts - skipped, review by hand', $userId, $missed->count()));
                $tooMany++;

                continue;
            }

            if ($dryRun) {
                $this->line(sprintf('  user %d would get %d post(s)', $userId, $missed->count()));
                $sent++;

                continue;
            }

            try {
                $spooler->spool(
                    new UnifiedDigest($user, $missed, UnifiedDigestService::MODE_DAILY),
                    $email,
                    emailType: 'digest_daily',
                );
                $sent++;
            } catch (\Throwable $e) {
                $this->warn(sprintf('  user %d failed: %s', $userId, $e->getMessage()));
            }
        }

        $this->info(sprintf(
            'sent=%d nothing-missed=%d still-suppressed=%d too-many=%d%s',
            $sent,
            $nothingMissed,
            $stillSuppressed,
            $tooMany,
            $dryRun ? ' [DRY RUN - nothing sent]' : ''
        ));

        if (! $dryRun && $sent > 0) {
            Log::info('Replayed posts lost to a provider suppression', [
                'window_start' => $start,
                'window_end' => $end,
                'members_mailed' => $sent,
            ]);
        }

        return self::SUCCESS;
    }

    /**
     * Posts that arrived on this member's immediate-mail communities during the
     * outage and which no email to them has ever carried.
     *
     * "Ever carried" is the important half. A post can reappear in
     * messages_groups with a fresh arrival because it was auto-reposted, having
     * been mailed to this member weeks ago - msgid 120806860 was reposted at
     * 04:00 during the Gmail outage and looked missed, but both sample members
     * had already had it on 2026-07-31. Its absence from the outage window was
     * correct behaviour, not a loss.
     */
    private function missedPostsFor(int $userId, string $start, string $end)
    {
        $candidates = DB::table('memberships as mem')
            ->join('messages_groups as mg', 'mg.groupid', '=', 'mem.groupid')
            ->join('messages as m', 'm.id', '=', 'mg.msgid')
            ->where('mem.userid', $userId)
            ->where('mem.emailfrequency', Membership::EMAIL_FREQUENCY_IMMEDIATE)
            ->where('mem.collection', Membership::COLLECTION_APPROVED)
            ->whereBetween('mg.arrival', [$start, $end])
            ->where('mg.collection', 'Approved')
            ->where('mg.deleted', 0)
            ->whereNull('m.deleted')
            // Their own post is not news to them.
            ->where(function ($q) use ($userId) {
                $q->whereNull('m.fromuser')->orWhere('m.fromuser', '!=', $userId);
            })
            ->distinct()
            ->get(['mg.msgid', 'mg.groupid']);

        if ($candidates->isEmpty()) {
            return collect();
        }

        $alreadyEmailed = $this->msgidsEverEmailedTo($userId);

        $missing = $candidates->reject(fn ($c) => isset($alreadyEmailed[(int) $c->msgid]));

        if ($missing->isEmpty()) {
            return collect();
        }

        $messages = Message::whereIn('id', $missing->pluck('msgid')->unique())->get()->keyBy('id');

        return $missing
            ->filter(fn ($c) => $messages->has((int) $c->msgid))
            ->map(fn ($c) => [
                'message' => $messages[(int) $c->msgid],
                'postedToGroups' => [(int) $c->groupid],
            ])
            ->values();
    }

    /**
     * Every post id we have ever put in an email to this member, from
     * email_tracking's metadata. That table is the only per-member record of
     * what a digest actually carried.
     *
     * @return array<int, true>
     */
    private function msgidsEverEmailedTo(int $userId): array
    {
        $rows = DB::table('email_tracking')
            ->where('userid', $userId)
            ->where('sent_at', '>=', now()->subDays(self::LOOKBACK_DAYS))
            ->whereNotNull('metadata')
            ->pluck('metadata');

        $seen = [];

        foreach ($rows as $json) {
            $meta = json_decode((string) $json, true);

            foreach ((array) ($meta['post_msgids'] ?? []) as $id) {
                $seen[(int) $id] = true;
            }
        }

        return $seen;
    }
}
