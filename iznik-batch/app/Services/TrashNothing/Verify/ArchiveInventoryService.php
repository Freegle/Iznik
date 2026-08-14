<?php

namespace App\Services\TrashNothing\Verify;

use App\Services\Mail\Incoming\IncomingArchiveReader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Builds an inventory of the TN post emails that arrived in a time window.
 *
 * This is the independent witness tn:verify-email-coverage checks the API path
 * against — see plans/tn-api-post-ingestion.md section S.1. It reads only; it
 * never routes, ingests or mutates anything, and it does not archive anything
 * either: IncomingArchiveService already captures every inbound email before
 * routing, and IncomingArchiveReader does the walking. What lives here is
 * purely the TN-specific part — which records count, and how to key them.
 *
 * Archive retention is 48h (mail:cleanup-archive), which is the hard bound on
 * how far behind real time verification can run.
 */
class ArchiveInventoryService
{
    /**
     * Header that marks an email as a TN post. Matched as a cheap substring on
     * the raw message to decide whether a full MIME parse is worth doing —
     * at production volumes most archived mail is not TN post traffic, and
     * parsing all of it would dominate the run.
     */
    private const TN_POST_HEADER = 'X-Trash-Nothing-Post-Id';

    public function __construct(
        private readonly IncomingArchiveReader $archive,
        private readonly TnEmailRoutingGate $gate,
    ) {}

    /**
     * Collect every TN post email whose archive timestamp falls in [$from, $to].
     *
     * Keyed by TN post id. Where the same post id was emailed more than once
     * (TN re-delivery), the earliest archived copy wins — that is the arrival
     * the API path had the most time to catch up with.
     *
     * NOTE this does NOT collapse crossposts: TN gives each per-group copy its
     * own post id, so N group copies legitimately produce N inventory entries.
     * Sorting that out is CoverageVerifier's job, and it needs the API to do it.
     *
     * @return array{
     *     posts: array<string, array{post_id: string, timestamp: string, subject: string|null, lat: float|null, lng: float|null, envelope_to: string, outcome: string|null, path: string}>,
     *     stats: array{archive_present: bool, files_scanned: int, files_unreadable: int, tn_posts: int, duplicates: int}
     * }
     */
    public function collect(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $posts = [];
        $stats = [
            // Reported separately from an empty result: a missing archive means
            // the verifier is blind, which must not read as "nothing arrived".
            'archive_present'  => $this->archive->exists(),
            'files_scanned'    => 0,
            'files_unreadable' => 0,
            'tn_posts'         => 0,
            'duplicates'       => 0,
        ];

        if (! $stats['archive_present']) {
            Log::warning('TN coverage: incoming archive directory missing', ['dir' => $this->archive->directory()]);

            return ['posts' => $posts, 'stats' => $stats];
        }

        $slop = $this->filenameSlopSeconds();

        foreach ($this->archive->files(...$this->dateRange($from, $to)) as $path) {
            // Prefilter on the filename before touching the file. The archive
            // holds ALL inbound mail, not just TN posts, so at production
            // volume the alternative is reading and base64-decoding tens of
            // thousands of unrelated emails every hour to find a window an
            // hour wide.
            if (! $this->filenameCouldBeInWindow($path, $from, $to, $slop)) {
                continue;
            }

            $stats['files_scanned']++;

            $record = $this->archive->read($path);
            if ($record === null) {
                $stats['files_unreadable']++;
                continue;
            }

            $timestamp = $this->archive->recordTimestamp($record);
            if ($timestamp === null || $timestamp->lt($from) || $timestamp->gt($to)) {
                continue;
            }

            $entry = $this->extractTnPost($record, $timestamp, $path);
            if ($entry !== null) {
                $this->addEntry($posts, $stats, $entry);
            }
        }

        $stats['tn_posts'] = count($posts);

        return ['posts' => $posts, 'stats' => $stats];
    }

    /**
     * Date-directory bounds for the window, widened by a day either side.
     *
     * The padding covers the app-timezone/UTC mismatch described on
     * filenameSlopSeconds(); the authoritative filter is the record's own
     * timestamp, applied in collect().
     *
     * @return array{string, string}
     */
    private function dateRange(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [$from->subDay()->format('Y-m-d'), $to->addDay()->format('Y-m-d')];
    }

    /**
     * Add an entry to the inventory, keeping the earliest copy of a repeated
     * post id.
     */
    private function addEntry(array &$posts, array &$stats, array $entry): void
    {
        $postId = $entry['post_id'];

        if (! isset($posts[$postId])) {
            $posts[$postId] = $entry;

            return;
        }

        $stats['duplicates']++;

        if ($entry['timestamp'] < $posts[$postId]['timestamp']) {
            $posts[$postId] = $entry;
        }
    }

    /**
     * How far a filename's implied time may sit from the record's own UTC
     * timestamp.
     *
     * IncomingArchiveService names files with date()/His (app timezone) but
     * stamps records with gmdate() (UTC), so the two differ by the app's UTC
     * offset — zero today, since config('app.timezone') is UTC. Deriving the
     * slop from the configured timezone rather than assuming keeps the
     * prefilter correct if that ever changes, and the extra hour covers DST
     * plus the gap between naming a file and writing it.
     */
    private function filenameSlopSeconds(): int
    {
        $offsetMinutes = CarbonImmutable::now(config('app.timezone', 'UTC'))->utcOffset();

        return abs($offsetMinutes) * 60 + 3600;
    }

    /**
     * Cheap window test from the path alone.
     *
     * Deliberately inclusive: a file whose name cannot be parsed is kept, so a
     * naming change upstream degrades performance rather than silently dropping
     * posts from the inventory. collect() applies the authoritative test.
     */
    private function filenameCouldBeInWindow(string $path, CarbonImmutable $from, CarbonImmutable $to, int $slop): bool
    {
        $stamp = $this->archive->filenameTimestamp($path);

        if ($stamp === null) {
            return true;
        }

        return $stamp->getTimestamp() >= $from->getTimestamp() - $slop
            && $stamp->getTimestamp() <= $to->getTimestamp() + $slop;
    }

    /**
     * Decode one archived email and, if it is a TN group post, return its
     * inventory entry.
     *
     * @return array{post_id: string, timestamp: string, subject: string|null, lat: float|null, lng: float|null, envelope_to: string, outcome: string|null, path: string}|null
     */
    private function extractTnPost(array $record, CarbonImmutable $timestamp, string $path): ?array
    {
        $raw = $this->archive->rawEmail($record);
        if ($raw === null) {
            return null;
        }

        // Cheap gate before the expensive one — see TN_POST_HEADER.
        if (stripos($raw, self::TN_POST_HEADER) === false) {
            return null;
        }

        try {
            $parsed = $this->archive->parse($raw, $record);
        } catch (\Throwable $e) {
            Log::warning('TN coverage: could not parse archived email', [
                'path'  => $path,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        // Same predicate the routing gate uses, so the inventory contains
        // exactly the emails the cutover stopped routing — no more, no less.
        if (! $this->gate->isTrashNothingGroupPost($parsed)) {
            return null;
        }

        [$lat, $lng] = $this->parseCoordinates($parsed->getTrashNothingCoordinates());

        return [
            'post_id'     => (string) $parsed->getTrashNothingPostId(),
            'timestamp'   => $timestamp->format('Y-m-d\TH:i:s\Z'),
            'subject'     => $parsed->subject,
            'lat'         => $lat,
            'lng'         => $lng,
            'envelope_to' => (string) ($record['envelope']['to'] ?? ''),
            'outcome'     => isset($record['routing_outcome']) ? (string) $record['routing_outcome'] : null,
            'path'        => $path,
        ];
    }

    /**
     * Split the X-Trash-Nothing-Post-Coordinates header, matching how the email
     * path itself reads it (IncomingMailService::createGroupPostMessage).
     *
     * @return array{float|null, float|null}
     */
    private function parseCoordinates(?string $header): array
    {
        if ($header === null || $header === '') {
            return [null, null];
        }

        $parts = explode(',', $header);
        if (count($parts) < 2) {
            return [null, null];
        }

        return [(float) trim($parts[0]), (float) trim($parts[1])];
    }
}
