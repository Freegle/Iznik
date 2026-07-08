<?php

namespace App\Console\Commands\Concierge;

use App\Services\Gmail\GmailService;
use Illuminate\Console\Command;
use Symfony\Component\Mime\Address;

/**
 * Sends ONLY the concierge queue entries a human has marked "approved": true.
 *
 * Dup-safe by construction:
 *  - dry-run unless --live is passed;
 *  - a persistent sent-ledger keyed by a stable hash of (offer, recipient,
 *    action, body) — an entry is skipped if its key is already in the ledger,
 *    so re-running can never send the same reply twice.
 */
class ConciergeSendApprovedCommand extends Command
{
    protected $signature = 'concierge:send-approved
        {--queue=/tmp/concierge-queue.json : the queue written by concierge:run}
        {--ledger=/tmp/concierge-sent-ledger.json : persistent sent keys (dup guard)}
        {--live : actually send (otherwise dry-run)}';

    protected $description = 'Send approved concierge replies from the queue — dup-guarded, dry-run by default.';

    public function handle(GmailService $gmail): int
    {
        $queuePath = $this->option('queue');
        if (!is_file($queuePath)) {
            $this->error("Queue not found: $queuePath");

            return self::FAILURE;
        }
        $data = json_decode((string) file_get_contents($queuePath), true);
        $offer = $data['offer'] ?? 0;
        $queue = $data['queue'] ?? [];

        $ledgerPath = $this->option('ledger');
        $ledger = is_file($ledgerPath) ? (json_decode((string) file_get_contents($ledgerPath), true) ?: []) : [];
        $ledgerSet = array_flip($ledger);

        $live = (bool) $this->option('live');
        $sent = 0; $skipDup = 0; $skipUnapproved = 0;

        foreach ($queue as &$q) {
            if (($q['status'] ?? '') !== 'pending_approval' || empty($q['approved']) || empty($q['draft']) || empty($q['to'])) {
                if (($q['status'] ?? '') === 'pending_approval' && empty($q['approved'])) $skipUnapproved++;
                continue;
            }
            $key = hash('sha256', $offer.'|'.strtolower($q['to']).'|'.($q['action']['kind'] ?? '').'|'.$q['draft']['body']);
            if (isset($ledgerSet[$key])) { $skipDup++; continue; }   // already sent — never twice

            if ($live) {
                try {
                    $to = new Address($q['to'], $q['org'] ?? '');
                    $html = nl2br(htmlspecialchars($q['draft']['body']));
                    $gmail->send($to, $q['draft']['subject'], $html, $q['draft']['body']);
                } catch (\Throwable $e) {
                    $this->warn("send failed to {$q['to']}: ".$e->getMessage());
                    continue;
                }
            }
            $ledger[] = $key; $ledgerSet[$key] = 1;
            $q['status'] = $live ? 'sent' : 'would_send'; $q['sent_key'] = $key;
            $sent++;
            $this->line(($live ? 'SENT   ' : 'DRYRUN ')."{$q['to']}  ({$q['action']['kind']})");
        }
        unset($q);

        if ($live) {
            file_put_contents($ledgerPath, json_encode($ledger, JSON_PRETTY_PRINT));
            file_put_contents($queuePath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        $this->info(($live ? 'SENT' : 'DRY-RUN')." — {$sent} message(s); skipped {$skipDup} already-sent, {$skipUnapproved} unapproved.");
        if (!$live) $this->line('Add --live to actually send. Dup-guard active either way.');

        return self::SUCCESS;
    }
}
