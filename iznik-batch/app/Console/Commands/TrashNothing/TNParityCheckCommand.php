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
    private const POST_ID_PREFIX = 'post_id=';

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

        $apiKey     = (string) config('freegle.trashnothing.api_key', '');
        $apiBaseUrl = (string) config('freegle.trashnothing.api_base_url', '');
        // Doesn't use dryRun because we're already using DB transaction rollback.
        // Needs to actually write to the DB for testing, e.g. to create stub users.
        $apiSyncer  = new PostSyncer(false, $localTesting, $apiKey, $apiBaseUrl, $loki);

        $apiLines = $this->captureTraceLogs(function () use ($apiSyncer, $from, $to) {
            $apiSyncer->sync($from, $to);
        });

        $this->line('API path: ' . count($apiLines) . ' trace line(s).');

        // ── 4. Compare (coverage-first, four-layer model — see class docblock) ──
        return $this->compareAndReport($emailLines, $apiLines, $apiSyncer, $localTesting, $from, $to);
    }

    /**
     * Runs the four-layer comparison, refines Layer 1 misses via a live
     * single-post lookup fallback (see reclassifyLayer1Misses()), and prints
     * a plaintext summary + failure lists. Returns Command::FAILURE if
     * Layer 1 or Layer 3 found problems, Command::SUCCESS otherwise
     * (Layers 2/4 are informational only).
     */
    private function compareAndReport(array $emailLines, array $apiLines, PostSyncer $apiSyncer, bool $localTesting, string $from, string $to): int
    {
        $layers = (new ParityComparer())->computeLayers($emailLines, $apiLines);

        // Fixture data has no live API to look up against, and this is not
        // something unit/feature tests should exercise — skip in --local-testing.
        if (!$localTesting && !empty($layers['layer1Missing'])) {
            $layers = $this->reclassifyLayer1Misses($layers, $apiSyncer, $from, $to);
        }

        $this->printReport($layers);

        if (empty($layers['layer1Missing']) && empty($layers['layer3Mismatches'])) {
            $this->info('PASS: no coverage gaps and no same-group parity mismatches.');
            return Command::SUCCESS;
        }

        $this->error('FAIL: see Layer 1/3 failures above.');
        return Command::FAILURE;
    }

    // Outcomes meaning the post was never going to be posted to FD anyway, so
    // /posts/all correctly excludes it — not a coverage regression. Per the
    // OpenAPI Post model docblock, outcome is one of satisfied/withdrawn/
    // promised/expired (offers) or satisfied/withdrawn/expired (wanted);
    // 'deleted' isn't a real outcome value (a deleted post 404s instead, see
    // the not_found branch below) but is matched defensively in case TN ever
    // returns it as one.
    private const RESOLVED_OUTCOMES = ['satisfied', 'withdrawn', 'deleted'];

    /**
     * For each Layer 1 candidate miss, looks the post up directly by ID
     * (bypassing the date-range listing) and splits it into four buckets:
     *
     * - genuinely missing (kept in layer1Missing — a real regression)
     * - deleted from TN after the fact (moved to layer1Deleted — informational)
     * - exists but its `date` now falls outside [from, to] (moved to
     *   layer1BumpedOutOfWindow — informational; TN mutates `date` on
     *   repost/edit, which is invisible from the partner email side)
     * - reached a resolved outcome (satisfied/withdrawn) before the API path
     *   ran (moved to layer1ResolvedOutcome — informational; we wouldn't
     *   have posted it to FD anyway, so its absence isn't a regression)
     *
     * Confirmed live in production — see plans/tn-api-post-ingestion.md
     * section Q for the specific post_ids this was found against.
     */
    private function reclassifyLayer1Misses(array $layers, PostSyncer $apiSyncer, string $from, string $to): array
    {
        $stillMissing = [];
        $deleted      = [];
        $bumped       = [];
        $resolved     = [];

        foreach ($layers['layer1Missing'] as $postId) {
            $result = $apiSyncer->lookupPostById($postId);

            if ($result['status'] === 'not_found') {
                $deleted[] = $postId;
                continue;
            }

            if ($result['status'] === 'found' && in_array($result['outcome'], self::RESOLVED_OUTCOMES, true)) {
                $resolved[] = "post_id={$postId} outcome={$result['outcome']}";
                continue;
            }

            if ($result['status'] === 'found' && $result['date'] !== null && ($result['date'] < $from || $result['date'] > $to)) {
                $bumped[] = "post_id={$postId} now dated {$result['date']} (outside window {$from}..{$to})";
                continue;
            }

            $stillMissing[] = $postId;
        }

        $layers['layer1Missing']          = $stillMissing;
        $layers['layer1Deleted']          = $deleted;
        $layers['layer1BumpedOutOfWindow'] = $bumped;
        $layers['layer1ResolvedOutcome']   = $resolved;

        return $layers;
    }

    private function printReport(array $layers): void
    {
        $this->line('');
        $this->line('── Parity summary ─────────────────────────────────────────────────────');
        $this->line('Layer 1 (coverage):        email=' . $layers['emailPostIdCount'] . ' api_covered=' . $layers['apiCoveredCount'] . ' missing=' . count($layers['layer1Missing']));
        $this->line('Layer 2 (extra, api-only): ' . count($layers['layer2Extra']));
        $this->line('Layer 3 (same-group):      overlap=' . $layers['overlapCount'] . ' mismatches=' . count($layers['layer3Mismatches']));
        $this->line('Layer 4 (divergence):      ' . count($layers['layer4Divergences']));
        if (isset($layers['layer1Deleted']) || isset($layers['layer1BumpedOutOfWindow']) || isset($layers['layer1ResolvedOutcome'])) {
            $this->line('Layer 1 (filtered out):    deleted=' . count($layers['layer1Deleted'] ?? []) . ' bumped_out_of_window=' . count($layers['layer1BumpedOutOfWindow'] ?? []) . ' resolved_outcome=' . count($layers['layer1ResolvedOutcome'] ?? []));
        }
        $this->line($this->formatIngestionGainLine($layers));
        $this->line('');

        $layer1MissingLines = array_map(
            static fn (string $postId) => $layers['layer1Details'][$postId] ?? self::POST_ID_PREFIX . $postId,
            $layers['layer1Missing'],
        );
        $layer1DeletedLines = array_map(static fn (string $postId) => self::POST_ID_PREFIX . $postId, $layers['layer1Deleted'] ?? []);
        $layer2ExtraLines   = array_map(static fn (string $postId) => self::POST_ID_PREFIX . $postId, $layers['layer2Extra']);

        $this->printSection('Layer 1 FAILURES — posts the email path processed but the API path never covered:', $layer1MissingLines, isFailure: true);
        $this->printSection('Layer 1 (informational) — deleted from TN after the email was sent:', $layer1DeletedLines);
        $this->printSection('Layer 1 (informational) — exists on TN but its date was bumped outside the query window (repost/edit):', $layers['layer1BumpedOutOfWindow'] ?? []);
        $this->printSection('Layer 1 (informational) — reached a resolved outcome (satisfied/withdrawn), never going to be posted to FD:', $layers['layer1ResolvedOutcome'] ?? []);
        $this->printSection('Layer 2 (informational) — posts the API path covered that the email path never saw:', $layer2ExtraLines);
        $this->printSection('Layer 3 FAILURES — same group on both paths, but content/outcome differs:', $layers['layer3Mismatches'], isFailure: true);
        $this->printSection('Layer 4 (informational) — overlapping posts with no meaningful same-group comparison:', $layers['layer4Divergences']);
    }

    /**
     * "How many more posts are we actually getting via the API vs the old
     * email path" — restricted to posts that actually created a messages row
     * (approved/pending) on each side, not raw post_id counts, so dropped/
     * duplicate/skipped extras don't inflate the figure. Percentage is
     * relative to the email path's own ingested count (the old baseline).
     */
    private function formatIngestionGainLine(array $layers): string
    {
        $emailIngested = $layers['emailIngestedCount'] ?? 0;
        $apiIngested   = $layers['apiIngestedCount'] ?? 0;
        $extraIngested = count($layers['layer2ExtraIngested'] ?? []);

        if ($emailIngested > 0) {
            $pct = sprintf('%.1f%%', ($extraIngested / $emailIngested) * 100);
        } elseif ($extraIngested > 0) {
            $pct = 'n/a (email baseline is 0)';
        } else {
            $pct = '0%';
        }

        return "New posts via API only:    {$extraIngested} (email ingested={$emailIngested}, api ingested={$apiIngested}, +{$pct} vs email-only baseline)";
    }

    /**
     * @param  string[]  $lines
     */
    private function printSection(string $title, array $lines, bool $isFailure = false): void
    {
        if (empty($lines)) {
            return;
        }

        $isFailure ? $this->error($title) : $this->line($title);
        foreach ($lines as $line) {
            $this->line('  ' . $line);
        }
        $this->line('');
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
