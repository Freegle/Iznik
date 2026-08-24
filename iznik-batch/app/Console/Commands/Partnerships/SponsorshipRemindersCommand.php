<?php

namespace App\Console\Commands\Partnerships;

use App\Mail\Partnerships\SponsorshipExpiringMail;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Chases council sponsorships that are coming up for renewal.
 *
 * A council's budget round takes months, so the team needs telling well before the
 * sponsorship lapses - three months by default. Each partnership is chased once per window
 * (recorded in partnerships_reminders), so running this daily does not nag.
 *
 *   php artisan partnerships:reminders
 *   php artisan partnerships:reminders --days=30 --type=1month
 */
class SponsorshipRemindersCommand extends Command
{
    protected $signature = 'partnerships:reminders
                            {--days=92 : How many days ahead of expiry to warn}
                            {--type=3months : Reminder window name, recorded so each deal is chased once}
                            {--dry-run : Report what would be sent without sending it}';

    protected $description = 'Email the Partnerships team about sponsorships nearing their end date';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $type = (string) $this->option('type');
        $dryRun = (bool) $this->option('dry-run');

        $today = Carbon::today();
        $cutoff = $today->copy()->addDays($days);

        // Only agreed, visible deals are worth chasing: an unagreed one is still being
        // negotiated, and a hidden one has already been retired by hand.
        $due = DB::table('partnerships')
            ->join('authorities', 'authorities.id', '=', 'partnerships.authorityid')
            ->leftJoin('partnerships_reminders', function ($join) use ($type) {
                $join->on('partnerships_reminders.partnershipid', '=', 'partnerships.id')
                    ->where('partnerships_reminders.type', '=', $type);
            })
            ->whereNull('partnerships_reminders.id')
            ->where('partnerships.agreed', 1)
            ->where('partnerships.visible', 1)
            ->whereDate('partnerships.enddate', '>=', $today->toDateString())
            ->whereDate('partnerships.enddate', '<=', $cutoff->toDateString())
            ->select([
                'partnerships.id',
                'partnerships.name',
                'partnerships.enddate',
                'partnerships.amount',
                'partnerships.contactname',
                'partnerships.contactemail',
                'authorities.name as authorityname',
            ])
            ->orderBy('partnerships.enddate')
            ->get();

        if ($due->isEmpty()) {
            $this->info('No sponsorships are due a reminder.');

            return Command::SUCCESS;
        }

        $to = $this->teamEmail();
        $from = config('freegle.mail.noreply_addr', 'noreply@ilovefreegle.org');
        $modSite = rtrim((string) config('freegle.sites.mod', 'https://modtools.org'), '/');

        foreach ($due as $partnership) {
            $endDate = Carbon::parse($partnership->enddate);
            $daysLeft = (int) $today->diffInDays($endDate, false);

            $groupCount = (int) DB::table('partnerships_groups')
                ->where('partnershipid', $partnership->id)
                ->count();

            $this->info(sprintf(
                '%s (%s) ends %s - %d days left, %d %s covered.',
                $partnership->name,
                $partnership->authorityname,
                $endDate->format('j M Y'),
                $daysLeft,
                $groupCount,
                $groupCount === 1 ? 'community' : 'communities'
            ));

            if ($dryRun) {
                continue;
            }

            Mail::send(new SponsorshipExpiringMail(
                recipientEmail: $to,
                fromEmail: $from,
                partnershipName: $partnership->name,
                authorityName: $partnership->authorityname,
                endDate: $endDate->format('j M Y'),
                daysLeft: $daysLeft,
                amount: (float) $partnership->amount,
                groupCount: $groupCount,
                contactName: $partnership->contactname,
                contactEmail: $partnership->contactemail,
                modToolsUrl: $modSite . '/partnerships?id=' . $partnership->id,
            ));

            // Written after the send, so a send that blows up is retried on the next run
            // rather than being silently swallowed.
            DB::table('partnerships_reminders')->insertOrIgnore([
                'partnershipid' => $partnership->id,
                'type' => $type,
                'sent' => now(),
            ]);
        }

        $this->info(sprintf('%s %d reminder%s.',
            $dryRun ? 'Would send' : 'Sent',
            $due->count(),
            $due->count() === 1 ? '' : 's'
        ));

        return Command::SUCCESS;
    }

    /**
     * Where the reminders go: the Partnerships team's own address, so changing it in
     * ModTools changes where these land.
     */
    private function teamEmail(): string
    {
        $email = DB::table('teams')->where('name', 'Partnerships')->value('email');

        return $email ?: 'partnerships@ilovefreegle.org';
    }
}
