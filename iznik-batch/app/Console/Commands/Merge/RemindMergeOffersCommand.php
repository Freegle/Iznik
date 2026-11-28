<?php

namespace App\Console\Commands\Merge;

use App\Models\User;
use App\Services\EmailSpoolerService;
use App\Services\Merge\MergeOfferMailer;
use App\Services\Merge\MergeOfferRules;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Re-sends the merge offer email for offers sent while the link in it did not work (the /merge
 * page's answer was refused, April to November 2026).
 *
 * An offer is reminded when it was offered on or after --since, nobody has answered it, nobody
 * has been reminded of it before (merges.reminded), both accounts still exist, neither is a
 * TrashNothing account, neither has opted out of merging and neither has turned our email off or
 * is bouncing. The email is the original one with a plain opening line. Each offer is marked
 * reminded before its email is sent, so it is sent at most once.
 *
 * Dry run unless --apply; the dry run prints counts only. --limit caps the offers handled.
 */
#[AsCommand(name: 'merges:remind')]
class RemindMergeOffersCommand extends Command
{
    public const INTRO = 'The link in our earlier email about your two accounts did not work. It works now.';

    protected $signature = 'merges:remind
                            {--since=2026-04-02 : Offers made on or after this date}
                            {--apply : Send the emails (default is a dry run)}
                            {--limit= : Handle at most this many offers}';

    protected $description = 'Re-send merge offers whose link did not work';

    public function handle(MergeOfferRules $rules, MergeOfferMailer $mailer, EmailSpoolerService $spooler): int
    {
        $apply = (bool) $this->option('apply');
        $limit = $this->option('limit');
        $since = (string) $this->option('since');

        if (strtotime($since) === false) {
            $this->error('--since must be a date.');

            return self::FAILURE;
        }

        $this->info($apply ? 'APPLYING' : 'DRY RUN - use --apply to send');

        $query = DB::table('merges')
            ->where('offered', '>=', date('Y-m-d H:i:s', strtotime($since)))
            ->whereNull('accepted')
            ->whereNull('rejected')
            ->whereNull('reminded')
            ->whereNotNull('uid')
            ->orderBy('id');

        if ($limit !== null && $limit !== '') {
            $query->limit((int) $limit);
        }

        $counts = ['candidates' => 0, 'eligible' => 0, 'sent' => 0, 'failed' => 0];
        $skipped = [];

        foreach ($query->get() as $offer) {
            $counts['candidates']++;

            $reason = $rules->accountsRefusal((int) $offer->user1, (int) $offer->user2);

            if ($reason === null) {
                $u1 = User::find($offer->user1);
                $u2 = User::find($offer->user2);

                if ($rules->emailOff($u1) || $rules->emailOff($u2)) {
                    $reason = 'an account has email off or bouncing';
                } elseif (!$u1->email_preferred || !$u2->email_preferred) {
                    $reason = 'an account has no email address';
                }
            }

            if ($reason !== null) {
                $skipped[$reason] = ($skipped[$reason] ?? 0) + 1;

                continue;
            }

            $counts['eligible']++;

            if (!$apply) {
                continue;
            }

            // Claim the offer first, so a second run, or a second process, cannot send it again.
            $claimed = DB::table('merges')
                ->where('id', $offer->id)
                ->whereNull('reminded')
                ->update(['reminded' => now()]);

            if ($claimed !== 1) {
                continue;
            }

            try {
                $mailer->send((int) $offer->id, (string) $offer->uid, $u1, $u2, $spooler, self::INTRO);
                $counts['sent']++;
            } catch (\Throwable $e) {
                DB::table('merges')->where('id', $offer->id)->update(['reminded' => null]);
                $this->error("Offer {$offer->id}: could not send: " . $e->getMessage());
                $counts['failed']++;
            }
        }

        $this->info("Offers since {$since} not yet answered or reminded: {$counts['candidates']}");
        $this->info("Eligible for a reminder: {$counts['eligible']}");

        foreach ($skipped as $reason => $n) {
            $this->info("Left alone, {$reason}: {$n}");
        }

        if ($apply) {
            $this->info("Reminders sent: {$counts['sent']}, failed: {$counts['failed']}");
        }

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
