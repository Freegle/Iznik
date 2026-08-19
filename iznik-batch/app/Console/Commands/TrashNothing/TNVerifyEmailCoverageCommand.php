<?php

namespace App\Console\Commands\TrashNothing;

use App\Services\LokiService;
use App\Services\Mail\Incoming\IncomingArchiveReader;
use App\Services\Mail\Incoming\MailParserService;
use App\Services\TrashNothing\Sync\PostSyncer;
use App\Services\TrashNothing\Verify\ArchiveInventoryService;
use App\Services\TrashNothing\Verify\CoverageVerifier;
use App\Services\TrashNothing\Verify\TnEmailRoutingGate;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Verifies that every TN post which arrived by email was also ingested via the
 * TN API, and optionally backfills the ones that weren't.
 *
 * The post-cutover successor to tn:parity-check. Once the email path stops
 * routing, tn:parity-check's Layers 3 and 5 have nothing to compare — both
 * compare what each path WROTE, and only one path writes now. What survives is
 * Layer 1, coverage, which is what this command does: the incoming email
 * archive becomes an inventory of what TN sent us, and the check is whether
 * each of those post ids reached `messages`.
 *
 * See plans/tn-api-post-ingestion.md section S. The subtlety is all in
 * CoverageVerifier — several classes of post are absent by design, crossposts
 * above all, and calling them misses would bury the real signal.
 *
 * Runs a long way behind real time (default 8h) so TN's own indexing lag and
 * the sync's own latency are never mistaken for a gap. The upper bound is the
 * archive's 48h retention (mail:cleanup-archive).
 */
class TNVerifyEmailCoverageCommand extends Command
{
    protected $signature = 'tn:verify-email-coverage
                            {--from= : Explicit window start (ISO-8601 UTC). Overrides --lag-hours/--window-hours}
                            {--to= : Explicit window end (ISO-8601 UTC)}
                            {--lag-hours= : How far behind real time to run (default: config)}
                            {--window-hours= : Window length (default: config)}
                            {--auto-ingest : Backfill genuine misses regardless of the config default}
                            {--report-only : Never backfill, whatever the config says}
                            {--archive-dir= : Read a different archive directory (testing)}
                            {--force : Run even though the email path is still routing TN posts}';

    protected $description = 'Check TN posts that arrived by email were ingested via the API, and backfill any that were not.';

    /**
     * Marks a post id we have already backfilled, so that if it comes back as a
     * genuine miss on a later run we know ingestion is failing rather than
     * lagging (section S.5 rail 6). Cache rather than a table: it is a
     * short-lived hint, not a record, and losing it on a flush only costs one
     * duplicate backfill attempt — which GroupPostIngestionService's own
     * tnpostid check makes a no-op anyway.
     */
    private const BACKFILL_CACHE_PREFIX = 'tn-verify:backfilled:';

    private const BACKFILL_CACHE_TTL_DAYS = 7;

    public function handle(
        ArchiveInventoryService $inventoryService,
        CoverageVerifier $verifier,
        LokiService $loki,
    ): int {
        if (! $this->guardEmailPathIsOff()) {
            return Command::FAILURE;
        }

        [$from, $to] = $this->resolveWindow();
        $this->line('Window: ' . $from->toIso8601String() . ' .. ' . $to->toIso8601String());

        $inventory = $this->collectInventory($inventoryService, $from, $to);
        $stats     = $inventory['stats'];
        $posts     = $inventory['posts'];

        $this->line(sprintf(
            'Archive: %d file(s) scanned, %d unreadable, %d TN post email(s), %d duplicate delivery(ies).',
            $stats['files_scanned'],
            $stats['files_unreadable'],
            $stats['tn_posts'],
            $stats['duplicates'],
        ));

        // A missing archive is a broken host, not a quiet window. Passing green
        // here would be the exact failure this command exists to prevent: a
        // verifier that reports "all clear" because it cannot see anything.
        if (! $stats['archive_present']) {
            $this->error('Incoming email archive directory does not exist — cannot verify coverage. Check the archive path on this host.');
            $this->logRun($loki, $from, $to, $stats, null, null, breach: true);

            return Command::FAILURE;
        }

        if ($posts === []) {
            $this->info('No TN post emails in this window — nothing to verify.');
            $this->logRun($loki, $from, $to, $stats, null, null);

            return Command::SUCCESS;
        }

        $syncer = $this->buildSyncer($loki);
        $result = $verifier->verify($posts, $syncer, $from, $to);

        $backfill = $this->backfill($result[CoverageVerifier::GENUINE], $syncer);

        $this->printReport($result, $backfill);
        $this->logRun($loki, $from, $to, $stats, $result, $backfill);

        return $this->exitCode($backfill);
    }

    /**
     * Coverage is only meaningful once the email path has stopped writing.
     *
     * Both paths stamp messages.tnpostid, so while the email path is still
     * routing, a "covered" post proves nothing about the API path — the email
     * path may have created that row. Running anyway would report a clean bill
     * of health that means nothing, which is worse than not running.
     *
     * FREEGLE_TN_INGEST_POSTS_VIA_API is the single cutover switch: on, the API
     * path ingests and TnEmailRoutingGate stops the email path routing TN posts,
     * which is exactly the condition this check needs.
     */
    private function guardEmailPathIsOff(): bool
    {
        if (config('freegle.trashnothing.ingest_posts_via_api', false)) {
            return true;
        }

        if ($this->option('force')) {
            $this->warn('--force: the email path is still routing TN posts, so "covered" does not prove the API path ingested anything. Results are indicative only.');

            return true;
        }

        $this->error('Refusing to run: FREEGLE_TN_INGEST_POSTS_VIA_API is off, so the email path is still routing TN posts and creating messages with tnpostid set, and coverage cannot be attributed to the API path. Use --force to run anyway.');

        return false;
    }

    /**
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function resolveWindow(): array
    {
        $config = config('freegle.trashnothing.verify_coverage');

        if ($this->option('from')) {
            $from = CarbonImmutable::parse((string) $this->option('from'), 'UTC');

            if ($this->option('to')) {
                return [$from, CarbonImmutable::parse((string) $this->option('to'), 'UTC')];
            }

            return [$from, $from->addHours((int) ($this->option('window-hours') ?: $config['window_hours']))];
        }

        $lagHours    = (int) ($this->option('lag-hours') ?: $config['lag_hours']);
        $windowHours = (int) ($this->option('window-hours') ?: $config['window_hours']);

        $to = CarbonImmutable::now('UTC')->subHours($lagHours);

        // The overlap extends the window backwards only. Re-checking a post
        // that was already covered costs one indexed lookup and nothing else,
        // whereas losing one on a boundary costs a false alert.
        $from = $to->subHours($windowHours)->subMinutes((int) $config['overlap_minutes']);

        return [$from, $to];
    }

    private function collectInventory(ArchiveInventoryService $service, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($this->option('archive-dir')) {
            $service = new ArchiveInventoryService(
                new IncomingArchiveReader(
                    app(MailParserService::class),
                    (string) $this->option('archive-dir'),
                ),
                app(TnEmailRoutingGate::class),
            );
        }

        return $service->collect($from, $to);
    }

    /**
     * makeWith() rather than `new` so a test can bind a double for
     * PostSyncer::class — the backfill rails below decide whether to write
     * member-visible data, which is not something to leave untested.
     * Unbound, this constructs exactly as `new` would.
     */
    private function buildSyncer(LokiService $loki): PostSyncer
    {
        return app()->makeWith(PostSyncer::class, [
            'dryRun'       => false,
            'localTesting' => false,
            'apiKey'       => (string) config('freegle.trashnothing.api_key', ''),
            'apiBaseUrl'   => (string) config('freegle.trashnothing.api_base_url', ''),
            'loki'         => $loki,
        ]);
    }

    /**
     * Apply the section S.5 rails and, if anything survives them, ingest.
     *
     * @param  array<string, array{post_id: string, subject: string|null, timestamp: string, date: string|null, envelope_to: string, post: mixed}>  $genuine
     * @return array{enabled: bool, ingested: string[], failed: string[], skipped_stale: string[], repeats: string[], capped: bool, cap: int}
     */
    private function backfill(array $genuine, PostSyncer $syncer): array
    {
        $config = config('freegle.trashnothing.verify_coverage');
        $cap    = (int) $config['auto_ingest_max'];

        $out = [
            'enabled'       => $this->autoIngestEnabled(),
            'ingested'      => [],
            'failed'        => [],
            'skipped_stale' => [],
            'repeats'       => [],
            'capped'        => false,
            'cap'           => $cap,
        ];

        // Rail 6 first, and unconditionally — a post we already backfilled that
        // is STILL missing means ingestion is failing, not lagging. Re-ingesting
        // it would just loop; it needs a human.
        $candidates = [];
        foreach ($genuine as $postId => $entry) {
            if (Cache::has(self::BACKFILL_CACHE_PREFIX . $postId)) {
                $out['repeats'][] = $postId;
                continue;
            }
            $candidates[$postId] = $entry;
        }

        if (! $out['enabled'] || $candidates === []) {
            return $out;
        }

        // Rail 5: a post far older than the window is more likely a data
        // problem than a real miss.
        $maxAge = CarbonImmutable::now('UTC')->subHours((int) $config['max_age_hours']);
        foreach ($candidates as $postId => $entry) {
            if ($this->isStale($entry, $maxAge)) {
                $out['skipped_stale'][] = $postId;
                unset($candidates[$postId]);
            }
        }

        // Rail 4: a mass miss is a broken sync, not a backfill job.
        if (count($candidates) > $cap) {
            $out['capped'] = true;

            return $out;
        }

        foreach ($candidates as $postId => $entry) {
            if ($entry['post'] === null) {
                $out['failed'][] = $postId;
                continue;
            }

            try {
                $syncer->ingestFetchedPost($entry['post']);
                Cache::put(self::BACKFILL_CACHE_PREFIX . $postId, true, now()->addDays(self::BACKFILL_CACHE_TTL_DAYS));
                $out['ingested'][] = $postId;
            } catch (\Throwable $e) {
                $this->error("Backfill failed for post {$postId}: " . $e->getMessage());
                $out['failed'][] = $postId;
            }
        }

        return $out;
    }

    private function autoIngestEnabled(): bool
    {
        if ($this->option('report-only')) {
            return false;
        }

        if ($this->option('auto-ingest')) {
            return true;
        }

        return (bool) config('freegle.trashnothing.verify_coverage.auto_ingest', false);
    }

    private function isStale(array $entry, CarbonImmutable $maxAge): bool
    {
        // The API's own date is authoritative; the email arrival time is the
        // fallback for a post whose date TN didn't return.
        $raw = $entry['date'] ?? $entry['timestamp'];

        try {
            return CarbonImmutable::parse($raw, 'UTC')->lt($maxAge);
        } catch (\Throwable) {
            return false;
        }
    }

    private function printReport(array $result, array $backfill): void
    {
        $counts = $result['counts'];

        $this->line('');
        $this->line('Coverage:');
        $this->line(sprintf('  covered             %d', $counts[CoverageVerifier::COVERED]));
        $this->line(sprintf('  crosspost copies    %d  (expected — API ingests only the source post)', $counts[CoverageVerifier::CROSSPOST]));
        $this->line(sprintf('  unplaceable         %d  (expected — outside every group boundary)', $counts[CoverageVerifier::UNPLACEABLE]));
        $this->line(sprintf('  deleted on TN       %d  (expected)', $counts[CoverageVerifier::DELETED]));
        $this->line(sprintf('  resolved outcome    %d  (expected)', $counts[CoverageVerifier::RESOLVED]));
        $this->line(sprintf('  date bumped         %d  (expected)', $counts[CoverageVerifier::BUMPED]));
        $this->line(sprintf('  TN lookup failed    %d  (undetermined)', $counts[CoverageVerifier::LOOKUP_ERROR]));
        $this->line(sprintf('  GENUINE MISSES      %d', $counts[CoverageVerifier::GENUINE]));
        $this->line(sprintf('  (%d single-post API lookup(s))', $result['api_lookups']));

        foreach ($result[CoverageVerifier::GENUINE] as $postId => $entry) {
            $this->line(sprintf('    miss post_id=%s date=%s to=%s subject=%s', $postId, $entry['date'] ?? '?', $entry['envelope_to'], (string) $entry['subject']));
        }

        $this->line('');
        $this->line('Backfill: ' . ($backfill['enabled'] ? 'enabled' : 'REPORT-ONLY'));

        if ($backfill['capped']) {
            $this->error(sprintf('  CAPPED: more than %d genuine misses — ingested nothing. This is a broken sync, not a backfill job.', $backfill['cap']));
        }

        $this->line(sprintf('  ingested            %d', count($backfill['ingested'])));
        $this->line(sprintf('  failed              %d', count($backfill['failed'])));
        $this->line(sprintf('  skipped (too old)   %d', count($backfill['skipped_stale'])));

        if ($backfill['repeats'] !== []) {
            $this->error(sprintf('  STILL MISSING AFTER AN EARLIER BACKFILL: %s', implode(', ', $backfill['repeats'])));
        }
    }

    private function logRun(
        LokiService $loki,
        CarbonImmutable $from,
        CarbonImmutable $to,
        array $stats,
        ?array $result,
        ?array $backfill,
        bool $breach = false,
    ): void {
        $context = [
            'from'             => $from->toIso8601String(),
            'to'               => $to->toIso8601String(),
            'archive_present'  => $stats['archive_present'],
            'files_scanned'    => $stats['files_scanned'],
            'files_unreadable' => $stats['files_unreadable'],
            'tn_posts'         => $stats['tn_posts'],
        ];

        if ($result !== null) {
            $context = array_merge($context, [
                'covered'      => $result['counts'][CoverageVerifier::COVERED],
                'crosspost'    => $result['counts'][CoverageVerifier::CROSSPOST],
                'unplaceable'  => $result['counts'][CoverageVerifier::UNPLACEABLE],
                'deleted'      => $result['counts'][CoverageVerifier::DELETED],
                'resolved'     => $result['counts'][CoverageVerifier::RESOLVED],
                'bumped'       => $result['counts'][CoverageVerifier::BUMPED],
                'lookup_error' => $result['counts'][CoverageVerifier::LOOKUP_ERROR],
                'genuine'      => $result['counts'][CoverageVerifier::GENUINE],
                'api_lookups'  => $result['api_lookups'],
                'miss_ids'     => implode(',', array_keys($result[CoverageVerifier::GENUINE])),
            ]);
        }

        if ($backfill !== null) {
            $context = array_merge($context, [
                'backfill_enabled' => $backfill['enabled'],
                'backfilled'       => count($backfill['ingested']),
                'backfill_failed'  => count($backfill['failed']),
                'backfill_stale'   => count($backfill['skipped_stale']),
                'backfill_capped'  => $backfill['capped'],
                'repeat_misses'    => count($backfill['repeats']),
            ]);
        }

        // Run-level, so it belongs on the batch stream — the routed stream
        // carries one entry per post and nothing else (section I). Backfilled
        // posts emit their own routed entry from PostSyncer, tagged backfill.
        $loki->logBatchJob(
            'tn:verify-email-coverage',
            $breach || $this->isBreach($backfill) ? 'failed' : 'completed',
            $context,
        );
    }

    /**
     * Which outcomes actually constitute a failure.
     *
     * Deliberately NOT "any genuine miss". Section Q documents a persistent gap
     * between TN's partner email feed and their public API — posts that were
     * emailed but never appear in the API at all — so a small trickle of misses
     * is the expected steady state, and failing on it would train everyone to
     * ignore this command. What is genuinely wrong is a post we already tried to
     * backfill that is still missing (ingestion is broken), or so many misses at
     * once that backfilling was refused (the sync is broken). Volume-based
     * alerting on the Loki counts covers the space in between.
     */
    private function isBreach(?array $backfill): bool
    {
        if ($backfill === null) {
            return false;
        }

        return $backfill['capped'] || $backfill['repeats'] !== [] || $backfill['failed'] !== [];
    }

    private function exitCode(array $backfill): int
    {
        if ($this->isBreach($backfill)) {
            $this->error('FAIL: see the backfill section above.');

            return Command::FAILURE;
        }

        $this->info('PASS.');

        return Command::SUCCESS;
    }
}
