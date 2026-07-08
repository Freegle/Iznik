<?php

namespace App\Console\Commands\BulkOffer;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The email-outreach brain's "propose" action — the mailbox equivalent of the
 * chat helper queuing a proposal for human sign-off. Instead of sending an email
 * reply immediately, it writes a Helper proposal (type=message, channel=email) so
 * the draft surfaces in the offerer's Bulk Offer management page (ClearanceManager
 * → HelperProposalCard) to review, edit and approve. bulkoffer:send-approved-outreach
 * then emails whatever the offerer approved.
 */
class ProposeReplyCommand extends Command
{
    protected $signature = 'bulkoffer:propose-reply
                            {--msgid= : the clearance offer message id (required)}
                            {--thread= : Gmail thread id the reply belongs to (required)}
                            {--body= : the drafted reply text (or --body-file)}
                            {--body-file= : read the drafted reply from a file}
                            {--orgname= : org name, for the review summary}
                            {--outreachid= : messages_bulk_outreach.id, if known}
                            {--summary= : short summary shown on the review card}';

    protected $description = 'Queue an outreach email reply as a Helper proposal for human review (does not send).';

    public function handle(): int
    {
        $msgid = (int) $this->option('msgid');
        $thread = trim((string) $this->option('thread'));
        $body = (string) $this->option('body');
        if ($this->option('body-file')) {
            $path = (string) $this->option('body-file');
            if (! is_file($path)) {
                $this->error("--body-file not found: {$path}");

                return self::FAILURE;
            }
            $body = (string) file_get_contents($path);
        }
        $body = trim($body);
        if (! $msgid || $thread === '' || $body === '') {
            $this->error('--msgid, --thread and a body (--body or --body-file) are required.');

            return self::FAILURE;
        }

        $offerer = (int) DB::table('messages')->where('id', $msgid)->value('fromuser');
        if (! $offerer) {
            $this->error("No such offer message {$msgid}.");

            return self::FAILURE;
        }

        // EnsureBatch (idempotent). New email-only batches default to approve mode —
        // email replies always go via review. Existing batches keep their mode.
        DB::table('helper_batches')->insertOrIgnore([
            'msgid' => $msgid,
            'offereruserid' => $offerer,
            'status' => 'active',
            'automode' => 'approve',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $batchid = (int) DB::table('helper_batches')->where('msgid', $msgid)->value('id');

        $orgname = trim((string) $this->option('orgname'));
        $summary = trim((string) $this->option('summary')) ?: ('Email reply'.($orgname !== '' ? " to {$orgname}" : ''));

        $proposalid = DB::table('helper_proposals')->insertGetId([
            'batchid' => $batchid,
            'type' => 'message',
            'replierid' => null,   // no chat replier — resolving sends no chat message
            'bulkitemid' => null,
            'summary' => mb_substr($summary, 0, 512),
            'proposed_text' => $body,
            'payload' => json_encode([
                'channel' => 'email',
                'gmail_thread_id' => $thread,
                'outreachid' => $this->option('outreachid') ? (int) $this->option('outreachid') : null,
                'orgname' => $orgname ?: null,
            ], JSON_UNESCAPED_SLASHES),
            'rationale' => 'Outreach email reply queued for review.',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->info("Proposal {$proposalid} queued for review on offer {$msgid} (batch {$batchid}).");

        return self::SUCCESS;
    }
}
