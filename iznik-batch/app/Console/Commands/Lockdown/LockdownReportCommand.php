<?php

namespace App\Console\Commands\Lockdown;

use App\Services\Lockdown\LockdownService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Hourly stats mail to geeks@ while a lockdown is active, and a final summary once after
 * close via --closing (plan 2026-09-27-lockdown-switch.md, section 11.4: "every hour it is
 * on delays thousands of genuine messages"). Sent direct with Mail::raw, the same as
 * lockdown:triage's announce, not through spool()/shouldSkip() - this must reach geeks@
 * regardless of whether email itself is held.
 */
class LockdownReportCommand extends Command
{
    protected $signature = 'lockdown:report {--closing : Send the final summary for the incident that just closed}';

    protected $description = 'Mail geeks@ a stats report for the active (or just-closed) lockdown incident';

    public function handle(LockdownService $lockdown): int
    {
        $row = $lockdown->current();
        $closing = (bool) $this->option('closing');

        if ($row === null) {
            $this->info('No lockdown has ever been pressed - nothing to report.');

            return self::SUCCESS;
        }

        $active = (int) $row->active === 1;
        if (!$active && !$closing) {
            // Hourly-while-active: nothing to send once it is closed unless this is
            // explicitly the closing summary.
            return self::SUCCESS;
        }

        $incidentId = (int) $row->incidentid;
        $counters = DB::table('lockdown_counters')
            ->where('lockdownid', $incidentId)
            ->orderByDesc('count')
            ->get(['kind', 'count']);

        // Grouped in PHP rather than a raw COUNT(*) select: an incident's hold count is
        // bounded (paced releases, one row per held item) so pulling three columns is
        // cheap, and this keeps the query builder free of a raw aggregate.
        $holdCounts = DB::table('lockdown_holds')
            ->where('lockdownid', $incidentId)
            ->get(['kind', 'risk', 'outcome'])
            ->groupBy(fn ($h) => $h->kind.'|'.($h->risk ?? '').'|'.($h->outcome ?? ''))
            ->map(function ($group) {
                $first = $group->first();

                return (object) [
                    'kind' => $first->kind,
                    'risk' => $first->risk,
                    'outcome' => $first->outcome,
                    'n' => $group->count(),
                ];
            })
            ->values();

        // Counters share one table for both "refused" (email:<type>, push) and "leaked"
        // kinds (section 11.6 point 4) - split them here so each gets its own section
        // below rather than mixing what the hold blocked with what got through anyway.
        $leaked = $counters->filter(fn ($c) => str_starts_with($c->kind, 'leaked:'));
        $refused = $counters->reject(fn ($c) => str_starts_with($c->kind, 'leaked:'));

        // lockdown_acks has one row per loop system-wide (not per incident), holding the
        // newest lockdowns row id that loop has acted on - so "caught up" means that id
        // matches this row, the current state, not merely that the loop has ever run.
        $acks = DB::table('lockdown_acks')->get(['loop', 'lockdownrowid', 'seenat'])->keyBy('loop');
        $currentRowId = (int) $row->id;
        $caughtUpCount = 0;
        $ackLines = [];
        foreach (LockdownService::ACK_LOOPS as $loopName) {
            $ack = $acks->get($loopName);
            if ($ack === null) {
                $ackLines[] = "  {$loopName}: never seen";

                continue;
            }

            $caughtUp = (int) $ack->lockdownrowid === $currentRowId;
            if ($caughtUp) {
                $caughtUpCount++;
            }
            $ackLines[] = sprintf(
                '  %s: %s (last seen %s)',
                $loopName,
                $caughtUp ? 'caught up' : 'BEHIND - still acting on an earlier state',
                $ack->seenat
            );
        }

        $surfaces = $lockdown->surfacesOf($row);
        $held = array_filter(LockdownService::SURFACES, fn (string $s) => $surfaces[$s] ?? false);

        $body = sprintf(
            "Lockdown incident %d %s.\nStarted: %s\n%s",
            $incidentId,
            $closing ? 'closed' : 'still active',
            $row->startedat ?? '(unknown)',
            $closing ? 'Ended: '.($row->endedat ?? '(unknown)')."\nClose note: ".($row->endnote ?? '(none)')."\n" : ''
        );

        $body .= "\nSurfaces held now: ".(empty($held) ? '(none)' : implode(', ', $held))."\n";

        $body .= "\nCounted (refused or not sent):\n";
        $body .= $refused->isEmpty()
            ? "  (nothing counted)\n"
            : $refused->map(fn ($c) => "  {$c->kind}: {$c->count}")->implode("\n")."\n";

        $body .= "\nLeaked since press (let through deliberately, or a known bypass):\n";
        $body .= $leaked->isEmpty()
            ? "  (nothing leaked)\n"
            : $leaked->map(fn ($c) => "  {$c->kind}: {$c->count}")->implode("\n")."\n";

        $body .= "\nLoops (caught up to the current state?):\n" . implode("\n", $ackLines) . "\n";

        $body .= "\nHolds by kind/risk/outcome:\n";
        $body .= $holdCounts->isEmpty()
            ? "  (no holds)\n"
            : $holdCounts->map(fn ($h) => sprintf(
                '  %s / risk=%s / outcome=%s: %d',
                $h->kind,
                $h->risk ?? '(unclassified)',
                $h->outcome ?? '(none)',
                $h->n
            ))->implode("\n")."\n";

        $subject = $closing
            ? "Lockdown incident {$incidentId}: closing report"
            : "Lockdown incident {$incidentId}: hourly report";

        $to = config('freegle.geeks_addr', 'geeks@ilovefreegle.org');
        Mail::raw($body, function ($message) use ($to, $subject) {
            $message->to($to)->subject($subject);
        });

        $this->info("Report emailed for incident {$incidentId}.");
        $this->info(sprintf(
            'Acks: %d/%d loops caught up. Leaked since press: %d.',
            $caughtUpCount,
            count(LockdownService::ACK_LOOPS),
            (int) $leaked->sum('count')
        ));

        return self::SUCCESS;
    }
}
