<?php

namespace App\Console\Commands\TrashNothing;

use App\Console\Concerns\PreventsOverlapping;
use App\Models\Location;
use App\Models\Rating;
use App\Models\User;
use App\Models\UserAboutMe;
use App\Models\UserEmail;
use App\Models\UserReplyTime;
use App\Services\LokiService;
use App\Traits\GracefulShutdown;
use App\Traits\LogsBatchJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TNSyncCommand extends Command
{
    use GracefulShutdown;
    use PreventsOverlapping;
    use LogsBatchJob;

    protected $signature = 'tn:sync
                            {--from= : Override sync start timestamp (ISO-8601)}
                            {--to= : Override sync end timestamp (ISO-8601)}';

    protected $description = 'Sync data from TrashNothing, including user data updates, user ratings, posts/messages, and chat messages.';

    private const PAGE_SIZE = 100;

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

        if (!$this->acquireLock()) {
            $this->warn('TN sync is already running.');
            return Command::SUCCESS;
        }

        $this->apiKey = config('freegle.trashnothing.api_key');
        $this->apiBaseUrl = config('freegle.trashnothing.api_base_url');
        $this->dateFile = config('freegle.trashnothing.sync_date_file');

        try {
            return $this->runWithLogging(function () {
                $this->info('Starting TN sync...');

                $from = $this->resolveFromDate();
                $to = $this->resolveToDate();

                Log::info("TN-SYNC-TRACE [START] from={$from} to={$to}");

                $maxChangeDate = null;

                // Sync ratings.
                [$ratingsProcessed, $ratingsMaxDate] = $this->syncRatings($from, $to);
                if ($ratingsMaxDate && (!$maxChangeDate || $ratingsMaxDate > $maxChangeDate)) {
                    $maxChangeDate = $ratingsMaxDate;
                }

                // Sync user changes.
                [$changesProcessed, $changesMaxDate] = $this->syncUserChanges($from, $to);
                if ($changesMaxDate && (!$maxChangeDate || $changesMaxDate > $maxChangeDate)) {
                    $maxChangeDate = $changesMaxDate;
                }

                // Merge duplicate TN users.
                $duplicatesMerged = $this->mergeDuplicateTNUsers();

                // Store the max change date for next sync.
                if ($maxChangeDate) {
                    $this->storeSyncDate($maxChangeDate);
                } else {
                    Log::info('No change date to store - no data processed');
                }

                if ($ratingsProcessed === 0 && $changesProcessed === 0 && $duplicatesMerged === 0) {
                    Log::warning('TN sync did nothing');
                    if (function_exists('\Sentry\captureMessage')) {
                        \Sentry\captureMessage('TN sync did nothing');
                    }
                }

                $this->info("TN sync complete: {$ratingsProcessed} ratings, {$changesProcessed} user changes, {$duplicatesMerged} duplicates merged.");
                Log::info('TN sync complete', [
                    'ratings_processed' => $ratingsProcessed,
                    'changes_processed' => $changesProcessed,
                    'duplicates_merged' => $duplicatesMerged,
                ]);

                Log::info("TN-SYNC-TRACE [END] ratings={$ratingsProcessed} changes={$changesProcessed} merges={$duplicatesMerged} max_date=" . ($maxChangeDate ?? 'null'));

                return Command::SUCCESS;
            });
        } catch (\Exception $e) {
            $this->error('TN sync failed: ' . $e->getMessage());
            Log::error('TN sync failed', ['error' => $e->getMessage()]);
            return Command::FAILURE;
        } finally {
            $this->releaseLock();
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

        $from = $max ? gmdate('c', strtotime($max)) : gmdate('c', strtotime('-1 day'));
        Log::info("No stored sync date found, using max rating timestamp: {$from}");

        return $from;
    }

    private function resolveFromDate(): string
    {
        $override = $this->option('from');

        if (is_string($override) && $override !== '' && strtotime($override) !== false) {
            return $override;
        }

        return $this->getSyncFromDate();
    }

    private function resolveToDate(): string
    {
        $override = $this->option('to');

        if (is_string($override) && $override !== '' && strtotime($override) !== false) {
            return $override;
        }

        return gmdate('c');
    }

    private function storeSyncDate(string $date): void
    {
        Log::info("TN-SYNC-TRACE [WRITE] op=file-write path={$this->dateFile} set=date={$date}");

        // TRACE: commented out for port testing
        // if (file_put_contents($this->dateFile, $date) !== false) {
        //     Log::info("Stored max change date to {$this->dateFile}: {$date}");
        // } else {
        //     Log::error("Failed to store max change date to {$this->dateFile}");
        //     if (function_exists('\Sentry\captureMessage')) {
        //         \Sentry\captureMessage("Failed to store TN sync date to {$this->dateFile}");
        //     }
        // }
    }

    /**
     * @return array [count, maxDate]
     */
    private function syncRatings(string $from, string $to): array
    {
        $page = 1;
        $count = 0;
        $maxDate = NULL;
        $fixturesDir = base_path('../iznik-server/test/integration/tn_sync/fixtures');

        do {
            // $response = Http::get("{$this->apiBaseUrl}/ratings", [
            //     'key' => $this->apiKey,
            //     'page' => $page,
            //     'per_page' => self::PAGE_SIZE,
            //     'date_min' => $from,
            //     'date_max' => $to,
            // ]);
            //
            // if (!$response->successful()) {
            //     Log::error("TN sync: ratings API failed on page {$page}", [
            //         'status' => $response->status(),
            //     ]);
            //     break;
            // }
            //
            // $ratings = $response->json('ratings', []);
            $ratingsFile = $fixturesDir . "/ratings_page_{$page}.json";
            if (file_exists($ratingsFile)) {
                $ratingsPayload = json_decode(file_get_contents($ratingsFile), true);
                $ratings = is_array($ratingsPayload) ? ($ratingsPayload['ratings'] ?? []) : [];
            } else {
                Log::info("TN-SYNC-TRACE [RATINGS-PAGE] missing fixture file={$ratingsFile}");
                $ratings = [];
            }
            $page++;

            Log::info("TN-SYNC-TRACE [RATINGS-PAGE] page=" . ($page - 1) . " count=" . count($ratings));

            foreach ($ratings as $rating) {
                $count++;

                if (!$maxDate || $rating['date'] > $maxDate) {
                    $maxDate = $rating['date'];
                }

                if (!($rating['ratee_fd_user_id'] ?? null)) {
                    continue;
                }

                $user = User::find($rating['ratee_fd_user_id']);
                if (!$user) {
                    continue;
                }

                try {
                    if ($rating['rating']) {
                        $ratingModel = Rating::firstOrNew(['tn_rating_id' => $rating['rating_id']]);
                        $isNew = !$ratingModel->exists;
                        if ($isNew) {
                            $ratingModel->ratee = $rating['ratee_fd_user_id'];
                            $ratingModel->visible = 1;
                        }
                        $ratingModel->rating = $rating['rating'];
                        $ratingModel->timestamp = $rating['date'];
                        Log::info("TN-SYNC-TRACE [WRITE] table=ratings op=upsert where=tn_rating_id={$rating['rating_id']} set=ratee={$rating['ratee_fd_user_id']},rating={$rating['rating']},timestamp={$rating['date']},visible=1");
                        // $ratingModel->save();  // TRACE: commented out for port testing
                        $this->loki->logEvent('tn-sync', 'rating-upsert', [
                            'action' => $isNew ? 'insert' : 'update',
                            'tn_rating_id' => $rating['rating_id'],
                            'user_id' => $rating['ratee_fd_user_id'],
                        ]);
                    } else {
                        Log::info("TN-SYNC-TRACE [WRITE] table=ratings op=delete where=ratee={$rating['ratee_fd_user_id']},tn_rating_id={$rating['rating_id']}");
                        $existing = Rating::where('ratee', $rating['ratee_fd_user_id'])
                            ->where('tn_rating_id', $rating['rating_id'])
                            ->first();
                        if ($existing) {
                            // $existing->delete();  // TRACE: commented out for port testing
                            $this->loki->logEvent('tn-sync', 'rating-delete', [
                                'tn_rating_id' => $rating['rating_id'],
                                'user_id' => $rating['ratee_fd_user_id'],
                            ]);
                        }
                    }
                } catch (\Exception $e) {
                    Log::info("TN-SYNC-TRACE [RATING] id={$rating['rating_id']} ratee={$rating['ratee_fd_user_id']} rating={$rating['rating']} action=error");
                    Log::error('TN sync: ratings sync failed', [
                        'error' => $e->getMessage(),
                        'rating' => $rating,
                    ]);
                    if (function_exists('\Sentry\captureException')) {
                        \Sentry\captureException($e);
                    }
                }
            }
        } while ($ratings && count($ratings) == self::PAGE_SIZE);

        return [$count, $maxDate];
    }

    /**
     * @return array [count, maxDate]
     */
    private function syncUserChanges(string $from, string $to): array
    {
        $page = 1;
        $count = 0;
        $maxDate = null;
        $fixturesDir = base_path('../iznik-server/test/integration/tn_sync/fixtures');

        do {
            // $response = Http::get("{$this->apiBaseUrl}/user-changes", [
            //     'key' => $this->apiKey,
            //     'page' => $page,
            //     'per_page' => self::PAGE_SIZE,
            //     'date_min' => $from,
            //     'date_max' => $to,
            // ]);
            //
            // if (!$response->successful()) {
            //     Log::error("TN sync: user-changes API failed on page {$page}", [
            //         'status' => $response->status(),
            //     ]);
            //     break;
            // }
            //
            // $changes = $response->json('changes', []);
            $changesFile = $fixturesDir . "/user_changes_page_{$page}.json";
            if (file_exists($changesFile)) {
                $changesPayload = json_decode(file_get_contents($changesFile), true);
                $changes = is_array($changesPayload) ? ($changesPayload['changes'] ?? []) : [];
            } else {
                Log::info("TN-SYNC-TRACE [CHANGES-PAGE] missing fixture file={$changesFile}");
                $changes = [];
            }
            $page++;

            Log::info("TN-SYNC-TRACE [CHANGES-PAGE] page=" . ($page - 1) . " count=" . count($changes));

            foreach ($changes as $change) {
                $count++;

                if (!$maxDate || $change['date'] > $maxDate) {
                    $maxDate = $change['date'];
                }

                if (!($change['fd_user_id'] ?? null)) {
                    continue;
                }

                try {
                    $user = User::find($change['fd_user_id']);
                    if (!$user || !$user->isTN()) {
                        continue;
                    }

                    if (!empty($change['account_removed'])) {
                        Log::info("FD #{$change['fd_user_id']} TN account removed");
                        Log::info("TN-SYNC-TRACE [USER-CHANGE] fd_user_id={$change['fd_user_id']} action=account-removed");
                        $user->forget('TN account removed');
                        $this->loki->logEvent('tn-sync', 'user-forget', [
                            'user_id' => $change['fd_user_id'],
                        ]);
                        continue;
                    }

                    if (!empty($change['reply_time'])) {
                        $replyTime = UserReplyTime::firstOrNew(['userid' => $change['fd_user_id']]);
                        $isNew = !$replyTime->exists;
                        $replyTime->replytime = $change['reply_time'];
                        $replyTime->timestamp = $change['date'];
                        Log::info("TN-SYNC-TRACE [WRITE] table=users_replytime op=replace where=userid={$change['fd_user_id']} set=replytime={$change['reply_time']},timestamp={$change['date']}");
                        // $replyTime->save();  // TRACE: commented out for port testing
                        $this->loki->logEvent('tn-sync', 'user-reply-time-upsert', [
                            'action' => $isNew ? 'insert' : 'update',
                            'user_id' => $change['fd_user_id'],
                        ]);
                    }

                    if (!empty($change['about_me'])) {
                        try {
                            $aboutMe = UserAboutMe::firstOrNew(['userid' => $change['fd_user_id']]);
                            $isNew = !$aboutMe->exists;
                            $aboutMe->timestamp = $change['date'];
                            $aboutMe->text = $change['about_me'];
                            Log::info("TN-SYNC-TRACE [WRITE] table=users_aboutme op=replace where=userid={$change['fd_user_id']} set=timestamp={$change['date']},text=len=" . strlen($change['about_me']));
                            // $aboutMe->save();  // TRACE: commented out for port testing
                            $this->loki->logEvent('tn-sync', 'user-about-me-upsert', [
                                'action' => $isNew ? 'insert' : 'update',
                                'user_id' => $change['fd_user_id'],
                            ]);
                        } catch (\Exception $e) {
                            if (function_exists('\Sentry\captureException')) {
                                \Sentry\captureException($e);
                            }
                        }
                    }

                    // Spot name changes.
                    if (!empty($change['username'])) {
                        $oldname = User::removeTNGroup($user->fullname ?? '');

                        if ($oldname != $change['username']) {
                            Log::info("Name change for {$change['fd_user_id']} {$oldname} => {$change['username']}");
                            Log::info("TN-SYNC-TRACE [NAME-CHANGE] fd_user_id={$change['fd_user_id']} old={$oldname} new={$change['username']}");
                            $user->fullname = $change['username'];

                            $emails = $user->emails()->pluck('email');

                            foreach ($emails as $email) {
                                if (str_contains($email, "{$oldname}-")) {
                                    $newEmail = str_replace("{$oldname}-", "{$change['username']}-", $email);
                                    $user->removeEmail($email);
                                    Log::info("...{$email} => {$newEmail}");
                                    $user->addEmail($newEmail);
                                    $this->loki->logEvent('tn-sync', 'user-email-rename', [
                                        'user_id' => $change['fd_user_id'],
                                        'old_email' => $email,
                                        'new_email' => $newEmail,
                                    ]);
                                }
                            }
                        }
                    }

                    // Location changes.
                    if (!empty($change['location'])) {
                        $lat = $change['location']['latitude'] ?? null;
                        $lng = $change['location']['longitude'] ?? null;

                        if ($lat !== null && $lng !== null) {
                            $loc = Location::closestPostcode((float) $lat, (float) $lng);

                            if ($loc && $loc['id'] !== $user->lastlocation) {
                                Log::info("FD #{$change['fd_user_id']} TN lat/lng {$lat},{$lng} has changed  => {$loc['id']} {$loc['name']}");
                                Log::info("TN-SYNC-TRACE [LOCATION] fd_user_id={$change['fd_user_id']} lat={$lat} lng={$lng} old_loc={$user->lastlocation} new_loc={$loc['id']}");
                                $user->lastlocation = $loc['id'];
                            }
                        }
                    }

                    // $user->save();  // TRACE: commented out for port testing
                    $this->loki->logEvent('tn-sync', 'user-update', [
                        'user_id' => $change['fd_user_id'],
                    ]);

                    Log::info("TN-SYNC-TRACE [USER-CHANGE] fd_user_id={$change['fd_user_id']} action=processed");
                } catch (\Exception $e) {
                    Log::info("TN-SYNC-TRACE [USER-CHANGE] fd_user_id={$change['fd_user_id']} action=error");
                    Log::error('TN sync: user changes sync failed', [
                        'error' => $e->getMessage(),
                        'change' => $change,
                    ]);
                    if (function_exists('\Sentry\captureException')) {
                        \Sentry\captureException($e);
                    }
                }
            }
        } while ($changes && count($changes) == self::PAGE_SIZE);

        return [$count, $maxDate];
    }

    private function mergeDuplicateTNUsers(): int
    {
        $duplicates = UserEmail::selectRaw("COUNT(DISTINCT(userid)) AS count, REGEXP_REPLACE(email, '(.*)-g[0-9]+@user\\.trashnothing\\.com', '$1') AS username")
            ->where('email', 'LIKE', '%@user.trashnothing.com')
            ->groupBy('username')
            ->having('count', '>', 1)
            ->get();

        if (empty($duplicates)) {
            return 0;
        }

        Log::info('Found ' . count($duplicates) . ' duplicate TN users');
        Log::info("TN-SYNC-TRACE [DUP-SCAN] count=" . count($duplicates));
        $merged = 0;

        foreach ($duplicates as $dup) {
            Log::info("Look for dups for {$dup->username}");

            $userIds = UserEmail::selectRaw("DISTINCT(userid) as userid")
                ->whereRaw("REGEXP_REPLACE(email, '(.*)-g[0-9]+@user\\.trashnothing\\.com', '$1') = ?", [$dup->username])
                ->where('email', 'LIKE', '%@user.trashnothing.com')
                ->pluck('userid')
                ->toArray();

            Log::info('Found ' . count($userIds) . " users for {$dup->username}");

            if (count($userIds) > 1) {
                $mergeTo = $userIds[0];

                foreach ($userIds as $userId) {
                    if ($userId != $mergeTo) {
                        Log::info("Merging {$userId} into {$mergeTo}");
                        Log::info("TN-SYNC-TRACE [MERGE] from={$userId} into={$mergeTo}");
                        User::merge($mergeTo, $userId, "Duplicate TN user created accidentally");
                        $this->loki->logEvent('tn-sync', 'user-merge', [
                            'merge_to' => $mergeTo,
                            'merge_from' => $userId,
                        ]);
                        $merged++;
                    }
                }
            }
        }

        return $merged;
    }
}
