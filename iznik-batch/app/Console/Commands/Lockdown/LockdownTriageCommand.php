<?php

namespace App\Console\Commands\Lockdown;

use App\Services\Lockdown\LockdownFilterSpoolService;
use App\Services\Lockdown\LockdownService;
use App\Services\Lockdown\LockdownTriageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Runs every minute while a lockdown exists (plan 2026-09-27-lockdown-switch.md, section
 * 11.4): announces any `lockdowns` row not yet announced (mail to geeks@ and a Sentry
 * message - direct, bypassing shouldSkip()/spool() entirely, since this must reach geeks@
 * even while email itself is held), then runs the triage pipeline: create this incident's
 * holds, classify anything unclassified, and act on ChitChat holds (spam_marked deleted,
 * low released once chitchat is lifted).
 *
 * Chat and post holds release through their own existing per-minute crons
 * (mail:chat:user2user et al., messages:contentcheck) once their surfaces lift - ChitChat
 * has no such cron of its own, which is why its release lives here instead.
 *
 * While email is held, this also runs lockdown:filter-spool's check (section 11.8) on
 * every pass: the send queue is filtered against current state continuously, not only
 * once at the moment email is finally lifted, so a backlog built up over an hours-long
 * hold does not sit unchecked until the last minute.
 */
class LockdownTriageCommand extends Command
{
    protected $signature = 'lockdown:triage';

    protected $description = 'Announce lockdown changes, create/classify holds, release ChitChat holds';

    public function handle(
        LockdownService $lockdown,
        LockdownTriageService $triage,
        LockdownFilterSpoolService $filterSpool
    ): int {
        $announced = $this->announceUnannounced($lockdown);

        if ($lockdown->current() === null) {
            // Nothing has ever been pressed - nothing to triage.
            return self::SUCCESS;
        }

        // Runs every minute while a lockdown exists (section 11.6 point 3), so this marks
        // the triage loop as still running once there is anything for it to do.
        $lockdown->ack('triage');

        $holds = $triage->createHolds();
        $classified = $triage->classifyPending();
        $chitchat = $triage->releaseChitChatHolds();

        $filtered = null;
        if ($lockdown->held('email')) {
            $filtered = $filterSpool->filter();
        }

        $this->info(sprintf(
            'Announced %d. Holds created: chat %d, post %d. Classified %d. ChitChat: released %d, rejected %d.%s',
            $announced,
            $holds['chat'] ?? 0,
            $holds['post'] ?? 0,
            $classified['classified'] ?? 0,
            $chitchat['released'] ?? 0,
            $chitchat['rejected'] ?? 0,
            $filtered !== null
                ? sprintf(' Filtered spool: checked %d, removed %d.', $filtered['checked'], $filtered['removed'])
                : ''
        ));

        return self::SUCCESS;
    }

    /**
     * Announce every row not yet announced, oldest first, then mark it. lockdowns is
     * append-only, so a press, a surface lift and a close are each their own row and each
     * gets its own mail - that is deliberate: Support sees every change land, not just the
     * first.
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
                "Lockdown row %d (incident %d) is now active.\nSurfaces held: %s\nChat mode: %s\nReason: %s\nNotice: %s\n",
                $row->id,
                $row->incidentid,
                empty($held) ? '(none)' : implode(', ', $held),
                $surfaces['chat_mode'] ?? LockdownService::CHAT_HARD,
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
            // The lockdown itself must still be recorded as announced even if this
            // particular mail attempt fails - Log/Sentry below is the fallback record.
            Log::error('Lockdown: announce mail failed', ['row' => $row->id, 'error' => $e->getMessage()]);
        }

        if (app()->bound('sentry')) {
            app('sentry')->captureMessage($subject."\n".$body, \Sentry\Severity::error());
        }
    }
}
