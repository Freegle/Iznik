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
 * against the same data and confirms their TN-SYNC-TRACE [WRITE]/[POST-RESULT]/
 * [POST-SKIP] log output matches.
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

        // ── 4. Compare ─────────────────────────────────────────────────────
        $normalizedEmail = $this->normalizeLines($emailLines);
        $normalizedApi   = $this->normalizeLines($apiLines);

        if ($normalizedEmail === $normalizedApi) {
            $this->info('PASS: email and API paths produce matching TN-SYNC-TRACE output (' . count($normalizedEmail) . ' line(s)).');
            return Command::SUCCESS;
        }

        $this->error('FAIL: paths diverged.');
        $this->line('');
        $this->line('── Email path (' . count($normalizedEmail) . ') ────────────────────────────────────────────');
        foreach ($normalizedEmail as $line) {
            $this->line('  ' . $line);
        }
        $this->line('');
        $this->line('── API path (' . count($normalizedApi) . ') ──────────────────────────────────────────────');
        foreach ($normalizedApi as $line) {
            $this->line('  ' . $line);
        }
        $this->line('');
        $this->printLineDiff($normalizedEmail, $normalizedApi);

        return Command::FAILURE;
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
     * TN-SYNC-TRACE [WRITE] table=, [POST-RESULT], and [POST-SKIP] log line.
     *
     * @return string[]
     */
    private function captureTraceLogs(\Closure $callback): array
    {
        $lines    = [];
        $listener = static function ($message) use (&$lines) {
            $text = (string) $message->message;
            if (preg_match('/TN-SYNC-TRACE \[(POST-RESULT|POST-SKIP)\]/', $text)
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
     * Strip auto-increment msgid values so independent runs produce comparable output.
     *
     * @param  string[]  $lines
     * @return string[]
     */
    private function normalizeLines(array $lines): array
    {
        return array_map(
            static fn (string $line) => preg_replace('/\bmsgid=\d+/', 'msgid=N', $line),
            $lines
        );
    }

    /**
     * Print a simple line-by-line diff highlighting which lines differ.
     *
     * @param string[] $a
     * @param string[] $b
     */
    private function printLineDiff(array $a, array $b): void
    {
        $this->line('── Diff (- email / + api) ───────────────────────────────────────────────────');

        $max = max(count($a), count($b));
        for ($i = 0; $i < $max; $i++) {
            $lineA = $a[$i] ?? '(missing)';
            $lineB = $b[$i] ?? '(missing)';

            if ($lineA === $lineB) {
                $this->line('  ' . $lineA);
            } else {
                $this->line('- ' . $lineA);
                $this->line('+ ' . $lineB);
            }
        }
    }
}
