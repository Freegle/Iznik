<?php

namespace App\Console\Commands\TrashNothing;

use App\Services\ItemService;
use App\Services\LokiService;
use App\Services\Mail\Incoming\IncomingMailService;
use App\Services\Mail\Incoming\MailParserService;
use App\Services\TrashNothing\Sync\EmailReplaySyncer;
use App\Services\TrashNothing\Sync\PostSyncer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * On-demand parity check: runs the legacy email path and the new API path
 * against the same data and checks the API path's coverage/parity against
 * the email path using a coverage-first, four-layer model (see plans/
 * tn-api-post-ingestion.md section Q) — NOT a byte-identical trace diff.
 * The two paths are not expected to be identical: the API path is a superset
 * (it should ingest everything the email path does, plus more), and the two
 * paths resolve a post's group independently (recipient address vs.
 * coordinates), so they may legitimately disagree on group per post.
 *
 * Layer 1 — coverage (hard fail): every post_id the email path produced a
 *   [POST-RESULT] for must also be covered by the API path — either via its
 *   own [POST-RESULT], or via one of its two pre-ingest [POST-SKIP] reasons
 *   (no-coordinates / not-in-any-group-bounds). A post the email path could
 *   place that the API path drops entirely is a real regression.
 * Layer 2 — extra posts (informational): post_ids the API path covered that
 *   the email path never saw. Expected and desirable — never a failure.
 * Layer 3 — same-group parity (hard fail): for post_ids where both paths
 *   created a messages row AND resolved the same groupid, the content
 *   (fromuser/type/subject/lat/lng/locationid) and routing outcome must
 *   match exactly. messageid/message/envelopefrom/fromip/sourceheader are
 *   excluded — those are synthesized differently by design (section C).
 * Layer 4 — divergence (informational): post_ids present on both paths but
 *   where either the resolved group differs, or one/both sides never
 *   created a messages row (so there's no group to compare) — reported for
 *   visibility only, never a failure.
 *
 * Each path runs inside a rolled-back transaction so neither writes persist.
 * The date window for the API path is derived from the CSV (oldest/newest post date).
 *
 * Live mode (default): downloads fd-post-log.csv and calls the TN API.
 * Local-testing mode:  uses fixture CSV and fixture API JSON files.
 *
 * PRODUCTION SAFETY NOTE:
 * The following side effects survive the transaction rollback and will occur in production:
 *   - FCM push notifications to group mods: IncomingMailService (email path) has no dry-run
 *     mode, so notifyGroupMods() fires for any post routed to Pending for a mod reason.
 *     Mods receive a push notification for a post that never appears — a minor nuisance.
 *   - Loki log-file writes (both paths): harmless but permanent.
 * The API path uses dryRun=true, so TUS photo uploads and API-path push notifications are
 * suppressed. SpamAssassin is skipped when FREEGLE_TRASHNOTHING_SECRET is configured.
 */
class TNParityCheckCommand extends Command
{
    private const CSV_URL = 'https://trashnothing.com/cimg/fd-post-log.csv';

    protected $signature = 'tn:parity-check
                            {--local-testing : Use fixture files instead of live CSV / live TN API}
                            {--date-min= : Only process emails/posts on or after this UTC timestamp (ISO-8601, e.g. 2026-07-22T10:00:00Z). Overrides the oldest-CSV-date used as the API from-date.}';

    protected $description = 'Compare legacy email path vs API path TN-SYNC-TRACE output for parity.';

    public function handle(
        LokiService $loki,
        MailParserService $parser,
        IncomingMailService $mailService,
    ): int {
        $localTesting = (bool) $this->option('local-testing');
        $dateMin      = $this->option('date-min') ?: null;

        // ── 1. Download (or load) the CSV ──────────────────────────────────
        $csvText = $this->loadCsvText($localTesting);

        if ($csvText === null) {
            $this->error('Failed to load post-log CSV.');
            return Command::FAILURE;
        }

        [$csvFrom, $to] = EmailReplaySyncer::extractDateRangeFromCsvText($csvText);

        if ($csvFrom === null || $to === null) {
            $this->error('CSV contains no parseable dates — cannot derive sync window.');
            return Command::FAILURE;
        }

        // --date-min overrides the from-date for the API window; the email syncer
        // uses it as a skip-before cutoff on the already-loaded CSV records.
        $from = $dateMin ?? $csvFrom;

        $this->line("Date range: from={$from} to={$to}" . ($dateMin ? " (--date-min applied)" : ""));

        // ── 2. Email path ──────────────────────────────────────────────────
        $this->line('Running email path…');

        $emailLines = $this->captureTraceLogs(function () use ($localTesting, $loki, $parser, $mailService, $dateMin) {
            $syncer = new EmailReplaySyncer($localTesting, $loki, $parser, $mailService);
            $syncer->sync($dateMin);
        });

        $this->line('Email path: ' . count($emailLines) . ' trace line(s).');

        // ── 3. API path ────────────────────────────────────────────────────
        $this->line('Running API path…');

        $apiLines = $this->captureTraceLogs(function () use ($localTesting, $from, $to, $loki) {
            $apiKey     = (string) config('freegle.trashnothing.api_key', '');
            $apiBaseUrl = (string) config('freegle.trashnothing.api_base_url', '');
            // dryRun=true: [WRITE] log lines are still emitted for comparison, but no
            // DB writes, no TUS photo uploads, and no push notifications fire.
            $syncer     = new PostSyncer(true, $localTesting, $apiKey, $apiBaseUrl, $loki);
            $syncer->sync($from, $to);
        });

        $this->line('API path: ' . count($apiLines) . ' trace line(s).');

        // ── 4. Compare (coverage-first, four-layer model — see class docblock) ──
        return $this->compareAndReport($emailLines, $apiLines);
    }

    /**
     * Runs the four-layer comparison and prints a plaintext summary + failure
     * lists. Returns Command::FAILURE if Layer 1 or Layer 3 found problems,
     * Command::SUCCESS otherwise (Layers 2/4 are informational only).
     */
    private function compareAndReport(array $emailLines, array $apiLines): int
    {
        $layers = $this->computeLayers($emailLines, $apiLines);
        $this->printReport($layers);

        if (empty($layers['layer1Missing']) && empty($layers['layer3Mismatches'])) {
            $this->info('PASS: no coverage gaps and no same-group parity mismatches.');
            return Command::SUCCESS;
        }

        $this->error('FAIL: see Layer 1/3 failures above.');
        return Command::FAILURE;
    }

    /**
     * Parses both trace-line sets and buckets post_ids into the four layers.
     *
     * @return array{
     *     emailPostIdCount: int, apiCoveredCount: int,
     *     layer1Missing: string[], layer2Extra: string[],
     *     overlapCount: int, layer3Mismatches: string[], layer4Divergences: string[],
     * }
     */
    private function computeLayers(array $emailLines, array $apiLines): array
    {
        $emailResults      = $this->parseResults($emailLines);
        $apiResults        = $this->parseResults($apiLines);
        $apiPreIngestSkips = $this->parsePreIngestSkips($apiLines);
        $emailMessages     = $this->parseMessages($emailLines);
        $apiMessages       = $this->parseMessages($apiLines);
        $emailPostDetails  = $this->parsePostDetails($emailLines);

        $emailPostIds       = array_keys($emailResults);
        $apiResultPostIds   = array_keys($apiResults);
        $apiCoveragePostIds = array_unique(array_merge($apiResultPostIds, array_keys($apiPreIngestSkips)));

        // Layer 1: coverage (hard fail). Report the email path's full picture of
        // each missing post — there's nothing on the API side to show, since
        // the whole point of a Layer 1 failure is that it never covered this
        // post_id at all.
        $layer1Missing = array_values(array_diff($emailPostIds, $apiCoveragePostIds));
        $layer1Details = [];
        foreach ($layer1Missing as $postId) {
            $layer1Details[$postId] = $this->formatLayer1Detail(
                $postId,
                $emailResults[$postId] ?? '?',
                $emailPostDetails[$postId] ?? null,
                $emailMessages[$postId] ?? null,
            );
        }

        // Layer 2: extra posts (informational).
        $layer2Extra = array_values(array_diff($apiResultPostIds, $emailPostIds));

        // Layers 3 & 4: overlap, split by whether both sides created a
        // messages row on the same group.
        $overlap            = array_values(array_intersect($emailPostIds, $apiResultPostIds));
        $layer3Mismatches   = [];
        $layer4Divergences  = [];

        foreach ($overlap as $postId) {
            $this->classifyOverlapPost(
                $postId,
                $emailMessages[$postId] ?? null,
                $apiMessages[$postId] ?? null,
                $emailResults[$postId] ?? '?',
                $apiResults[$postId] ?? '?',
                $layer3Mismatches,
                $layer4Divergences,
            );
        }

        return [
            'emailPostIdCount'   => count($emailPostIds),
            'apiCoveredCount'    => count($apiCoveragePostIds),
            'layer1Missing'      => $layer1Missing,
            'layer1Details'      => $layer1Details,
            'layer2Extra'        => $layer2Extra,
            'overlapCount'       => count($overlap),
            'layer3Mismatches'   => $layer3Mismatches,
            'layer4Divergences'  => $layer4Divergences,
        ];
    }

    /**
     * Builds the full-detail block printed for a single Layer 1 (coverage)
     * failure: the email path's result, its [POST] summary (type/group_id/
     * date/title — always present), and its full messages-row field set if
     * the email path actually created a message for this post.
     *
     * @param  array{type: string, group_id: string, date: string, title: string}|null  $postDetail
     * @param  array<string, mixed>|null  $message
     */
    private function formatLayer1Detail(string $postId, string $emailResult, ?array $postDetail, ?array $message): string
    {
        $parts = ["post_id={$postId}", "email_result={$emailResult}"];

        if ($postDetail !== null) {
            $parts[] = 'type=' . $postDetail['type'];
            $parts[] = 'group_id=' . $postDetail['group_id'];
            $parts[] = 'date=' . $postDetail['date'];
            $parts[] = 'title=' . $postDetail['title'];
        }

        if ($message !== null) {
            $parts[] = 'message=' . json_encode($message);
        }

        return implode(' ', $parts);
    }

    /**
     * Classifies a single overlapping post_id into Layer 3 (same-group
     * mismatch) or Layer 4 (divergence), appending to the passed-by-reference
     * result arrays.
     *
     * @param  array<string, mixed>|null  $emailMsg
     * @param  array<string, mixed>|null  $apiMsg
     * @param  string[]  $layer3Mismatches
     * @param  string[]  $layer4Divergences
     */
    private function classifyOverlapPost(
        string $postId,
        ?array $emailMsg,
        ?array $apiMsg,
        string $emailResult,
        string $apiResult,
        array &$layer3Mismatches,
        array &$layer4Divergences,
    ): void {
        if ($emailMsg === null || $apiMsg === null) {
            // At least one side never created a messages row (dropped/skipped
            // before ingestion) — no group to compare against, so this is a
            // divergence to report, not a same-group parity failure.
            $missingSide = match (true) {
                $emailMsg === null && $apiMsg === null => 'either side',
                $emailMsg === null => 'email side',
                default => 'API side',
            };
            $layer4Divergences[] = sprintf(
                'post_id=%s email_result=%s api_result=%s (no messages row on %s)',
                $postId,
                $emailResult,
                $apiResult,
                $missingSide,
            );
            return;
        }

        if ((string) ($emailMsg['groupid'] ?? '') !== (string) ($apiMsg['groupid'] ?? '')) {
            $layer4Divergences[] = sprintf(
                'post_id=%s different groups: email groupid=%s, api groupid=%s',
                $postId,
                $emailMsg['groupid'] ?? '?',
                $apiMsg['groupid'] ?? '?',
            );
            return;
        }

        // Same group on both sides — Layer 3: content + outcome must match
        // exactly. messageid/message/envelopefrom/fromip/sourceheader are
        // deliberately excluded — synthesized differently by design.
        $fieldDiffs = $this->diffMessageFields($emailMsg, $apiMsg);

        if ($emailResult !== $apiResult) {
            $fieldDiffs[] = "result: email={$emailResult} api={$apiResult}";
        }

        if (!empty($fieldDiffs)) {
            $layer3Mismatches[] = 'post_id=' . $postId . ' groupid=' . $emailMsg['groupid'] . ': ' . implode('; ', $fieldDiffs);
        }
    }

    /**
     * @param  array<string, mixed>  $emailMsg
     * @param  array<string, mixed>  $apiMsg
     * @return string[]
     */
    private function diffMessageFields(array $emailMsg, array $apiMsg): array
    {
        $fieldsToCompare = ['fromuser', 'type', 'subject', 'lat', 'lng', 'locationid'];
        $fieldDiffs = [];

        foreach ($fieldsToCompare as $field) {
            $emailVal = $emailMsg[$field] ?? null;
            $apiVal   = $apiMsg[$field] ?? null;
            if ((string) $emailVal !== (string) $apiVal) {
                $fieldDiffs[] = "{$field}: email=" . json_encode($emailVal) . ' api=' . json_encode($apiVal);
            }
        }

        return $fieldDiffs;
    }

    private function printReport(array $layers): void
    {
        $this->line('');
        $this->line('── Parity summary ─────────────────────────────────────────────────────');
        $this->line('Layer 1 (coverage):        email=' . $layers['emailPostIdCount'] . ' api_covered=' . $layers['apiCoveredCount'] . ' missing=' . count($layers['layer1Missing']));
        $this->line('Layer 2 (extra, api-only): ' . count($layers['layer2Extra']));
        $this->line('Layer 3 (same-group):      overlap=' . $layers['overlapCount'] . ' mismatches=' . count($layers['layer3Mismatches']));
        $this->line('Layer 4 (divergence):      ' . count($layers['layer4Divergences']));
        $this->line('');

        if (!empty($layers['layer1Missing'])) {
            $this->error('Layer 1 FAILURES — posts the email path processed but the API path never covered:');
            foreach ($layers['layer1Missing'] as $postId) {
                $this->line('  ' . ($layers['layer1Details'][$postId] ?? 'post_id=' . $postId));
            }
            $this->line('');
        }

        if (!empty($layers['layer2Extra'])) {
            $this->line('Layer 2 (informational) — posts the API path covered that the email path never saw:');
            foreach ($layers['layer2Extra'] as $postId) {
                $this->line('  post_id=' . $postId);
            }
            $this->line('');
        }

        if (!empty($layers['layer3Mismatches'])) {
            $this->error('Layer 3 FAILURES — same group on both paths, but content/outcome differs:');
            foreach ($layers['layer3Mismatches'] as $line) {
                $this->line('  ' . $line);
            }
            $this->line('');
        }

        if (!empty($layers['layer4Divergences'])) {
            $this->line('Layer 4 (informational) — overlapping posts with no meaningful same-group comparison:');
            foreach ($layers['layer4Divergences'] as $line) {
                $this->line('  ' . $line);
            }
            $this->line('');
        }
    }

    private function loadCsvText(bool $localTesting): ?string
    {
        if ($localTesting) {
            $path = base_path('tests/fixtures/tn_sync/fd_post_log.csv');
            if (!file_exists($path)) {
                $this->error("Fixture CSV not found: {$path}");
                return null;
            }
            return (string) file_get_contents($path);
        }

        $url      = self::CSV_URL . '?_=' . bin2hex(random_bytes(8));
        $response = Http::get($url);

        if (!$response->successful()) {
            $this->error("CSV download failed (HTTP {$response->status()}): {$url}");
            return null;
        }

        return $response->body();
    }

    /**
     * Run $callback inside a rolled-back transaction, capturing every
     * TN-SYNC-TRACE [WRITE] table=, [POST-RESULT], [EMAIL-RESULT],
     * [POST-SKIP], [POST], and [EMAIL] log line.
     *
     * [EMAIL-RESULT] (emitted by EmailReplaySyncer, not IncomingMailService)
     * is the reliable per-post outcome marker for the email path — some
     * IncomingMailService skip branches (e.g. unknown-group) never emit
     * their own internal [POST-RESULT] line, but EmailReplaySyncer emits
     * [EMAIL-RESULT] unconditionally after every route() call.
     *
     * [POST] (emitted by IncomingMailService::handleGroupPost, unconditionally,
     * before any skip/drop decision) carries type/group_id/date/title for every
     * post the email path saw — used to show full post details on a Layer 1
     * coverage failure, where there's no messages row to pull details from.
     *
     * @return string[]
     */
    private function captureTraceLogs(\Closure $callback): array
    {
        $lines    = [];
        $listener = static function ($message) use (&$lines) {
            $text = (string) $message->message;
            if (preg_match('/TN-SYNC-TRACE \[(POST-RESULT|EMAIL-RESULT|POST-SKIP|POST|EMAIL)\]/', $text)
                || preg_match('/TN-SYNC-TRACE \[WRITE\] table=/', $text)) {
                $lines[] = $text;
            }
        };

        Log::listen($listener);

        DB::beginTransaction();
        try {
            $callback();
        } finally {
            DB::rollBack();
        }

        return $lines;
    }

    /**
     * Parses `TN-SYNC-TRACE [POST-RESULT|EMAIL-RESULT] post_id=X result=Y`
     * lines into a post_id => result map, lowercased so the email path's
     * PascalCase enum values (e.g. "Dropped") compare equal to the API
     * path's lowercase strings (e.g. "dropped"). Last occurrence wins if a
     * post_id appears twice (shouldn't happen in practice, but keeps this
     * defensive).
     *
     * @param  string[]  $lines
     * @return array<string, string>
     */
    private function parseResults(array $lines): array
    {
        $results = [];
        foreach ($lines as $line) {
            if (preg_match('/TN-SYNC-TRACE \[(?:POST-RESULT|EMAIL-RESULT)\] post_id=(\S+) result=(\S+)/', $line, $m)) {
                $results[$m[1]] = strtolower($m[2]);
            }
        }
        return $results;
    }

    /**
     * Parses the API-only pre-ingest [POST-SKIP] lines emitted by
     * PostSyncer::processPost() before GroupPostIngestionService::ingest()
     * is ever called (no-coordinates / not-in-any-group-bounds) — these
     * posts never produce a [POST-RESULT] line, but the API path did "see"
     * them, so they count toward Layer 1 coverage.
     *
     * @param  string[]  $lines
     * @return array<string, string> post_id => reason
     */
    private function parsePreIngestSkips(array $lines): array
    {
        $skips = [];
        foreach ($lines as $line) {
            if (preg_match('/TN-SYNC-TRACE \[POST-SKIP\] reason=(no-coordinates|not-in-any-group-bounds).*post_id=(\S+)/', $line, $m)) {
                $skips[$m[2]] = $m[1];
            }
        }
        return $skips;
    }

    /**
     * Parses `TN-SYNC-TRACE [WRITE] table=messages op=insert set={json}`
     * lines into a tnpostid => decoded-fields map. Both paths emit this line
     * with the same key set (see class docblock), keyed by their shared
     * `tnpostid` field rather than the surrounding `post_id=` on other lines,
     * since that's what's actually inside the JSON payload here.
     *
     * @param  string[]  $lines
     * @return array<string, array<string, mixed>>
     */
    private function parseMessages(array $lines): array
    {
        $messages = [];
        foreach ($lines as $line) {
            if (!str_contains($line, 'TN-SYNC-TRACE [WRITE] table=messages op=insert set=')) {
                continue;
            }
            $json = substr($line, strpos($line, 'set=') + 4);
            $decoded = json_decode($json, true);
            if (is_array($decoded) && isset($decoded['tnpostid'])) {
                $messages[(string) $decoded['tnpostid']] = $decoded;
            }
        }
        return $messages;
    }

    /**
     * Parses `TN-SYNC-TRACE [POST] post_id=X type=Y group_id=Z date=D title=T`
     * lines into a post_id => details map. Applied to one side's lines at a
     * time by the caller (email or API) — both paths emit this tag, so
     * calling it on the combined set would silently merge two different
     * posts' details under one key.
     *
     * @param  string[]  $lines
     * @return array<string, array{type: string, group_id: string, date: string, title: string}>
     */
    private function parsePostDetails(array $lines): array
    {
        $details = [];
        foreach ($lines as $line) {
            if (preg_match('/TN-SYNC-TRACE \[POST\] post_id=(\S+) type=(\S+) group_id=(\S+) date=(\S+) title=(.*)$/', $line, $m)) {
                $details[$m[1]] = [
                    'type'     => $m[2],
                    'group_id' => $m[3],
                    'date'     => $m[4],
                    'title'    => $m[5],
                ];
            }
        }
        return $details;
    }
}
