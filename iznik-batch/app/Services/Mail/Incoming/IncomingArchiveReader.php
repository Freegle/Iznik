<?php

namespace App\Services\Mail\Incoming;

use Carbon\CarbonImmutable;

/**
 * Reads back what IncomingArchiveService wrote.
 *
 * The archive is a directory of JSON files, not a queryable store, so every
 * consumer that wants to ask it a question has to do the same walk: resolve the
 * directory, iterate date folders, filter by date, iterate files, json_decode,
 * base64-decode the raw message, MIME-parse it. That traversal lived inline in
 * RecoverDroppedMergedUserMailCommand and was about to be written a second time
 * for tn:verify-email-coverage; it lives here instead.
 *
 * Deliberately a toolkit rather than a pipeline: the two consumers filter on
 * different things (a routing outcome vs a time window and a TN header) and do
 * very different things with the result (re-delivering mail vs building a
 * read-only inventory), so this supplies the steps and lets each compose them.
 *
 * Read-only. IncomingArchiveService remains the only writer.
 */
class IncomingArchiveReader
{
    public function __construct(
        private readonly MailParserService $parser,
        // Overridable for tests; defaults to the real archive location.
        private readonly ?string $archiveDir = null,
    ) {}

    public function directory(): string
    {
        return $this->archiveDir ?? storage_path('incoming-archive');
    }

    /**
     * Whether the archive directory exists at all.
     *
     * Worth distinguishing from "the archive is empty": a consumer that treats
     * a missing archive as "no mail arrived" reports a clean result while
     * actually being blind.
     */
    public function exists(): bool
    {
        return is_dir($this->directory());
    }

    /**
     * Archive file paths whose date directory falls in [$fromDate, $toDate].
     *
     * Dates are Y-m-d strings compared lexicographically, which is exactly what
     * the directory naming allows; null means unbounded on that side. Yields in
     * date order.
     *
     * @return \Generator<string> absolute paths
     */
    public function files(?string $fromDate = null, ?string $toDate = null): \Generator
    {
        $dir = $this->directory();

        if (! is_dir($dir)) {
            return;
        }

        $dateDirs = @scandir($dir);
        if ($dateDirs === false) {
            return;
        }

        sort($dateDirs);

        foreach ($dateDirs as $dateDir) {
            if ($dateDir === '.' || $dateDir === '..') {
                continue;
            }
            if ($fromDate !== null && $dateDir < $fromDate) {
                continue;
            }
            if ($toDate !== null && $dateDir > $toDate) {
                continue;
            }

            $fullDir = $dir . '/' . $dateDir;
            if (! is_dir($fullDir)) {
                continue;
            }

            $files = @scandir($fullDir);
            if ($files === false) {
                continue;
            }

            foreach ($files as $file) {
                if (! str_ends_with($file, '.json')) {
                    continue;
                }

                yield $fullDir . '/' . $file;
            }
        }
    }

    /**
     * Decode one archive file.
     *
     * Returns null when the file is missing, truncated or still being written —
     * all of which happen routinely, since mail keeps arriving while a reader
     * is walking the directory.
     *
     * @return array<string, mixed>|null
     */
    public function read(string $path): ?array
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        $data = json_decode($contents, true);

        return is_array($data) ? $data : null;
    }

    /**
     * The original message text, or null if absent/undecodable.
     */
    public function rawEmail(array $record): ?string
    {
        $encoded = $record['raw_email'] ?? null;
        if (! is_string($encoded) || $encoded === '') {
            return null;
        }

        $raw = base64_decode($encoded, true);

        return ($raw === false || $raw === '') ? null : $raw;
    }

    /**
     * MIME-parse a record, using the envelope it was archived with.
     *
     * Takes the already-decoded text so a caller that has inspected the raw
     * message (say, to check for a header before paying for a full parse)
     * doesn't decode it twice. Throws on a parse failure rather than returning
     * null, because the callers treat that differently from "nothing here".
     */
    public function parse(string $rawEmail, array $record): ParsedEmail
    {
        $envelope = $record['envelope'] ?? [];

        return $this->parser->parse(
            $rawEmail,
            (string) ($envelope['from'] ?? ''),
            (string) ($envelope['to'] ?? ''),
        );
    }

    /**
     * The record's own UTC timestamp — the authoritative arrival time.
     */
    public function recordTimestamp(array $record): ?CarbonImmutable
    {
        $raw = $record['timestamp'] ?? null;
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($raw, 'UTC');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The time implied by the path (YYYY-MM-DD/HHMMSS_*.json), or null if the
     * path doesn't follow that shape.
     *
     * Only an approximation of recordTimestamp(): IncomingArchiveService names
     * files with date() (app timezone) but stamps records with gmdate() (UTC),
     * so the two differ by the app's UTC offset. Useful as a cheap prefilter
     * before reading a file, never as the answer — see
     * ArchiveInventoryService::filenameSlopSeconds().
     */
    public function filenameTimestamp(string $path): ?CarbonImmutable
    {
        if (! preg_match('#/(\d{4}-\d{2}-\d{2})/(\d{2})(\d{2})(\d{2})_#', $path, $m)) {
            return null;
        }

        try {
            return CarbonImmutable::parse("{$m[1]}T{$m[2]}:{$m[3]}:{$m[4]}Z", 'UTC');
        } catch (\Throwable) {
            return null;
        }
    }
}
