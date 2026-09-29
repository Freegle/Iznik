<?php

namespace App\Console\Commands\Lockdown;

use App\Services\EmailSpoolerService;
use App\Services\Lockdown\LockdownFilterSpoolService;
use App\Services\Lockdown\LockdownHoldsService;
use App\Services\Lockdown\LockdownService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Runs every minute while a lockdown exists (plan 2026-09-27-lockdown-switch.md, section
 * 11.11): announces any `lockdowns` row not yet announced (mail to geeks@ and a Sentry
 * message, sent directly rather than through shouldSkip()/spool(), since this must reach
 * geeks@ even while email itself is held), writes this incident's hold rows, closes holds
 * whose item has gone, unhides held ChitChat posts once ChitChat is lifted, and records how
 * many emails are waiting to send.
 *
 * Chat and posts release through their own per-minute crons (chats:process-incoming and
 * messages:contentcheck) once their areas lift. ChitChat has no such cron of its own, which
 * is why its release lives here.
 *
 * While email is held, this also runs lockdown:filter-spool's check (section 11.8) on every
 * pass, so the send queue is kept clear of mail about removed content throughout, not only
 * at the moment email is lifted.
 */
class LockdownTickCommand extends Command
{
    protected $signature = 'lockdown:tick';

    protected $description = 'Announce lockdown changes, record what is held, release held ChitChat';

    public function handle(
        LockdownService $lockdown,
        LockdownHoldsService $holds,
        LockdownFilterSpoolService $filterSpool,
        EmailSpoolerService $spooler
    ): int {
        $announced = $this->announceUnannounced($lockdown);

        if ($lockdown->current() === null) {
            // Nothing has ever been pressed.
            return self::SUCCESS;
        }

        // Runs every minute while a lockdown exists (section 11.6 point 3), so this marks
        // the loop as still running once there is anything for it to do.
        $lockdown->ack('tick');

        $created = $holds->createHolds();
        $gone = $holds->closeGoneHolds();
        $chitchat = $holds->releaseChitChatHolds();

        $filtered = null;
        if ($lockdown->held('email')) {
            $filtered = $filterSpool->filter();
        }

        // So the Lockdown tab can show the queue emptying once email is lifted.
        $queued = $spooler->queuedCount();
        $lockdown->record('queue:email', $queued);

        $this->info(sprintf(
            'Announced %d. Holds created: chat %d, post %d. Gone %d. ChitChat released %d. Emails queued %d.%s',
            $announced,
            $created['chat'] ?? 0,
            $created['post'] ?? 0,
            $gone,
            $chitchat,
            $queued,
            $filtered !== null
                ? sprintf(' Filtered spool: checked %d, removed %d.', $filtered['checked'], $filtered['removed'])
                : ''
        ));

        return self::SUCCESS;
    }

    /**
     * Announce every row not yet announced, oldest first, then mark it. lockdowns is
     * append-only, so a press, an area lifted and a close are each their own row and each
     * gets its own mail: Support sees every change land, not just the first.
     */
    private function announceUnannounced(LockdownService $lockdown): int
    {
        $rows = DB::table('lockdowns')->whereNull('announcedat')->orderBy('id')->get();

        foreach ($rows as $row) {
            $this->announceRow($lockdown, $row);
            DB::table('lockdowns')->where('id', $row->id)->update(['announcedat' => now()]);
        }

        return $rows->count();
    }

    private function announceRow(LockdownService $lockdown, object $row): void
    {
        $active = (int) $row->active === 1;
        $surfaces = $lockdown->surfacesOf($row);
        $held = array_filter(LockdownService::SURFACES, fn (string $s) => $surfaces[$s] ?? false);

        $subject = $active
            ? "Lockdown incident {$row->incidentid}: state changed"
            : "Lockdown incident {$row->incidentid}: closed";

        $body = $active
            ? sprintf(
                "Lockdown row %d (incident %d) is now active.\nHeld: %s\nReason: %s\nMember notice: %s\n",
                $row->id,
                $row->incidentid,
                empty($held) ? '(nothing)' : implode(', ', $held),
                $row->reason ?? '(none)',
                $row->notice ?? '(none)'
            )
            : sprintf(
                "Lockdown incident %d closed at %s.\nNote: %s\n",
                $row->incidentid,
                $row->endedat ?? (string) now(),
                $row->endnote ?? '(none)'
            );

        $to = config('freegle.geeks_addr', 'geeks@ilovefreegle.org');

        try {
            Mail::raw($body, function ($message) use ($to, $subject) {
                $message->to($to)->subject($subject);
            });
        } catch (\Throwable $e) {
            // The row must still be recorded as announced even if this mail attempt fails;
            // the log and Sentry below are the fallback record.
            Log::error('Lockdown: announce mail failed', ['row' => $row->id, 'error' => $e->getMessage()]);
        }

        if (app()->bound('sentry')) {
            app('sentry')->captureMessage($subject."\n".$body, \Sentry\Severity::error());
        }
    }
}
