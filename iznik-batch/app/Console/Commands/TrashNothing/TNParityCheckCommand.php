<?php

namespace App\Console\Commands\TrashNothing;

use App\Services\LokiService;
use App\Services\Mail\Incoming\IncomingMailService;
use App\Services\Mail\Incoming\MailParserService;
use App\Services\TrashNothing\Sync\EmailReplaySyncer;
use App\Services\TrashNothing\Sync\ParityComparer;
use App\Services\TrashNothing\Sync\PostSyncer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * On-demand parity check: runs the legacy email path and the new API path
 * against the same data and reports coverage/parity via ParityComparer's
 * coverage-first, four-layer model (see that class's docblock, and plans/
 * tn-api-post-ingestion.md section Q) — NOT a byte-identical trace diff.
 * This command owns running both paths and printing the report; the actual
 * comparison logic lives in ParityComparer so it's independently unit-
 * tested (see tests/Feature/TrashNothing/EmailApiParityTest.php).
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
                            {--date-min= : Only process emails/posts on or after this UTC timestamp (ISO-8601, e.g. 2026-07-22T10:00:00Z). Overrides the oldest-CSV-date used as the API from-date.}
                            {--date-max= : Only process emails/posts on or before this UTC timestamp (ISO-8601). Overrides the newest-CSV-date used as the API to-date. Pin this safely in the past (e.g. an hour or more ago) to avoid false Layer 1 misses from TN\'s own indexing lag on its most recent posts — see plans/tn-api-post-ingestion.md section Q.}';

    protected $description = 'Compare legacy email path vs API path TN-SYNC-TRACE output for parity.';

    public function handle(
        LokiService $loki,
        MailParserService $parser,
        IncomingMailService $mailService,
    ): int {
        $localTesting = (bool) $this->option('local-testing');
        $dateMin      = $this->option('date-min') ?: null;
        $dateMax      = $this->option('date-max') ?: null;

        // ── 1. Download (or load) the CSV ──────────────────────────────────
        $csvText = $this->loadCsvText($localTesting);

        if ($csvText === null) {
            $this->error('Failed to load post-log CSV.');
            return Command::FAILURE;
        }

        [$csvFrom, $csvTo] = EmailReplaySyncer::extractDateRangeFromCsvText($csvText);

        if ($csvFrom === null || $csvTo === null) {
            $this->error('CSV contains no parseable dates — cannot derive sync window.');
            return Command::FAILURE;
        }

        // --date-min/--date-max override the from/to used for the API window; the
        // email syncer uses them as skip-before/skip-after cutoffs on the
        // already-loaded CSV records. Capping --date-max safely in the past avoids
        // false Layer 1 misses from TN's own indexing lag on its newest posts —
        // see plans/tn-api-post-ingestion.md section Q for confirmed examples.
        $from = $dateMin ?? $csvFrom;
        $to   = $dateMax ?? $csvTo;

        $this->line(
            "Date range: from={$from} to={$to}"
            . ($dateMin ? ' (--date-min applied)' : '')
            . ($dateMax ? ' (--date-max applied)' : '')
        );

        // ── 2. Email path ──────────────────────────────────────────────────
        $this->line('Running email path…');

        $emailLines = $this->captureTraceLogs(function () use ($localTesting, $loki, $parser, $mailService, $dateMin, $dateMax) {
            $syncer = new EmailReplaySyncer($localTesting, $loki, $parser, $mailService);
            $syncer->sync($dateMin, $dateMax);
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
        $layers = (new ParityComparer())->computeLayers($emailLines, $apiLines);
        $this->printReport($layers);

        if (empty($layers['layer1Missing']) && empty($layers['layer3Mismatches'])) {
            $this->info('PASS: no coverage gaps and no same-group parity mismatches.');
            return Command::SUCCESS;
        }

        $this->error('FAIL: see Layer 1/3 failures above.');
        return Command::FAILURE;
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
     * TN-SYNC-TRACE log line ParityComparer::isRelevantTraceLine() cares
     * about — see that method's docblock for exactly which tags and why.
     *
     * @return string[]
     */
    private function captureTraceLogs(\Closure $callback): array
    {
        $lines    = [];
        $listener = static function ($message) use (&$lines) {
            $text = (string) $message->message;
            if (ParityComparer::isRelevantTraceLine($text)) {
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
}
