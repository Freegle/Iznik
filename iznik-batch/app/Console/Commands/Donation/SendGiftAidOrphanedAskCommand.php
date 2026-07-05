<?php

namespace App\Console\Commands\Donation;

use App\Mail\Donation\GiftAidOrphanedAsk;
use App\Models\User;
use App\Services\EmailSpoolerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ONE-OFF send. Emails donors whose past donations were orphaned when member email
 * addresses changed (so were never acknowledged), and for whom we hold NO Gift Aid
 * consent, inviting them to give consent so we can reclaim the 25%.
 *
 * Recipients = the LIVE account that currently owns the donation's PayPal/Stripe payer
 * email, where that donation is linked to a DIFFERENT (leftover) account, is gift-aid
 * eligible (PayPal/Stripe, >0, not already claimed, within HMRC's 4-year window), and
 * the correct account holds no Gift Aid declaration at all. One email per person.
 *
 * Idempotent: skips anyone who already has a GiftAidOrphanedAsk tracking row, so a
 * re-run never double-sends. Use --dry-run first to review the recipient count.
 */
class SendGiftAidOrphanedAskCommand extends Command
{
    protected $signature = 'mail:giftaid-orphaned-ask
                            {--dry-run : List recipients without sending}
                            {--limit=0 : Cap number of recipients (0 = all)}';

    protected $description = 'One-off: ask orphaned-donation donors with no Gift Aid consent to give consent.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');

        $rows = DB::select("
            SELECT DISTINCT e.userid AS correct_uid
            FROM users_donations d
            JOIN users_emails e ON e.email = d.Payer AND e.userid <> d.userid
            JOIN users ub ON ub.id = e.userid AND ub.deleted IS NULL
            WHERE d.source IN ('DonateWithPayPal','Stripe')
              AND d.GrossAmount > 0
              AND d.giftaidclaimed IS NULL
              AND d.timestamp >= (NOW() - INTERVAL 4 YEAR)
              AND NOT EXISTS (
                    SELECT 1 FROM users_emails e2
                    WHERE e2.email = d.Payer AND e2.userid = d.userid)
              AND NOT EXISTS (
                    SELECT 1 FROM giftaid g
                    WHERE g.userid = e.userid AND g.deleted IS NULL)
        ");

        $ids = array_values(array_unique(array_map(fn ($r) => (int) $r->correct_uid, $rows)));
        $this->info('Eligible recipients (live account, no Gift Aid consent): '.count($ids));

        if ($limit > 0) {
            $ids = array_slice($ids, 0, $limit);
            $this->warn('Limited to '.count($ids).' recipient(s) by --limit.');
        }

        $spooler = app(EmailSpoolerService::class);
        $spooled = 0;
        $skippedNoEmail = 0;
        $skippedAlready = 0;

        foreach ($ids as $uid) {
            $user = User::find($uid);

            if (! $user || ! $user->email_preferred) {
                $skippedNoEmail++;
                $this->warn("skip {$uid}: no external email");

                continue;
            }

            $already = DB::table('email_tracking')
                ->where('userid', $uid)
                ->where('email_type', 'GiftAidOrphanedAsk')
                ->exists();
            if ($already) {
                $skippedAlready++;

                continue;
            }

            if ($dryRun) {
                $this->line("would send: {$uid}  <{$user->email_preferred}>  {$user->displayname}");

                continue;
            }

            $mailable = new GiftAidOrphanedAsk($user);
            $mailable->render(); // triggers build() so ->to() is populated
            $to = collect($mailable->to)->pluck('address')->filter()->values()->all();
            if (empty($to)) {
                $skippedNoEmail++;

                continue;
            }
            $spooler->spool($mailable, $to, 'GiftAidOrphanedAsk');
            $spooled++;
        }

        $this->newLine();
        $this->info(($dryRun ? 'DRY RUN — nothing sent. ' : '')
            ."Spooled: {$spooled}  |  skipped (no email): {$skippedNoEmail}  |  skipped (already sent): {$skippedAlready}");

        if (! $dryRun) {
            $this->comment('Emails are spooled; the mail:spool:process daemon will deliver them.');
        }

        return Command::SUCCESS;
    }
}
