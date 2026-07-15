<?php

namespace App\Services\TrashNothing\Sync;

use App\Services\LokiService;
use App\Services\Mail\Incoming\IncomingMailService;
use App\Services\Mail\Incoming\MailParserService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Replays TN "post emails sent" records through the legacy email-ingestion
 * pipeline (MailParserService + IncomingMailService), so the resulting
 * TN-SYNC-TRACE [WRITE]/[POST-*] log lines can be diffed against a
 * GroupPostIngestionService dry-run over the same date range.
 *
 * Unlike PostSyncer, this always writes for real — there is no dry-run
 * mode, because it exists specifically to exercise the real legacy write
 * path for comparison. Run it against a disposable test database.
 *
 * The "post emails" TN API endpoint does not exist yet; this class is the
 * prep work for when it does. Two record shapes are supported so it needs
 * minimal change once the real endpoint is documented:
 *   - `raw_message` present: used verbatim as the RFC822 body (most
 *     faithful — byte-identical to what TN actually sent).
 *   - otherwise: an RFC822 message is synthesized from structured fields
 *     (see buildRawEmail()), mirroring the real TN email shape recorded in
 *     tests/fixtures/emails/tn_post.eml.
 */
class EmailReplaySyncer
{
    private const PAGE_SIZE = 100;

    public function __construct(
        private readonly bool $localTesting,
        private readonly string $apiKey,
        private readonly string $apiBaseUrl,
        private readonly LokiService $loki,
        private readonly MailParserService $parser,
        private readonly IncomingMailService $mailService,
    ) {}

    /**
     * @return array{int, string|null} [count, maxDate]
     */
    public function sync(string $from, string $to): array
    {
        $page    = 1;
        $count   = 0;
        $maxDate = null;

        do {
            $emails = $this->fetchPage($page, $from, $to);
            if ($emails === null) {
                break;
            }

            Log::info('TN-SYNC-TRACE [EMAILS-PAGE] page=' . $page . ' count=' . count($emails));

            foreach ($emails as $record) {
                $count++;
                $maxDate = $this->processEmail($record, $maxDate);
            }

            $page++;
        } while ($emails && count($emails) === self::PAGE_SIZE);

        Log::info('TN-SYNC-TRACE [EMAILS-DONE] total=' . $count . ' max_date=' . ($maxDate ?? 'null'));

        return [$count, $maxDate];
    }

    /**
     * @return array|null  Email records, or null on API error.
     */
    private function fetchPage(int $page, string $from, string $to): ?array
    {
        if ($this->localTesting) {
            return $this->fetchPageFromFixture($page);
        }

        // TODO: Endpoint path is a placeholder — TN hasn't documented this API yet.
        // Update once it has: this follows the same direct-HTTP convention as
        // RatingsSyncer/UserChangesSyncer (fd/api/*), not the generated OpenAPI
        // client PostSyncer uses, since it's most likely another internal
        // fd/api/* endpoint rather than a public one.
        $response = Http::get("{$this->apiBaseUrl}/post-emails", [
            'key'      => $this->apiKey,
            'page'     => $page,
            'per_page' => self::PAGE_SIZE,
            'date_min' => $from,
            'date_max' => $to,
        ]);

        if (!$response->successful()) {
            Log::error('TN sync: post-emails API failed on page ' . $page, ['status' => $response->status()]);
            return null;
        }

        return $response->json('emails', []);
    }

    /**
     * @return array  Email records for this page (fixture mode has no separate num_pages
     *                 signal — pagination stops when a page returns fewer than PAGE_SIZE).
     */
    private function fetchPageFromFixture(int $page): array
    {
        $fixtureFile = base_path("tests/fixtures/tn_sync/post_emails_page_{$page}.json");

        if (!file_exists($fixtureFile)) {
            Log::info('TN-SYNC-TRACE [EMAILS-PAGE] missing fixture file=' . $fixtureFile);
            return [];
        }

        $payload = json_decode(file_get_contents($fixtureFile), true);

        return is_array($payload) ? ($payload['emails'] ?? []) : [];
    }

    private function processEmail(array $record, ?string $maxDate): ?string
    {
        $postId  = $record['post_id'] ?? '';
        $groupId = $record['group_id'] ?? '';
        $date    = $record['date'] ?? null;

        if ($date && (!$maxDate || $date > $maxDate)) {
            $maxDate = $date;
        }

        Log::info('TN-SYNC-TRACE [EMAIL] post_id=' . $postId . ' group_id=' . $groupId . ' date=' . $date . ' subject=' . substr((string) ($record['subject'] ?? ''), 0, 60));

        try {
            $envelopeFrom = $record['envelope_from'] ?? ($record['from_address'] ?? '');
            $envelopeTo   = $record['envelope_to'] ?? ($groupId . '@' . config('freegle.mail.group_domain'));

            $rawMessage = $record['raw_message'] ?? $this->buildRawEmail($record, $envelopeTo);

            $parsed = $this->parser->parse($rawMessage, $envelopeFrom, $envelopeTo);
            $result = $this->mailService->route($parsed);

            Log::info('TN-SYNC-TRACE [EMAIL-RESULT] post_id=' . $postId . ' result=' . $result->value);

            $this->loki->logEvent('tn-sync', 'email-replay', [
                'tn_post_id' => $postId,
                'group_id'   => $groupId,
                'result'     => $result->value,
            ]);
        } catch (\Throwable $e) {
            Log::error('TN sync: email replay failed', [
                'post_id' => $postId,
                'error'   => $e->getMessage(),
            ]);
        }

        return $maxDate;
    }

    /**
     * Synthesize an RFC822 message from structured post-email fields, mirroring
     * the real TN email shape recorded in tests/fixtures/emails/tn_post.eml.
     *
     * Photo/pic-link handling is deliberately omitted: the legacy path scrapes
     * https://trashnothing.com/pics/* links from the body via a live HTTP fetch
     * (scrapeTnImageUrls), which isn't safe to trigger from synthesized/fixture
     * data. Pass `raw_message` instead if attachment parity needs testing.
     */
    private function buildRawEmail(array $record, string $envelopeTo): string
    {
        $fromAddress = $record['from_address'] ?? 'unknown@user.trashnothing.com';
        $fromName    = $record['from_name'] ?? null;
        $from        = $fromName ? "\"{$fromName}\" <{$fromAddress}>" : $fromAddress;

        $date = $record['date'] ?? now()->toIso8601String();
        try {
            $dateStr = (new \DateTime($date))->format('D, d M Y H:i:s O');
        } catch (\Exception $e) {
            $dateStr = now()->format('D, d M Y H:i:s O');
        }

        $headers = [
            'From'    => $from,
            'To'      => $envelopeTo,
            'Subject' => $record['subject'] ?? '',
            'Date'    => $dateStr,
        ];

        if (!empty($record['message_id'])) {
            $headers['Message-ID'] = '<' . $record['message_id'] . '>';
        }
        if (!empty($record['secret'])) {
            $headers['X-Trash-Nothing-Secret'] = $record['secret'];
        }
        if (!empty($record['post_id'])) {
            $headers['X-Trash-Nothing-Post-Id'] = $record['post_id'];
        }
        if (!empty($record['source'])) {
            $headers['X-Trash-Nothing-Source'] = $record['source'];
        }
        if (!empty($record['sender_ip'])) {
            $headers['X-Trash-Nothing-User-IP'] = $record['sender_ip'];
        }
        if (isset($record['latitude']) && isset($record['longitude'])) {
            $headers['X-Trash-Nothing-Post-Coordinates'] = $record['latitude'] . ',' . $record['longitude'];
        }

        $headers['MIME-Version'] = '1.0';
        $headers['Content-Type'] = 'text/plain; charset=utf-8';

        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }

        return implode("\r\n", $lines) . "\r\n\r\n" . ($record['content'] ?? '');
    }
}
