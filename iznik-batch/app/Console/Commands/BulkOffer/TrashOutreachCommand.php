<?php

namespace App\Console\Commands\BulkOffer;

use App\Services\Gmail\GmailService;
use Illuminate\Console\Command;

/**
 * Move matching mailbox messages to Trash - e.g. delivery-failure NDRs whose
 * bounce has already been handled (address corrected + re-queued, or org marked
 * defunct), so the outreach inbox stays clean. Reusable: pass any Gmail search.
 *
 * Dry-run by default (lists what it would trash); --live actually trashes.
 */
class TrashOutreachCommand extends Command
{
    protected $signature = 'bulkoffer:trash-outreach
                            {--query= : Gmail search for messages to trash (required)}
                            {--live : Actually trash; default is a dry-run listing}';

    protected $description = 'Move matching outreach-mailbox messages (e.g. handled bounce NDRs) to Trash';

    public function handle(GmailService $gmail): int
    {
        $query = trim((string) $this->option('query'));
        if ($query === '') {
            $this->error('--query is required.');

            return self::FAILURE;
        }

        try {
            $threads = $gmail->listThreads($query, 100);
        } catch (\Throwable $e) {
            $this->error('listThreads failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $live = (bool) $this->option('live');
        $count = 0;
        foreach ($threads as $t) {
            $threadId = $t['id'] ?? null;
            if (! $threadId) {
                continue;
            }
            try {
                $full = $gmail->getThread($threadId);
            } catch (\Throwable $e) {
                $this->warn("getThread {$threadId} failed: {$e->getMessage()}");

                continue;
            }
            foreach (($full['messages'] ?? []) as $m) {
                if (empty($m['id'])) {
                    continue;
                }
                // Only touch messages actually in the Inbox: Gmail threads a bounce
                // NDR together with our own Sent copy, and we must not trash the latter.
                if (! in_array('INBOX', $m['labelIds'] ?? [], true)) {
                    continue;
                }
                $subject = '';
                foreach (($m['payload']['headers'] ?? []) as $h) {
                    if (isset($h['name']) && strtolower($h['name']) === 'subject') {
                        $subject = $h['value'] ?? '';
                    }
                }
                if ($live) {
                    try {
                        $gmail->modifyMessageLabels($m['id'], ['TRASH']);
                    } catch (\Throwable $e) {
                        $this->warn("trash {$m['id']} failed: {$e->getMessage()}");

                        continue;
                    }
                }
                $this->line(($live ? 'TRASHED ' : 'would trash ').$m['id'].'  '.substr($subject, 0, 80));
                $count++;
            }
        }

        $this->info(($live ? 'Trashed ' : 'Would trash ').$count.' message(s) matching: '.$query);

        return self::SUCCESS;
    }
}
