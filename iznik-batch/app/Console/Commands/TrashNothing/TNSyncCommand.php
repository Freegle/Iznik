<?php

namespace App\Console\Commands\TrashNothing;

use App\Console\Concerns\PreventsOverlapping;
use App\Models\Rating;
use App\Models\User;
use App\Services\LokiService;
use App\Services\TrashNothing\Sync\PostSyncer;
use App\Services\TrashNothing\Sync\RatingsSyncer;
use App\Services\TrashNothing\Sync\UserChangesSyncer;
use App\Traits\GracefulShutdown;
use App\Traits\LogsBatchJob;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TNSyncCommand extends Command
{
    use GracefulShutdown;
    use LogsBatchJob;

    // TODO Finnbarr: remove this after testing is complete. The Laravel scheduler's withoutOverlapping will handle locking.
    use PreventsOverlapping;

    protected $signature = 'tn:sync
                            {--from= : Override sync start timestamp (ISO-8601)}
                            {--to= : Override sync end timestamp (ISO-8601)}
                            {--run-id= : Queue run identifier used to update background_tasks JSON completion state}
                            {--dry-run : Trace DB writes without executing them}
                            {--local-testing : Load API responses from local fixture files instead of hitting the live TN API}
                            {--full-duplicate-scan : Re-scan every Trash Nothing address rather than only those added since the last run}
                            {--report-duplicates : List duplicate Trash Nothing account candidates and exit, merging nothing}';

    protected $description = 'Sync data from TrashNothing, including user data updates, user ratings, posts/messages, and chat messages.';

    private const STALENESS_THRESHOLD_HOURS = 12;

    // Backward overlap applied to the stored sync date when building `date_min`
    // for every TN API request. Handles data written to the current second when
    // the boundary lands mid-second. Duplicate items in the overlap window are
    // dropped by each syncer's idempotency checks.
    private const SYNC_OVERLAP_SECONDS = 10;

    private bool $dryRun;

    /** Report duplicate candidates and merge nothing. Read-only; never moves the cursor. */
    private bool $reportOnly = false;

    private bool $localTesting;

    private string $apiKey;
    private string $apiBaseUrl;
    private string $dateFile;
    private LokiService $loki;

    public function __construct(LokiService $loki)
    {
        parent::__construct();
        $this->loki = $loki;
    }

    public function handle(): int
    {
        $this->registerShutdownHandlers();
        $this->dryRun = (bool) $this->option('dry-run');
        $this->localTesting = (bool) $this->option('local-testing');
        $this->reportOnly = (bool) $this->option('report-duplicates');

        // Read-only, so it wants neither the lock nor the API sync. Widening the
        // address filter exposes a backlog of real member accounts, and User::merge
        // deletes one of each pair, so the list gets read before anything acts on it.
        if ($this->reportOnly) {
            $this->mergeDuplicateTNUsers(true);

            return Command::SUCCESS;
        }
        $exitCode = Command::FAILURE;
        $errorMessage = null;

        if (!$this->acquireLock()) {
            $this->warn('TN sync is already running.');
            $this->markQueueRunCompleted(Command::SUCCESS, 'lock already held');
            return Command::SUCCESS;
        }

        $this->apiKey = (string) config('freegle.trashnothing.api_key', '');
        $this->apiBaseUrl = (string) config('freegle.trashnothing.api_base_url', '');
        $this->dateFile = (string) config('freegle.trashnothing.sync_date_file', '');

        try {
            $exitCode = $this->runWithLogging(function () {
                $this->info('Starting TN sync...');

                // When dry-run is used as a queue health-check from tn_sync.php, skip all real
                // work — API fetches and the duplicate-merge DB scan are both expensive and
                // serve no purpose when we're not going to write anything.
                if ($this->dryRun && !$this->localTesting) {
                    Log::info('TN-SYNC-TRACE [SKIP] dry-run queue health-check: no API calls or DB work');
                    return Command::SUCCESS;
                }

                $from = $this->resolveFromDate();
                $to = $this->resolveToDate();

                Log::info("TN-SYNC-TRACE [START] from={$from} to={$to}");

                $maxChangeDate = null;

                // Sync ratings.
                $ratingsSyncer = new RatingsSyncer($this->dryRun, $this->localTesting, $this->apiKey, $this->apiBaseUrl, $this->loki);
                [$ratingsProcessed, $ratingsMaxDate] = $ratingsSyncer->sync($from, $to);
                if ($ratingsMaxDate && (!$maxChangeDate || $ratingsMaxDate > $maxChangeDate)) {
                    $maxChangeDate = $ratingsMaxDate;
                }

                // Sync user changes.
                $userChangesSyncer = new UserChangesSyncer($this->dryRun, $this->localTesting, $this->apiKey, $this->apiBaseUrl, $this->loki);
                [$changesProcessed, $changesMaxDate] = $userChangesSyncer->sync($from, $to);
                if ($changesMaxDate && (!$maxChangeDate || $changesMaxDate > $maxChangeDate)) {
                    $maxChangeDate = $changesMaxDate;
                }

                // Merge duplicate TN users.
                $duplicatesMerged = $this->mergeDuplicateTNUsers((bool) $this->option('full-duplicate-scan'));

                // Sync posts. The TN API is the only way TN posts are ingested.
                $postSyncer = new PostSyncer($this->dryRun, $this->localTesting, $this->apiKey, $this->apiBaseUrl, $this->loki);
                [$postsProcessed, $postsMaxDate] = $postSyncer->sync($from, $to);
                if ($postsMaxDate && (!$maxChangeDate || $postsMaxDate > $maxChangeDate)) {
                    $maxChangeDate = $postsMaxDate;
                }

                // Store the max change date for next sync.
                if ($maxChangeDate) {
                    $this->storeSyncDate($maxChangeDate);
                } else {
                    Log::info('No change date to store - no data processed');
                }

                if ($ratingsProcessed === 0 && $changesProcessed === 0 && $duplicatesMerged === 0 && $postsProcessed === 0) {
                    $this->alertIfSyncStale();
                }

                $this->info("TN sync complete: {$ratingsProcessed} ratings, {$changesProcessed} user changes, {$duplicatesMerged} duplicates merged, {$postsProcessed} posts.");
                Log::info('TN sync complete', [
                    'ratings_processed' => $ratingsProcessed,
                    'changes_processed' => $changesProcessed,
                    'duplicates_merged' => $duplicatesMerged,
                    'posts_processed' => $postsProcessed,
                ]);

                Log::info("TN-SYNC-TRACE [END] ratings={$ratingsProcessed} changes={$changesProcessed} merges={$duplicatesMerged} posts={$postsProcessed} max_date=" . ($maxChangeDate ?? 'null'));

                return Command::SUCCESS;
            });

            return $exitCode;
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $this->error('TN sync failed: ' . $e->getMessage());
            Log::error('TN sync failed', ['error' => $e->getMessage()]);
            $exitCode = Command::FAILURE;
            return Command::FAILURE;
        } finally {
            $this->markQueueRunCompleted($exitCode, $errorMessage);
            $this->releaseLock();
        }
    }

    private function markQueueRunCompleted(int $exitCode, ?string $errorMessage = null): void
    {
        $runId = $this->option('run-id');

        if (!is_string($runId) || $runId === '') {
            return;
        }

        try {
            $task = DB::table('background_tasks')
                ->select('id', 'data')
                ->where('task_type', 'tn_sync_command')
                ->where('data->run_id', $runId)
                ->orderByDesc('id')
                ->first();

            if (!$task) {
                Log::warning('[QUEUE-WRITEBACK] background_tasks row not found for run_id', [
                    'run_id' => $runId,
                ]);
                return;
            }

            $data = json_decode((string) $task->data, true);

            if (!is_array($data)) {
                $data = [];
            }

            $data['tn_sync_finished'] = true;
            $data['tn_sync_status'] = $exitCode === Command::SUCCESS ? 'success' : 'failed';
            $data['tn_sync_finished_at'] = gmdate('Y-m-d\TH:i:s\Z');
            $data['tn_sync_exit_code'] = $exitCode;

            if ($errorMessage) {
                $data['tn_sync_error'] = substr($errorMessage, 0, 2000);
            }

            $encodedData = json_encode($data, JSON_UNESCAPED_SLASHES);

            if ($encodedData === false) {
                Log::warning('[QUEUE-WRITEBACK] failed to encode completion payload', [
                    'run_id' => $runId,
                ]);
                return;
            }

            DB::table('background_tasks')
                ->where('id', $task->id)
                ->update(['data' => $encodedData]);

            Log::info('[QUEUE-WRITEBACK] marked run complete', [
                'task_id' => $task->id,
                'run_id' => $runId,
                'status' => $data['tn_sync_status'],
                'exit_code' => $exitCode,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[QUEUE-WRITEBACK] exception writing completion payload', [
                'run_id' => $runId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function getSyncFromDate(): string
    {
        // Try local file first.
        if (file_exists($this->dateFile)) {
            $lastSyncDate = trim(file_get_contents($this->dateFile));
            if ($lastSyncDate && strtotime($lastSyncDate)) {
                Log::info("Using stored sync date from {$this->dateFile}: {$lastSyncDate}");
                return $lastSyncDate;
            }
        }

        // Fallback to max rating timestamp.
        $max = Rating::whereNotNull('tn_rating_id')
            ->max('timestamp');

        $from = $max ? gmdate('Y-m-d\TH:i:s\Z', strtotime($max)) : gmdate('Y-m-d\TH:i:s\Z', strtotime('-1 day'));
        Log::info("No stored sync date found, using max rating timestamp: {$from}");

        return $from;
    }

    private function resolveFromDate(): string
    {
        $override = $this->option('from');

        if (is_string($override) && $override !== '' && strtotime($override) !== false) {
            return $override;
        }

        $from = $this->getSyncFromDate();

        // Apply backward overlap so data recorded in the last few seconds of the
        // previous window is not missed when the boundary lands mid-second.
        // Each syncer's idempotency check drops items already processed.
        $ts = strtotime($from);
        if ($ts !== false) {
            return gmdate('Y-m-d\TH:i:s\Z', $ts - self::SYNC_OVERLAP_SECONDS);
        }

        return $from;
    }

    private function resolveToDate(): string
    {
        $override = $this->option('to');

        if (is_string($override) && $override !== '' && strtotime($override) !== false) {
            return $override;
        }

        return gmdate('Y-m-d\TH:i:s\Z');
    }

    private function storeSyncDate(string $date): void
    {
        Log::info("TN-SYNC-TRACE [WRITE] op=file-write path={$this->dateFile} set=date={$date}");

        if (@file_put_contents($this->dateFile, $date) !== false) {
            Log::info("Stored max change date to {$this->dateFile}: {$date}");
        } else {
            Log::error("Failed to store max change date to {$this->dateFile}");
            if (function_exists('\Sentry\captureMessage')) {
                \Sentry\captureMessage("Failed to store TN sync date to {$this->dateFile}");
            }
        }
    }

    /**
     * Determine the timestamp (unix epoch, UTC) of the last successful
     * TrashNothing operation, or null if there is no baseline to compare.
     */
    private function getLastSuccessfulSyncTime(): ?int
    {
        // The sync date file holds the max change date of the most recently
        // processed TN data. It only advances when real work is done.
        if ($this->dateFile && file_exists($this->dateFile)) {
            $stored = trim((string) @file_get_contents($this->dateFile));
            if ($stored !== '') {
                $ts = strtotime($stored);
                if ($ts !== false) {
                    return $ts;
                }
            }
        }

        // Fall back to the most recent TN rating timestamp in the DB.
        $max = Rating::whereNotNull('tn_rating_id')->max('timestamp');
        if ($max) {
            $ts = strtotime((string) $max);
            if ($ts !== false) {
                return $ts;
            }
        }

        return null;
    }

    /**
     * When a sync cycle did no work, alert to Sentry only if the last
     * successful TrashNothing operation is older than the staleness
     * threshold. Quiet periods (no new data) are normal and must not alert.
     */
    private function alertIfSyncStale(): void
    {
        $lastSuccess = $this->getLastSuccessfulSyncTime();

        if ($lastSuccess === null) {
            Log::info('TN sync did nothing; no prior successful TN operation on record, skipping staleness alert.');
            return;
        }

        $ageSeconds = time() - $lastSuccess;
        $ageHours = round($ageSeconds / 3600, 1);
        $thresholdSeconds = self::STALENESS_THRESHOLD_HOURS * 3600;

        if ($ageSeconds > $thresholdSeconds) {
            $message = sprintf(
                'TN sync stale: no successful TrashNothing operation for %s hours (threshold %d)',
                $ageHours,
                self::STALENESS_THRESHOLD_HOURS
            );
            Log::warning($message, [
                'last_success' => gmdate('Y-m-d\TH:i:s\Z', $lastSuccess),
                'age_hours' => $ageHours,
            ]);
            if (function_exists('\Sentry\captureMessage')) {
                \Sentry\captureMessage($message);
            }
        } else {
            Log::info('TN sync did nothing (last successful TN operation within threshold)', [
                'last_success' => gmdate('Y-m-d\TH:i:s\Z', $lastSuccess),
                'age_hours' => $ageHours,
            ]);
        }
    }

    /** Where the per-tick duplicate check remembers how far it has read. */
    /** The domain every Trash Nothing per-group address ends with. */
    private const TN_ADDRESS_SUFFIX = '@user.trashnothing.com';

    private const DUP_CURSOR_KEY = 'tn.dupscan_cursor';

    /** When the last whole-table duplicate re-scan finished. */
    private const DUP_FULL_AT_KEY = 'tn.dupscan_full_at';

    /**
     * How long the per-tick check may run before one tick does the whole table again.
     *
     * The full re-scan is what covers a duplicate created by re-pointing an existing row
     * rather than adding one, since that adds no new id for the per-tick check to see.
     * It happens on one ordinary tick rather than on a schedule of its own: a separate
     * scheduled entry would be a different command string, so it would not share the
     * per-minute run's overlap mutex and the two could run at once.
     */
    private const DUP_FULL_SCAN_HOURS = 24;

    /**
     * The Trash Nothing username inside a per-group address: `bibiana-g288@...` is
     * `bibiana`. The whole duplicate check turns on this being the member's identity,
     * so both passes have to derive it the same way.
     */
    private function tnUsernameFromAddress(string $email): string
    {
        return preg_replace('/-g\d+@user\.trashnothing\.com$/i', '', $email);
    }

    /**
     * Merge Trash Nothing accounts that are really the same person.
     *
     * This ran on every tick, and a tick is every minute. Each run streamed all ~400,000
     * Trash Nothing addresses out of users_emails and grouped them in PHP, about five
     * seconds of database time and twenty gigabytes a day off the wire, to find a number
     * of duplicates measured at roughly zero a day.
     *
     * A duplicate can only come into existence when a users_emails row is INSERTED, so
     * the per-tick check now looks only at rows added since the last one, and probes for
     * siblings of each by an indexed prefix on the address. Typically that is no rows at
     * all and no probes.
     *
     * The one thing that escapes it is an UPDATE re-pointing an existing row, which adds
     * no new id. $full is the answer to that: the nightly run does today's whole scan,
     * and it is a permanent fixture rather than a transitional one - it is the only
     * reason narrowing the per-tick check is safe. Worst case a duplicate is merged a day
     * late, against an event rate of about zero a day, and User::merge re-checks live
     * state anyway so a late merge cannot corrupt anything.
     */
    private function mergeDuplicateTNUsers(bool $full = false): int
    {
        // See whereTNAddress() for what the address filter matches on and why it is
        // not the backwards column.

        // Stream rows with a query-builder cursor and group as we go. Hydrating the
        // full ~400k-row result set as Eloquent models exhausts the 512M memory_limit;
        // raw rows are an order of magnitude lighter and we only need userid + email.
        // Group by TN username in PHP — avoids slow REGEXP_REPLACE GROUP BY in MySQL.
        // Read the watermark BEFORE any work, so a row arriving mid-run is left for the
        // next run rather than stepped over.
        $highWater = (int) DB::table('users_emails')->max('id');
        $cursor = ($full || $this->fullDuplicateScanDue()) ? null : $this->readDupCursor();
        $didFullScan = $cursor === null;

        $groups = [];

        if ($cursor === null) {
            // Full pass: the nightly reconciliation, and whatever runs first after a
            // deploy so there is a watermark to work from.
            //
            // Hold ONE integer per username, not a list. Matching on the address takes
            // this scan from 451k rows to 2.22M across ~1.03M accounts, and
            // an array per username at that size does not fit the 512M memory_limit -
            // the same limit the Eloquent hydration note below was written about. A
            // username only earns a list once a SECOND distinct account appears on it,
            // which on production is 96 of them.
            $firstSeen = [];

            foreach (
                $this->whereTNAddress(DB::table('users_emails'))
                    ->select('userid', 'email')
                    ->orderBy('id')
                    ->cursor() as $row
            ) {
                $username = $this->tnUsernameFromAddress($row->email);
                $userid = (int) $row->userid;

                if (!isset($firstSeen[$username])) {
                    $firstSeen[$username] = $userid;

                    continue;
                }

                if ($firstSeen[$username] === $userid) {
                    continue;
                }

                // Ordered by id, so the first account seen is the lowest
                // users_emails.id and stays at the head of the list: the one kept.
                if (!isset($groups[$username])) {
                    $groups[$username] = [$firstSeen[$username]];
                }
                if (!in_array($userid, $groups[$username], true)) {
                    $groups[$username][] = $userid;
                }
            }

            unset($firstSeen);
        } else {
            // Only addresses added since last time. For each, collect everyone sharing
            // its Trash Nothing username. The address is indexed, so a prefix LIKE finds
            // the candidates cheaply - but it only narrows, it does not decide: see the
            // exact-username test below.
            $newUsernames = [];
            foreach (
                $this->whereTNAddress(DB::table('users_emails'))
                    ->select('email')
                    ->where('id', '>', $cursor)
                    ->where('id', '<=', $highWater)
                    ->cursor() as $row
            ) {
                $newUsernames[$this->tnUsernameFromAddress($row->email)] = true;
            }

            foreach (array_keys($newUsernames) as $username) {
                foreach (
                    DB::table('users_emails')
                        ->select('userid', 'email')
                        // Escape the escape character first, then the wildcards - doing it
                        // the other way round would re-escape the backslashes just added.
                        ->where('email', 'LIKE', str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $username) . '-g%@user.trashnothing.com')
                        // Same ordering as the full pass above. Whichever account comes
                        // first is the one kept when duplicates are merged, and it takes
                        // its own name over the other's, so the order decides what the
                        // member ends up called. Without this the answer would be
                        // whatever order the address index happened to return.
                        ->orderBy('id')
                        ->cursor() as $row
                ) {
                    // '-g%' runs on past the end of the username, so 'bibiana-g%' also
                    // matches 'bibiana-gomes-g4840@...' - a different member with a
                    // longer name. Group on an exact username, the same test the full
                    // pass applies. Without this two unrelated members are merged into
                    // one account and one of them is deleted: on 2026-09-13 TN user
                    // 8893880 went into TN user 8996910's account, and 8893880's reply
                    // to an OFFER was then answered to 8996910.
                    if ($this->tnUsernameFromAddress($row->email) !== $username) {
                        continue;
                    }

                    $groups[$username][] = (int) $row->userid;
                }
            }
        }

        if (empty($groups)) {
            if ($this->reportOnly) {
                $this->line('Trash Nothing duplicate-account candidates: 0');
            } else {
                $this->writeDupCursor($highWater, $didFullScan);
            }

            return 0;
        }

        $duplicateGroups = array_filter($groups, fn($ids) => count(array_unique($ids)) > 1);

        if (empty($duplicateGroups)) {
            if ($this->reportOnly) {
                $this->line('Trash Nothing duplicate-account candidates: 0');
            } else {
                $this->writeDupCursor($highWater, $didFullScan);
            }

            return 0;
        }

        Log::info('Found ' . count($duplicateGroups) . ' duplicate TN users');
        Log::info("TN-SYNC-TRACE [DUP-SCAN] count=" . count($duplicateGroups));

        if ($this->reportOnly) {
            $this->reportDuplicateGroups($duplicateGroups);

            // No cursor write: reporting leaves the next real run seeing exactly what
            // it would have seen.
            return 0;
        }

        $merged = 0;

        foreach ($duplicateGroups as $username => $userIds) {
            $uniqueIds = array_values(array_unique($userIds));
            Log::info('Found ' . count($uniqueIds) . " users for {$username}");

            $mergeTo = $uniqueIds[0];

            foreach (array_slice($uniqueIds, 1) as $userId) {
                Log::info("Merging {$userId} into {$mergeTo}");
                Log::info("TN-SYNC-TRACE [MERGE] from={$userId} into={$mergeTo}");
                User::merge($mergeTo, $userId, "Duplicate TN user created accidentally", dryRun: $this->dryRun);
                $this->loki->logEvent('tn-sync', 'user-merge', [
                    'merge_to' => $mergeTo,
                    'merge_from' => $userId,
                ]);
                $merged++;
            }
        }

        // Only once the merges are done. A crash before this point leaves the watermark
        // where it was, so the next run re-reads the same rows rather than stepping over
        // a duplicate it never got to.
        $this->writeDupCursor($highWater, $didFullScan);

        return $merged;
    }

    /**
     * Whether to look at the addresses the old filter could not see.
     *
     * Default off. What it exposes is ~96 pairs of live member accounts, and merging
     * a pair deletes one of them and re-points their mail, which cannot be undone.
     * Reporting always sees them; merging waits until someone has read the report and
     * set FREEGLE_TN_MERGE_LEGACY_DUPLICATES. Duplicates created from now on are
     * unaffected either way - those arrive as new rows and the per-tick check has
     * always seen them.
     */
    private function widenDuplicateScan(): bool
    {
        return $this->reportOnly
            || (bool) config('freegle.trashnothing.merge_legacy_duplicates', false);
    }

    /**
     * Narrow a users_emails query to Trash Nothing addresses.
     *
     * Once widened this tests the ADDRESS, because no test on backwards can be
     * complete. That column holds three different things for a TN address:
     * REVERSE(email) (450,846 rows), REVERSE(canon) - canon strips the -gNNNN suffix
     * AND the dots in the domain, giving 'mocgnihtonhsartresu@...' (1,752,575 rows),
     * and NULL (13,772 rows). 5e2a90450 filtered on the first form alone, which is
     * how the check came to read 20% of the table while reporting "roughly zero
     * duplicates a day"; no set of prefixes reaches the NULLs at all.
     *
     * It was swapped onto backwards for speed, but EXPLAIN on production shows the
     * optimiser never picks that index - every form matches far too much of the table
     * - so both filters are the same full scan and the swap bought nothing. Measured
     * on production 2026-09-19: backwards 3.3s finding 94 of the 96 split usernames,
     * address 5.1s finding all 96. The real speedup in 5e2a90450 came from moving
     * REGEXP_REPLACE and GROUP BY out of MySQL, which stays.
     *
     * The per-tick pass pays none of this: its id range is on the primary key and
     * does the narrowing before the address test is reached.
     *
     * See .claude/rules/mail-and-data.md.
     */
    private function whereTNAddress(Builder $query): Builder
    {
        if (!$this->widenDuplicateScan()) {
            // Gate closed: exactly the filter that ships today, incompleteness included.
            return $query->where('backwards', 'LIKE', strrev(self::TN_ADDRESS_SUFFIX) . '%');
        }

        return $query->where('email', 'LIKE', '%' . self::TN_ADDRESS_SUFFIX);
    }

    /**
     * Print the duplicate candidates for review and merge nothing.
     *
     * Pairs whose accounts carry two DIFFERENT tnuserids are called out: that is a
     * member who re-registered on Trash Nothing, or a username released and retaken
     * by somebody else, and only a person can tell which.
     *
     * @param  array<string, list<int>>  $duplicateGroups
     */
    private function reportDuplicateGroups(array $duplicateGroups): void
    {
        $this->line('Trash Nothing duplicate-account candidates: ' . count($duplicateGroups));

        foreach ($duplicateGroups as $username => $userIds) {
            $tnuserids = [];
            $parts = [];

            foreach (array_values(array_unique($userIds)) as $id) {
                $tnuserid = DB::table('users')->where('id', $id)->value('tnuserid');
                $messages = DB::table('messages')->where('fromuser', $id)->count();
                if ($tnuserid) {
                    $tnuserids[] = $tnuserid;
                }
                $parts[] = $id . ' (tnuserid=' . ($tnuserid ?: '-') . ', messages=' . $messages . ')';
            }

            $review = count(array_unique($tnuserids)) > 1 ? '  <== REVIEW: two tnuserids' : '';
            $this->line('  ' . $username . ': ' . implode('  ', $parts) . $review);
        }
    }

    /** Is it time for one tick to re-scan the whole table? */
    private function fullDuplicateScanDue(): bool
    {
        $last = DB::table('config')->where('key', self::DUP_FULL_AT_KEY)->value('value');

        if (!$last) {
            return true;
        }

        return strtotime($last) < strtotime('-' . self::DUP_FULL_SCAN_HOURS . ' hours');
    }

    /** How far the per-tick duplicate check has read, or null if it has never run. */
    private function readDupCursor(): ?int
    {
        $value = DB::table('config')->where('key', self::DUP_CURSOR_KEY)->value('value');

        return $value === null || $value === '' ? null : (int) $value;
    }

    private function writeDupCursor(int $highWater, bool $wasFull = false): void
    {
        if ($this->dryRun) {
            return;
        }

        $rows = [['key' => self::DUP_CURSOR_KEY, 'value' => (string) $highWater]];

        if ($wasFull) {
            $rows[] = ['key' => self::DUP_FULL_AT_KEY, 'value' => now()->toDateTimeString()];
        }

        DB::table('config')->upsert($rows, ['key'], ['value']);
    }
}
