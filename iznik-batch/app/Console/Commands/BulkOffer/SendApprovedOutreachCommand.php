<?php

namespace App\Console\Commands\BulkOffer;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Emails the outreach replies the offerer has APPROVED in the management page.
 *
 * An approved email proposal is a helper_proposals row with type=message,
 * status=sent (set by the Go ResolveProposal when the human clicks Send),
 * payload.channel=email, and resolved_text = the final (possibly-edited) reply.
 * We deliver it by delegating to bulkoffer:reply-outreach (same threading + send
 * path the mailbox brain used). helper_outreach_sends (UNIQUE proposalid) is the
 * dup guard: an approved reply is emailed exactly once, even on re-runs.
 * Dry-run unless --live.
 */
class SendApprovedOutreachCommand extends Command
{
    protected $signature = 'bulkoffer:send-approved-outreach {--live : actually send (else dry-run .eml)}';

    protected $description = 'Send outreach email replies approved in the management page (dup-guarded, dry-run by default).';

    public function handle(): int
    {
        $live = (bool) $this->option('live');

        $rows = DB::table('helper_proposals as p')
            ->leftJoin('helper_outreach_sends as s', 's.proposalid', '=', 'p.id')
            ->where('p.type', 'message')
            ->where('p.status', 'sent')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(p.payload, '$.channel')) = 'email'")
            ->whereNull('s.id')  // not yet emailed
            ->select('p.id', 'p.resolved_text', 'p.proposed_text', 'p.payload')
            ->get();

        $sent = 0;
        $failed = 0;
        foreach ($rows as $r) {
            $payload = json_decode((string) $r->payload, true) ?: [];
            $thread = (string) ($payload['gmail_thread_id'] ?? '');
            $body = trim((string) ($r->resolved_text ?? '')) ?: trim((string) ($r->proposed_text ?? ''));
            if ($thread === '' || $body === '') {
                $this->warn("proposal {$r->id}: missing thread or body, skipping.");

                continue;
            }

            // Claim first (UNIQUE proposalid) so a crash can't double-send; roll back on failure.
            $claimed = DB::table('helper_outreach_sends')->insertOrIgnore([
                'proposalid' => $r->id,
                'gmail_thread_id' => $thread,
                'created_at' => now(),
            ]);
            if (! $claimed) {
                continue; // already emailed
            }

            $args = ['--thread' => $thread, '--body' => $body];
            if ($live) {
                $args['--live'] = true;
            }
            $code = Artisan::call('bulkoffer:reply-outreach', $args);
            if ($code === self::SUCCESS) {
                $sent++;
                $this->line(($live ? 'SENT   ' : 'DRYRUN ')."proposal {$r->id} in thread {$thread}");
            } else {
                $failed++;
                DB::table('helper_outreach_sends')->where('proposalid', $r->id)->delete(); // allow retry
                $this->warn("proposal {$r->id}: send failed (".trim(Artisan::output()).')');
            }
        }

        $this->info(($live ? 'SENT' : 'DRY-RUN')." — {$sent} approved repl".($sent === 1 ? 'y' : 'ies').($failed ? ", {$failed} failed" : '').'.');
        if (! $live && $sent) {
            $this->line('Add --live to actually send.');
        }

        return self::SUCCESS;
    }
}
