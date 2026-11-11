<?php

namespace App\Console\Commands\Partnerships;

use App\Mail\Partnerships\SponsorshipExpiringMail;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Chases council sponsorships that are coming up for renewal, and those that have run out
 * without being renewed.
 *
 * A council's budget round takes months, so the team needs telling well before the
 * sponsorship lapses - three months by default. That is the point to ask about next year.
 * With --ended, it chases deals that finished in the last --days days with nothing agreed to
 * follow them: by then the council should have renewed and paid. Each partnership is chased
 * once per window (recorded in partnerships_reminders), so running this daily does not nag,
 * and a deal already followed by a later one with the same council is left alone.
 *
 *   php artisan partnerships:reminders
 *   php artisan partnerships:reminders --days=30 --type=1month
 *   php artisan partnerships:reminders --ended --days=30 --type=ended
 */
#[AsCommand(name: 'partnerships:reminders')]
class SponsorshipRemindersCommand extends Command
{
    protected $signature = 'partnerships:reminders
                            {--days=92 : How many days ahead of expiry to warn (with --ended, how far back to look)}
                            {--type=3months : Reminder window name, recorded so each deal is chased once}
                            {--ended : Chase deals that have already ended instead of those about to}
                            {--dry-run : Report what would be sent without sending it}';

    protected $description = 'Email the Partnerships team about sponsorships nearing or past their end date';

    private const COMMITTED = ['Confirmed', 'Paid', 'Overdue'];

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $type = (string) $this->option('type');
        $ended = (bool) $this->option('ended');
        $dryRun = (bool) $this->option('dry-run');

        $today = Carbon::today();

        if ($ended) {
            $windowStart = $today->copy()->subDays($days);
            $windowEnd = $today->copy()->subDay();
        } else {
            $windowStart = $today;
            $windowEnd = $today->copy()->addDays($days);
        }

        // Only committed deals are worth chasing: a quote or an agreement in principle is
        // still being negotiated. Hidden deals count too - a council that asked not to be
        // named still needs asking about next year.
        $due = DB::table('partnerships')
            ->join('authorities', 'authorities.id', '=', 'partnerships.authorityid')
            ->leftJoin('partnerships_reminders', function ($join) use ($type) {
                $join->on('partnerships_reminders.partnershipid', '=', 'partnerships.id')
                    ->where('partnerships_reminders.type', '=', $type);
            })
            ->whereNull('partnerships_reminders.id')
            ->whereIn('partnerships.status', self::COMMITTED)
            ->whereDate('partnerships.enddate', '>=', $windowStart->toDateString())
            ->whereDate('partnerships.enddate', '<=', $windowEnd->toDateString())
            // Already renewed: a later deal with the same council exists.
            ->whereNotExists(function ($query) {
                $query->from('partnerships AS later')
                    ->whereColumn('later.authorityid', 'partnerships.authorityid')
                    ->whereColumn('later.enddate', '>', 'partnerships.enddate');
            })
            ->select([
                'partnerships.id',
                'partnerships.name',
                'partnerships.enddate',
                'partnerships.amount',
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
                ->where('source', '!=', 'Removed')
                ->count();

            $this->info(sprintf(
                '%s (%s) %s %s - %d days, %d %s covered.',
                $partnership->name,
                $partnership->authorityname,
                $ended ? 'ended' : 'ends',
                $endDate->format('j M Y'),
                abs($daysLeft),
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
                contacts: $this->contacts((int) $partnership->id),
                modToolsUrl: $modSite . '/partnerships?id=' . $partnership->id,
                ended: $ended,
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
     * Everyone at the council we deal with, with their role in words.
     *
     * @return array<int, array{name: ?string, email: ?string, role: string}>
     */
    private function contacts(int $partnershipId): array
    {
        $roles = ['Waste' => 'waste team', 'Finance' => 'finance', 'Other' => 'other'];

        return DB::table('partnerships_contacts')
            ->where('partnershipid', $partnershipId)
            ->orderBy('id')
            ->get(['name', 'email', 'role'])
            ->map(fn ($c) => [
                'name' => $c->name,
                'email' => $c->email,
                'role' => $roles[$c->role] ?? $c->role,
            ])
            ->all();
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
