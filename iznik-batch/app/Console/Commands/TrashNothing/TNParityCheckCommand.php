<?php

namespace App\Console\Commands\TrashNothing;

use App\Services\LokiService;
use App\Services\Mail\Incoming\IncomingMailService;
use App\Services\Mail\Incoming\MailParserService;
use App\Services\TrashNothing\Sync\EmailReplaySyncer;
use App\Services\TrashNothing\Sync\ParityComparer;
use App\Services\TrashNothing\Sync\PostLogCsvFetcher;
use App\Services\TrashNothing\Sync\PostSyncer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
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
 * Live mode (default): uses the locally cached fd-post-log.csv (downloading it
 * only if there is no cached copy, or --refresh-csv is passed) and calls the TN API.
 * Local-testing mode:  uses fixture CSV and fixture API JSON files.
 *
 * PRODUCTION SAFETY NOTE:
 * The following side effects survive the transaction rollback and will occur in production:
 *   - FCM push notifications to group mods: IncomingMailService (email path) has no dry-run
 *     mode, so notifyGroupMods() fires for any post routed to Pending for a mod reason.
 *     Mods receive a push notification for a post that never appears — a minor nuisance.
 *   - Loki log-file writes (both paths): harmless but permanent.
 *   - TUS photo uploads (BOTH paths): every post's photos are downloaded from TN and
 *     uploaded to whatever `freegle.tus_uploader` points at. The API path runs with
 *     dryRun=false (see the constructor comment on $apiSyncer), so it does NOT suppress
 *     them, and neither does the email path. The uploaded blobs are orphaned once the
 *     transaction rolls back — the message_attachments rows referencing them are gone.
 *     Check TUS_UPLOADER before a long run: the dev `batch` container sets it to the
 *     local tusd, but the config DEFAULT is production (uploads.ilovefreegle.org), and
 *     each WAN round trip is also the dominant cost of a wide date window.
 * SpamAssassin is skipped when FREEGLE_TRASHNOTHING_SECRET is configured.
 */
class TNParityCheckCommand extends Command
{
    private const POST_ID_PREFIX = 'post_id=';

    /** Enough post_ids per unknown group to identify it; the count carries the rest. */
    private const UNKNOWN_GROUP_POST_IDS_SHOWN = 3;

    protected $signature = 'tn:parity-check
                            {--local-testing : Use fixture files instead of live CSV / live TN API}
                            {--refresh-csv : Re-download the TN post-log CSV even if a cached copy exists}
                            {--date-min= : Only process emails/posts on or after this UTC timestamp (ISO-8601, e.g. 2026-07-22T10:00:00Z). Overrides the oldest-CSV-date used as the API from-date.}
                            {--date-max= : Only process emails/posts on or before this UTC timestamp (ISO-8601). Overrides the newest-CSV-date used as the API to-date. Pin this safely in the past (e.g. an hour or more ago) to avoid false Layer 1 misses from TN\'s own indexing lag on its most recent posts — see plans/tn-api-post-ingestion.md section Q.}';

    protected $description = 'Compare legacy email path vs API path TN-SYNC-TRACE output for parity.';

    public function handle(
        LokiService $loki,
        MailParserService $parser,
        IncomingMailService $mailService,
    ): int {
        $localTesting = (bool) $this->option('local-testing');
        $refreshCsv   = (bool) $this->option('refresh-csv');
        $dateMin      = $this->option('date-min') ?: null;
        $dateMax      = $this->option('date-max') ?: null;

        // Layer 5 compares the two paths' Loki entries, which requires both
        // paths to actually build them — replaces the injected instance.
        $loki = $this->isolateLokiOutput();

        // ── 1. Load the CSV (from cache, or downloading if needed) ─────────
        $csvText = $this->loadCsvText($localTesting, $refreshCsv);

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
            // The CSV was already fetched above, so this reuses the cached copy
            // regardless of --refresh-csv — no second download of the same file.
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
     * Forces Loki entry generation for this run, into a throwaway directory.
     *
     * Layer 5 needs both paths to build their Loki entries, which LokiService
     * skips entirely when freegle.loki.enabled is false (the default outside
     * production). Redirecting the log path keeps a parity run — which replays
     * real posts through both ingestion paths — from injecting duplicate
     * entries into the production Loki stream that ModTools' dashboards read.
     *
     * The comparison itself reads the entries from the [LOKI] trace lines, not
     * from these files; the directory exists only because LokiService always
     * writes what it builds.
     */
    private function isolateLokiOutput(): LokiService
    {
        $dir = sys_get_temp_dir() . '/tn-parity-loki-' . getmypid();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        config(['freegle.loki.enabled' => true, 'freegle.loki.log_path' => $dir]);
        $this->line("Loki entries redirected to {$dir} (not the production stream).");

        // LokiService reads config in its constructor, so this must be built
        // after the config() call above.
        return new LokiService;
    }

    /**
     * Runs the four-layer comparison, refines Layer 1 misses via a live
     * single-post lookup fallback (see reclassifyLayer1Misses()), and prints
     * a plaintext summary + failure lists. Returns Command::FAILURE if
     * Layer 1, Layer 3 or Layer 5 found problems, or if the email path
     * resolved no group at all so there was nothing to compare;
     * Command::SUCCESS otherwise (Layers 2/4 are informational only).
     */
    private function compareAndReport(array $emailLines, array $apiLines, PostSyncer $apiSyncer, bool $localTesting, string $from, string $to): int
    {
        $layers = (new ParityComparer())->computeLayers($emailLines, $apiLines);

        // Fixture data has no live API to look up against, and this is not
        // something unit/feature tests should exercise — skip in --local-testing.
        if (!$localTesting && !empty($layers['layer1Missing'])) {
            $layers = $this->reclassifyLayer1Misses($layers, $apiSyncer, $from, $to);
        }

        if (!$localTesting && !empty($layers['subjectOnlyMismatchPostIds'])) {
            $layers = $this->reclassifySubjectMismatches($layers, $apiSyncer);
        }

        $this->printReport($layers);

        // A run where the email path resolved no group at all compared nothing:
        // every post was dropped before a messages row existed, so Layers 3-5
        // are silently empty and would otherwise print PASS. That means the
        // database is missing the groups TN posted to (a disposable parity DB
        // cloned without them, most often), not that the two paths agree.
        if ($layers['emailUnknownGroupPostCount'] > 0 && $layers['emailUnknownGroupPostCount'] === $layers['emailPostIdCount']) {
            $this->error('FAIL: the email path dropped every post as "unknown group" — none of the groups TN addressed exist in this database, so no parity was actually checked.');
            return Command::FAILURE;
        }

        if (empty($layers['layer1Missing']) && empty($layers['layer3Mismatches']) && empty($layers['layer5Mismatches'])) {
            $this->info('PASS: no coverage gaps, no same-group parity mismatches, no Loki divergence.');
            return Command::SUCCESS;
        }

        $this->error('FAIL: see Layer 1/3/5 failures above.');
        return Command::FAILURE;
    }

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

            if ($result['status'] === 'found' && in_array($result['outcome'], PostSyncer::RESOLVED_OUTCOMES, true)) {
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

    /**
     * TN posts carry a fixed 90-day life: `expiration` is pinned at ORIGINAL
     * publication + 90 days, while `date` moves whenever the post is reposted
     * or edited. So a post whose `expiration - date` is anything other than 90
     * days has been mutated by TN since it was first published — the same
     * signal reclassifyLayer1Misses() relies on for out-of-window dates.
     */
    private const TN_POST_LIFETIME_DAYS = 90;

    /**
     * Slack allowed on the 90-day arithmetic before calling a post "mutated",
     * so clock skew or a rounded timestamp cannot masquerade as an edit.
     */
    private const TN_LIFETIME_TOLERANCE_SECONDS = 60;

    /**
     * Moves subject-only Layer 3/5 failures that TN itself caused out of the
     * failure lists and into an informational bucket.
     *
     * The email path records the subject TN put in the email at send time; the
     * API path records TN's title as it stands now. When TN edits a post's
     * title afterwards the two legitimately disagree, and no amount of care on
     * our side can make them agree — the same class of TN-side mutation as the
     * `date` bump handled in reclassifyLayer1Misses(). Confirmed live on
     * post_ids 47116974 and 47116976: TN's live title matched what the API
     * path had recorded exactly, and both posts' expiration arithmetic showed
     * they had been bumped since publication.
     *
     * Deliberately narrow, because a subject difference is also what a real
     * title-mangling bug (truncation, encoding) would look like:
     *   - only post_ids whose sole disagreement, on every layer that flagged
     *     them, is the subject (ParityComparer::subjectOnlyMismatchPostIds());
     *   - only when TN confirms the post was mutated after publication.
     * Anything else keeps failing, including a post whose `expiration` is null
     * (non-expiring types), where the check cannot conclude anything.
     */
    private function reclassifySubjectMismatches(array $layers, PostSyncer $apiSyncer): array
    {
        $editedOnTn = [];

        foreach ($layers['subjectOnlyMismatchPostIds'] as $postId) {
            $result = $apiSyncer->lookupPostById($postId);

            if ($result['status'] !== 'found' || !$this->wasEditedSincePublication($result)) {
                continue;
            }

            $editedOnTn[] = sprintf(
                'post_id=%s %s',
                $postId,
                $this->describeSubjectDiff($layers, $postId),
            );

            $layers['layer3Mismatches'] = $this->dropMismatchesFor($layers['layer3Mismatches'], $postId);
            $layers['layer5Mismatches'] = $this->dropMismatchesFor($layers['layer5Mismatches'], $postId);
        }

        $layers['subjectEditedOnTn'] = $editedOnTn;

        return $layers;
    }

    /**
     * @param  array{date: ?string, post: mixed}  $lookup  a lookupPostById() result
     */
    private function wasEditedSincePublication(array $lookup): bool
    {
        $expiration = $lookup['post']?->getExpiration();

        if ($expiration === null || empty($lookup['date'])) {
            return false;
        }

        $lifetime = $expiration->getTimestamp() - strtotime($lookup['date']);

        return abs($lifetime - (self::TN_POST_LIFETIME_DAYS * 86400)) > self::TN_LIFETIME_TOLERANCE_SECONDS;
    }

    /**
     * Pulls the email-vs-api subject text out of the Layer 5 line for this
     * post so the informational entry says what actually changed, rather than
     * just naming a post_id the reader then has to go and look up.
     */
    private function describeSubjectDiff(array $layers, string $postId): string
    {
        foreach ($layers['layer5Mismatches'] as $line) {
            if ($this->mismatchIsFor($line, $postId) && str_contains($line, 'subject differs:')) {
                return substr($line, strpos($line, 'subject differs:'));
            }
        }

        foreach ($layers['layer3Mismatches'] as $line) {
            if ($this->mismatchIsFor($line, $postId)) {
                return substr($line, strpos($line, 'subject:'));
            }
        }

        return 'subject differs (TN edited the title after emailing it)';
    }

    /**
     * @param  string[]  $mismatches
     * @return string[]
     */
    private function dropMismatchesFor(array $mismatches, string $postId): array
    {
        return array_values(array_filter(
            $mismatches,
            fn (string $line) => !$this->mismatchIsFor($line, $postId),
        ));
    }

    /**
     * Both layers' mismatch lines open with `post_id=<id>` followed by a space
     * or a colon; anchoring on that delimiter stops post_id=471169 matching
     * post_id=4711690.
     */
    private function mismatchIsFor(string $line, string $postId): bool
    {
        return (bool) preg_match('/^post_id=' . preg_quote($postId, '/') . '[\s:]/', $line);
    }

    private function printReport(array $layers): void
    {
        $this->line('');
        $this->line('── Parity summary ─────────────────────────────────────────────────────');
        $this->line('Layer 1 (coverage):        email=' . $layers['emailPostIdCount'] . ' api_covered=' . $layers['apiCoveredCount'] . ' missing=' . count($layers['layer1Missing']));
        $this->line('Layer 2 (extra, api-only): ' . count($layers['layer2Extra']));
        $this->line('Layer 3 (same-group):      overlap=' . $layers['overlapCount'] . ' mismatches=' . count($layers['layer3Mismatches']));
        $this->line('Layer 4 (divergence):      ' . count($layers['layer4Divergences']));
        $this->line(
            'Layer 5 (Loki entries):    compared=' . $layers['layer5Compared']
            . ' structure-only=' . $layers['layer5StructureOnly']
            . ' mismatches=' . count($layers['layer5Mismatches'])
            . ($layers['lokiEntriesSeen'] === 0 ? '  [NOT CHECKED — no Loki entries emitted; is freegle.loki.enabled set?]' : '')
        );
        if (isset($layers['layer1Deleted']) || isset($layers['layer1BumpedOutOfWindow']) || isset($layers['layer1ResolvedOutcome'])) {
            $this->line('Layer 1 (filtered out):    deleted=' . count($layers['layer1Deleted'] ?? []) . ' bumped_out_of_window=' . count($layers['layer1BumpedOutOfWindow'] ?? []) . ' resolved_outcome=' . count($layers['layer1ResolvedOutcome'] ?? []));
        }
        if (isset($layers['subjectEditedOnTn'])) {
            $this->line('Layer 3/5 (filtered out):  title_edited_on_tn=' . count($layers['subjectEditedOnTn']));
        }
        $this->line('API crossposts discarded:  ' . count($layers['apiCrosspostsDiscarded'] ?? []) . ' (TN per-group copies, identified by group_id; excluded from every count above — Freegle cross-posts via rippling)');
        if (($layers['emailUnknownGroupPostCount'] ?? 0) > 0) {
            $this->line(
                'Unknown groups (email):    ' . count($layers['emailUnknownGroups'])
                . ' group(s) missing from this database, swallowing ' . $layers['emailUnknownGroupPostCount'] . ' post(s)'
                . '  [' . implode(', ', array_keys($layers['emailUnknownGroups'])) . ']'
            );
        }
        $this->line($this->formatIngestionGainLine($layers));
        $this->line('');

        $layer1MissingLines = array_map(
            static fn (string $postId) => $layers['layer1Details'][$postId] ?? self::POST_ID_PREFIX . $postId,
            $layers['layer1Missing'],
        );
        $layer1DeletedLines    = array_map(static fn (string $postId) => self::POST_ID_PREFIX . $postId, $layers['layer1Deleted'] ?? []);
        $layer2ExtraLines      = array_map(static fn (string $postId) => self::POST_ID_PREFIX . $postId, $layers['layer2Extra']);
        $apiCrosspostLines     = array_map(static fn (string $postId) => self::POST_ID_PREFIX . $postId, $layers['apiCrosspostsDiscarded'] ?? []);

        $this->printSection('Layer 1 FAILURES — posts the email path processed but the API path never covered:', $layer1MissingLines, isFailure: true);
        $this->printSection('Layer 1 (informational) — deleted from TN after the email was sent:', $layer1DeletedLines);
        $this->printSection('Layer 1 (informational) — exists on TN but its date was bumped outside the query window (repost/edit):', $layers['layer1BumpedOutOfWindow'] ?? []);
        $this->printSection('Layer 1 (informational) — reached a resolved outcome (satisfied/withdrawn), never going to be posted to FD:', $layers['layer1ResolvedOutcome'] ?? []);
        $this->printSection('(informational) — API crossposts discarded (TN per-group copies, never ingested):', $apiCrosspostLines);
        $this->printSection('Layer 2 (informational) — posts the API path covered that the email path never saw:', $layer2ExtraLines);
        $this->printSection('Layer 3/5 (informational) — TN edited the post\'s title after emailing it, so the two subjects cannot agree:', $layers['subjectEditedOnTn'] ?? []);
        $this->printSection('Layer 3 FAILURES — same group on both paths, but content/outcome differs:', $layers['layer3Mismatches'], isFailure: true);
        $this->printSection('Layer 4 (informational) — overlapping posts with no meaningful same-group comparison:', $layers['layer4Divergences']);
        $this->printSection('Layer 5 FAILURES — the two paths reported different Loki entries for the same post:', $layers['layer5Mismatches'], isFailure: true);
        $this->printSection(
            'NOT COMPARED — the email path dropped these posts as "unknown group": the group is addressed by nameshort and does not exist in this database, so nothing was written to compare against. Restore/clone the groups before trusting this run:',
            $this->formatUnknownGroupLines($layers),
            isFailure: true,
        );
    }

    /**
     * @return string[] one "<nameshort>: N post(s) (post_id=…, …)" line per group
     */
    private function formatUnknownGroupLines(array $layers): array
    {
        $lines = [];
        foreach ($layers['emailUnknownGroups'] ?? [] as $nameshort => $postIds) {
            $shown = array_slice($postIds, 0, self::UNKNOWN_GROUP_POST_IDS_SHOWN);
            $lines[] = $nameshort . ': ' . count($postIds) . ' post(s) ('
                . implode(', ', array_map(static fn (string $id) => self::POST_ID_PREFIX . $id, $shown))
                . (count($postIds) > count($shown) ? ', …' : '') . ')';
        }
        return $lines;
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

    private function loadCsvText(bool $localTesting, bool $refreshCsv): ?string
    {
        if ($localTesting) {
            $path = base_path('tests/fixtures/tn_sync/fd_post_log.csv');
            if (!file_exists($path)) {
                $this->error("Fixture CSV not found: {$path}");
                return null;
            }
            return (string) file_get_contents($path);
        }

        $fetcher = new PostLogCsvFetcher();

        $this->line(
            (!$refreshCsv && $fetcher->isCached())
                ? "Using cached CSV: {$fetcher->cachePath()} (--refresh-csv to re-download)"
                : 'Downloading CSV (~15MB) from TN…'
        );

        $csvText = $fetcher->fetch($refreshCsv);

        if ($csvText === null) {
            $this->error('CSV download failed — see log for details.');
            return null;
        }

        return $csvText;
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
