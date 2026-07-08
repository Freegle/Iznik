<?php

namespace App\Console\Commands\Concierge;

use App\Services\Concierge\ConciergeEngine;
use App\Services\Concierge\LlmExtractor;
use App\Services\Concierge\TemplateDrafter;
use App\Services\Gmail\GmailService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One pass of the concierge reply-handling FSM for a bulk-offer clearance:
 *   scan mailbox → classify → sync live availability → reconcile → draft →
 *   write proposals to an APPROVAL QUEUE.
 *
 * It NEVER sends. A human reviews the queue; `concierge:send-approved` (guarded)
 * sends only the entries a human marked approved. The engine's decision logic is
 * covered by the replay test.
 */
class ConciergeRunCommand extends Command
{
    protected $signature = 'concierge:run
        {offer : messages.id of the bulk offer}
        {--state= : path to the concierge state JSON (repliers + commitment ledger)}
        {--queue=/tmp/concierge-queue.json : where to write proposed actions for approval}
        {--no-mail : skip the mailbox scan (reconcile only)}';

    protected $description = 'Run one concierge FSM pass (scan, sync, reconcile, queue) — never sends.';

    public function handle(ConciergeEngine $engine, GmailService $gmail): int
    {
        $offer = (int) $this->argument('offer');
        $statePath = $this->option('state');
        if (!$statePath || !is_file($statePath)) {
            $this->error('Provide --state=<path to concierge state JSON>.');

            return self::FAILURE;
        }
        $state = json_decode((string) file_get_contents($statePath), true);
        $repliers = $state['repliers'] ?? [];
        $commitments = $state['commitments'] ?? [];

        // 2) Availability sync — trust the live offer row, not our own notes
        //    (done before the scan so the extractor has the item catalogue)
        $items = [];
        foreach (DB::table('messages_bulk_items')->where('msgid', $offer)->orderBy('position')->get() as $row) {
            $num = (int) $row->position + 1;
            $items[$num] = ['num' => $num, 'name' => $row->name, 'qty' => (int) $row->quantity,
                'available' => (int) ($row->available ?? 1) === 1];
        }

        // 1) Mailbox scan → classify + extract structured interest (surfaced for the human)
        $extractor = new LlmExtractor();   // falls back to rule-based if no LLM configured
        $inbound = [];
        if (!$this->option('no-mail')) {
            try {
                foreach ($gmail->listThreads('in:inbox is:unread newer_than:21d') as $stub) {
                    $th = $gmail->getThread($stub['id'] ?? $stub['threadId'] ?? '');
                    $msgs = $th['messages'] ?? [];
                    if (!$msgs) continue;
                    $last = end($msgs);
                    $h = [];
                    foreach (($last['payload']['headers'] ?? []) as $x) $h[$x['name']] = $x['value'];
                    $subject = $h['Subject'] ?? '';
                    $class = $engine->classifyInbound($h, $subject);
                    $entry = ['from' => $h['From'] ?? '', 'class' => $class, 'subject' => $subject];
                    if ($class === ConciergeEngine::IN_REPLY && $items) {
                        $entry['extracted'] = $extractor->extract($this->bodyText($last['payload'] ?? []), $subject, $items, ['defaultMonth' => date('Y-m')]);
                    }
                    $inbound[] = $entry;
                }
            } catch (\Throwable $e) {
                $this->warn('Mailbox scan skipped: '.$e->getMessage());
            }
        }
        $access = DB::table('messages_bulk_access')->where('msgid', $offer)->value('accessinstructions');

        // 3) Reconcile
        $confident = !empty($items);
        $actions = $engine->reconcile($items, $repliers, $commitments, ['availabilityConfident' => $confident]);

        // 4) Draft + 5) queue for approval (NO SEND)
        $drafter = new TemplateDrafter();
        $byId = [];
        foreach ($repliers as $r) $byId[$r['id']] = $r;
        $ctx = ['items' => $items, 'collection' => $access ?: '(collection details TBC)', 'subject' => 'the office furniture'];
        $queue = [];
        foreach ($actions as $a) {
            $r = $byId[$a['replier']] ?? ['name' => $a['replier']];
            $draft = $drafter->draft($a, $r, $ctx);
            $queue[] = [
                'action' => $a,
                'to' => $r['email'] ?? null,
                'org' => $r['org'] ?? null,
                'draft' => $draft,          // null => internal state (HOLD/WAITLIST), no message
                'status' => $draft ? 'pending_approval' : 'internal',
                'approved' => false,
            ];
        }

        $queuePath = $this->option('queue');
        file_put_contents($queuePath, json_encode([
            'offer' => $offer, 'availability_confident' => $confident,
            'inbound_seen' => $inbound, 'queue' => $queue,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $toSend = count(array_filter($queue, fn ($q) => $q['status'] === 'pending_approval'));
        $this->info("Concierge pass complete for offer $offer.");
        $this->line("  inbound classified: ".count($inbound));
        $this->line("  actions: ".count($actions)."  (drafts awaiting approval: $toSend)");
        $this->line("  queue written: $queuePath");
        $this->line("  NOTHING SENT — review the queue, then run concierge:send-approved.");

        return self::SUCCESS;
    }

    /** Recursively decode a Gmail message payload into plain text. */
    private function bodyText(array $payload): string
    {
        $out = '';
        if (!empty($payload['body']['data'])) {
            $out .= base64_decode(strtr($payload['body']['data'], '-_', '+/'));
        }
        foreach (($payload['parts'] ?? []) as $part) {
            $out .= "\n" . $this->bodyText($part);
        }

        return trim(strip_tags($out));
    }
}
