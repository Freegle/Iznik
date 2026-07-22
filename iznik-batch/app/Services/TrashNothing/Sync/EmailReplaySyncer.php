<?php

namespace App\Services\TrashNothing\Sync;

use App\Services\LokiService;
use App\Services\Mail\Incoming\IncomingMailService;
use App\Services\Mail\Incoming\MailParserService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Replays TN post emails through the legacy email-ingestion pipeline
 * (MailParserService + IncomingMailService), so the resulting
 * TN-SYNC-TRACE [WRITE]/[POST-*] log lines can be diffed against a
 * GroupPostIngestionService dry-run over the same date range.
 *
 * Unlike PostSyncer, this always writes for real — there is no dry-run
 * mode, because it exists specifically to exercise the real legacy write
 * path for comparison. Run it against a disposable test database.
 *
 * Data source: https://trashnothing.com/cimg/fd-post-log.csv (downloaded
 * with a cache-busting query string on each run). In --local-testing mode,
 * uses tests/fixtures/tn_sync/fd_post_log.csv instead.
 *
 * CSV columns expected (case-insensitive):
 *   Body, Date, From, Subject, To, X-Trash-Nothing-Ip-Hash,
 *   X-trash-nothing-Post-Coordinates, X-trash-nothing-Post-ID,
 *   X-trash-nothing-Source, X-trash-nothing-User-ID
 *
 * The TN secret (X-Trash-Nothing-Secret) is not present in the CSV export
 * and is injected from config('freegle.mail.trashnothing_secret') so that
 * IncomingMailService::shouldSkipSpamCheck() correctly skips spam checking
 * for these TN posts (matching the original email path behaviour).
 */
class EmailReplaySyncer
{
    private const CSV_URL          = 'https://trashnothing.com/cimg/fd-post-log.csv';
    private const FIXTURE_CSV_PATH = 'tests/fixtures/tn_sync/fd_post_log.csv';

    public function __construct(
        private readonly bool $localTesting,
        private readonly LokiService $loki,
        private readonly MailParserService $parser,
        private readonly IncomingMailService $mailService,
    ) {}

    /**
     * Load and replay all records from the CSV.
     *
     * @param  string|null  $dateMin  ISO-8601 UTC cutoff; records with an earlier date are skipped.
     * @return array{int, string|null, string|null} [count, minDate, maxDate]
     */
    public function sync(?string $dateMin = null): array
    {
        $records = $this->loadAllRecords();

        if ($dateMin !== null) {
            $records = array_filter($records, static fn (array $r) => ($r['date'] ?? '') >= $dateMin);
            $records = array_values($records);
        }

        Log::info('TN-SYNC-TRACE [EMAILS-PAGE] count=' . count($records));

        $count   = 0;
        $minDate = null;
        $maxDate = null;

        foreach ($records as $record) {
            $count++;
            [$minDate, $maxDate] = $this->processEmail($record, $minDate, $maxDate);
        }

        Log::info('TN-SYNC-TRACE [EMAILS-DONE] total=' . $count . ' min_date=' . ($minDate ?? 'null') . ' max_date=' . ($maxDate ?? 'null'));

        return [$count, $minDate, $maxDate];
    }

    /**
     * Extract the date range (min, max) from raw CSV text without processing
     * any emails. Useful for callers that want the range to pass to tn:sync.
     *
     * @return array{string|null, string|null} [minDate, maxDate]
     */
    public static function extractDateRangeFromCsvText(string $csvText): array
    {
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $csvText);
        rewind($fh);

        $headers = fgetcsv($fh);
        if ($headers === false) {
            fclose($fh);
            return [null, null];
        }

        $headersLower = array_map('strtolower', array_map('trim', $headers));
        $dateIdx      = array_search('date', $headersLower, true);

        $dates = [];
        while (($row = fgetcsv($fh)) !== false) {
            if ($dateIdx !== false && isset($row[$dateIdx]) && $row[$dateIdx] !== '') {
                $dates[] = $row[$dateIdx];
            }
        }

        fclose($fh);

        if (empty($dates)) {
            return [null, null];
        }

        sort($dates);

        return [reset($dates), end($dates)];
    }

    private function loadAllRecords(): array
    {
        if ($this->localTesting) {
            return $this->loadRecordsFromFixtureCsv();
        }

        // Append a random suffix to prevent any proxy or CDN from serving a stale copy.
        $url      = self::CSV_URL . '?_=' . bin2hex(random_bytes(8));
        $response = Http::get($url);

        if (!$response->successful()) {
            Log::error('TN sync: failed to download post-log CSV', [
                'status' => $response->status(),
                'url'    => self::CSV_URL,
            ]);
            return [];
        }

        return $this->parseCsvText($response->body());
    }

    private function loadRecordsFromFixtureCsv(): array
    {
        $path = base_path(self::FIXTURE_CSV_PATH);

        if (!file_exists($path)) {
            Log::info('TN-SYNC-TRACE [EMAILS-PAGE] missing fixture csv path=' . $path);
            return [];
        }

        return $this->parseCsvText((string) file_get_contents($path));
    }

    private function parseCsvText(string $csvText): array
    {
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $csvText);
        rewind($fh);

        $headers = fgetcsv($fh);
        if ($headers === false) {
            fclose($fh);
            return [];
        }

        $headersLower = array_map('strtolower', array_map('trim', $headers));

        $records = [];
        while (($values = fgetcsv($fh)) !== false) {
            if (count($values) !== count($headersLower)) {
                continue;
            }
            $row       = array_combine($headersLower, $values);
            $records[] = $this->parseCsvRow($row);
        }

        fclose($fh);

        return $records;
    }

    private function parseCsvRow(array $row): array
    {
        $record = [];

        // From: "Name <email>" or bare email address.
        $from = trim($row['from'] ?? '');
        if (preg_match('/^"?(.+?)"?\s*<([^>]+)>$/', $from, $m)) {
            $record['from_name']    = trim($m[1], '"');
            $record['from_address'] = trim($m[2]);
        } else {
            $record['from_address'] = $from;
        }

        $record['date']        = trim($row['date'] ?? '');
        $record['subject']     = trim($row['subject'] ?? '');
        $record['content']     = $row['body'] ?? '';

        $to                    = trim($row['to'] ?? '');
        $record['envelope_to'] = $to;
        if ($to !== '' && str_contains($to, '@')) {
            // Derive group_id from the local-part of the To address
            // ("8444" from "8444@groups.ilovefreegle.org").
            $record['group_id'] = explode('@', $to, 2)[0];
        }

        $postId            = trim($row['x-trash-nothing-post-id'] ?? '');
        $record['post_id'] = $postId;
        // Synthesize a Message-ID in the same format as GroupPostIngestionService so
        // IncomingMailService appends the group-index suffix and both paths produce an
        // identical messages.messageid value (e.g. "tn-test-103@tn.trashnothing.com-1").
        if ($postId !== '') {
            $record['message_id'] = $postId . '@tn.trashnothing.com';
        }
        $record['source']    = trim($row['x-trash-nothing-source'] ?? '');
        $record['sender_ip'] = trim($row['x-trash-nothing-ip-hash'] ?? '');

        $coords = trim($row['x-trash-nothing-post-coordinates'] ?? '');
        if ($coords !== '' && str_contains($coords, ',')) {
            [$lat, $lng] = explode(',', $coords, 2);
            $lat = trim($lat);
            $lng = trim($lng);
            if ($lat !== '' && $lng !== '') {
                $record['latitude']  = (float) $lat;
                $record['longitude'] = (float) $lng;
            }
        }

        // Inject the TN secret so IncomingMailService::shouldSkipSpamCheck() returns true.
        // All CSV rows are confirmed TN posts; the original email always carried the secret.
        $record['secret'] = (string) config('freegle.mail.trashnothing_secret', '');

        return $record;
    }

    /**
     * @return array{?string, ?string} Updated [minDate, maxDate].
     */
    private function processEmail(array $record, ?string $minDate, ?string $maxDate): array
    {
        $postId  = $record['post_id'] ?? '';
        $groupId = $record['group_id'] ?? '';
        $date    = $record['date'] ?? null;

        if ($date) {
            if (!$minDate || $date < $minDate) {
                $minDate = $date;
            }
            if (!$maxDate || $date > $maxDate) {
                $maxDate = $date;
            }
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

        return [$minDate, $maxDate];
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
