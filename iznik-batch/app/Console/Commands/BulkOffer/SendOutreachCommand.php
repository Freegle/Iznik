<?php

namespace App\Console\Commands\BulkOffer;

use App\Models\MessagesBulkOutreach;
use App\Services\Gmail\GmailService;
use App\Services\MjmlCompilerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Address;

/**
 * Send the per-organisation intro email for a bulk offer ("clearance") via the
 * Gmail mailbox transport. Reads Queued rows from messages_bulk_outreach, renders
 * the self-contained outreach email (no links back to Freegle), sends it as the
 * Workspace mailbox, and advances the row to Sent - recording the Gmail message +
 * thread id so the inbox poller can match replies.
 *
 * Safety: GmailService is DRY_RUN by config default (writes the .eml to
 * storage/app/outreach/dryrun/ and never contacts Google). Even when the mailbox
 * is configured live, this command refuses to dispatch unless --live is also
 * passed, so a real send needs BOTH an env change and an explicit flag.
 *
 * Suppression (PECR): rows whose suppressed_until is in the future (e.g. an
 * org that replied UNSUBSCRIBE) are skipped.
 */
class SendOutreachCommand extends Command
{
    protected $signature = 'bulkoffer:send-outreach
                            {--msgid= : Only send for this bulk offer (messages.id)}
                            {--limit=10 : Maximum organisations to email this run}
                            {--live : Actually dispatch when the mailbox is configured live (otherwise dry-run)}';

    protected $description = 'Send the per-org intro email for a bulk offer via the Gmail mailbox (dry-run by default)';

    public function handle(GmailService $gmail, MjmlCompilerService $mjml): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $msgid = $this->option('msgid') !== null ? (int) $this->option('msgid') : null;

        // Belt-and-braces: a real send needs the mailbox configured live AND --live.
        if (! $gmail->isDryRun() && ! $this->option('live')) {
            $this->error('Mailbox is configured live but --live was not passed. Refusing to send. Re-run with --live to dispatch for real.');

            return self::FAILURE;
        }
        $mode = $gmail->isDryRun() ? 'DRY-RUN (writing .eml, no email sent)' : 'LIVE (sending real email)';
        $this->info("Outreach send: {$mode}");

        $query = DB::table('messages_bulk_outreach')
            ->where('status', MessagesBulkOutreach::STATUS_QUEUED)
            ->where(function ($q) {
                $q->whereNull('suppressed_until')->orWhereDate('suppressed_until', '<=', now()->toDateString());
            })
            ->orderBy('tier')
            ->orderBy('id')
            ->limit($limit);
        if ($msgid !== null) {
            $query->where('msgid', $msgid);
        }
        $rows = $query->get();

        if ($rows->isEmpty()) {
            $this->info('No Queued outreach rows to send.');

            return self::SUCCESS;
        }

        $unsubAddress = (string) config('services.gmail_outreach.unsub_address', 'natalie-wagg+unsub@ilovefreegle.org');
        $unsubscribeUrl = 'mailto:'.$unsubAddress.'?subject=unsubscribe';
        $signoffName = (string) config('services.gmail_outreach.from_name', 'Natalie @ Freegle');

        $sent = 0;
        $skipped = 0;
        foreach ($rows as $row) {
            $email = $this->orgEmail((int) $row->userid);
            if (! $email) {
                $this->warn("Outreach #{$row->id}: org user {$row->userid} has no email - skipping.");
                $skipped++;

                continue;
            }

            $items = $this->buildItems((int) $row->msgid);
            if (empty($items)) {
                $this->warn("Outreach #{$row->id}: bulk offer {$row->msgid} has no items - skipping.");
                $skipped++;

                continue;
            }

            $greetingName = $this->greetingName($row);
            $vars = [
                'greetingName' => $greetingName,
                'orgName' => $row->orgname,
                'area' => $row->area,
                'intro' => null,
                'items' => $items,
                'signoffName' => $signoffName,
                'unsubscribeUrl' => $unsubscribeUrl,
                'unsubscribeMailto' => $unsubAddress,
                'preview' => count($items).' free item'.(count($items) === 1 ? '' : 's').' near you to give away',
            ];

            $html = $mjml->compile(view('emails.mjml.outreach.initial', $vars)->render());
            $text = view('emails.text.outreach.initial', $vars)->render();

            try {
                $result = $gmail->send(
                    to: new Address($email, (string) $row->orgname),
                    subject: 'Free items near you, looking for a good home',
                    html: $html,
                    text: $text,
                    headers: [
                        'List-Unsubscribe' => '<'.$unsubscribeUrl.'>',
                        'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
                    ],
                );
            } catch (\Throwable $e) {
                Log::error('Outreach send failed', ['outreachid' => $row->id, 'error' => $e->getMessage()]);
                $this->error("Outreach #{$row->id}: send failed - {$e->getMessage()}");
                $skipped++;

                continue;
            }

            DB::table('messages_bulk_outreach')->where('id', $row->id)->update([
                'status' => MessagesBulkOutreach::STATUS_SENT,
                'sent_at' => now(),
                'sent_via' => 'email',
                'gmail_message_id' => $result['id'] ?? null,
                'gmail_thread_id' => $result['thread_id'] ?? null,
                'updated_at' => now(),
            ]);
            $this->line("Outreach #{$row->id}: {$row->orgname} <{$email}> - ".($result['dry_run'] ? 'dry-run .eml written' : 'sent ('.$result['id'].')'));
            $sent++;
        }

        $this->info("Done. Sent: {$sent}, skipped: {$skipped}.");

        return self::SUCCESS;
    }

    /** The org's published email (its Freegle account's preferred address). */
    private function orgEmail(int $userid): ?string
    {
        return DB::table('users_emails')
            ->where('userid', $userid)
            ->orderByDesc('preferred')
            ->orderBy('id')
            ->value('email');
    }

    /** How to address the recipient: a named contact, else the org, else "there". */
    private function greetingName(object $row): string
    {
        if (! empty($row->contactname)) {
            return $row->contactname;
        }
        if (! empty($row->orgname)) {
            return trim(explode(' ', trim($row->orgname))[0]);
        }

        return 'there';
    }

    /**
     * Build the item sections for the email from the bulk catalogue: name,
     * condition, description, a display photo and the raw high-res photo it links
     * to. Mirrors UnifiedDigest::prepareBulkItems but produces the outreach
     * template's item shape (photo / photo_full).
     *
     * @return array<int,array<string,mixed>>
     */
    private function buildItems(int $msgid): array
    {
        $rows = DB::table('messages_bulk_items')
            ->where('msgid', $msgid)
            ->orderBy('position')
            ->orderBy('id')
            ->get(['id', 'name', 'quantity', 'condition', 'dimensions', 'photourl', 'description']);
        if ($rows->isEmpty()) {
            return [];
        }

        // First photo per item (item photos are linked via the separate
        // messages_bulk_item_attachments table - no column on messages_attachments).
        $firstByItem = [];
        $atts = DB::table('messages_attachments as ma')
            ->join('messages_bulk_item_attachments as bia', 'bia.attachmentid', '=', 'ma.id')
            ->where('ma.msgid', $msgid)
            ->orderBy('ma.id')
            ->get(['ma.id', 'bia.bulkitemid', 'ma.externaluid', 'ma.externalurl', 'ma.archived']);
        foreach ($atts as $a) {
            if (! isset($firstByItem[$a->bulkitemid])) {
                $firstByItem[$a->bulkitemid] = $a;
            }
        }

        $items = [];
        foreach ($rows as $row) {
            $source = isset($firstByItem[$row->id]) ? $this->attachmentSource($firstByItem[$row->id]) : null;
            if (! $source && ! empty($row->photourl)) {
                $source = $row->photourl;
            }
            $photo = $source ? $this->deliveryUrl($source, 600) : null;
            $condition = $row->condition && $row->condition !== 'Unknown'
                ? ($row->condition === 'LikeNew' ? 'Like new' : $row->condition)
                : null;
            $qty = (int) $row->quantity;
            $name = $qty > 1 ? "{$qty}x {$row->name}" : $row->name;
            $items[] = [
                'name' => $name,
                'condition' => $condition,
                'description' => $row->description ?: ($row->dimensions ?: null),
                'photo' => $photo,
                'photo_full' => $source,
            ];
        }

        return $items;
    }

    /** Raw image source for an attachment row (the high-res image the photo links to). */
    private function attachmentSource(object $a): ?string
    {
        if (! empty($a->externalurl)) {
            return $a->externalurl;
        }
        if (! empty($a->externaluid) || (int) ($a->archived ?? 0) === 1) {
            $imagesDomain = (string) config('freegle.images.domain', 'https://images.ilovefreegle.org');

            return "{$imagesDomain}/timg_{$a->id}.jpg";
        }

        return null;
    }

    /** Run a source image URL through the delivery/resize proxy (as other email images are). */
    private function deliveryUrl(string $source, int $width): string
    {
        $base = config('freegle.delivery.base_url');
        if (! $base) {
            return $source;
        }

        return $base.'/?url='.urlencode($source).'&w='.$width;
    }
}
