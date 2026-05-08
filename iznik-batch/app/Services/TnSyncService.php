<?php

namespace App\Services;

use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TnSyncService
{
    private const CACHE_KEY      = 'tn_sync_last_date';
    private const PAGE_SIZE      = 100;
    private const RATINGS_PATH   = '/fd/api/ratings';
    private const CHANGES_PATH   = '/fd/api/user-changes';

    private string $apiBase;
    private string $apiKey;

    public function __construct()
    {
        $this->apiBase = config('freegle.trashnothing.api_base', 'https://trashnothing.com');
        $this->apiKey  = config('freegle.trashnothing.key', '');
    }

    public function sync(bool $dryRun = false): array
    {
        $from = $this->getFromDate();
        $to   = date('c');

        Log::info('TN sync starting', ['from' => $from, 'to' => $to, 'dry_run' => $dryRun]);

        $ratings     = $this->syncRatings($from, $to, $dryRun);
        $userChanges = $this->syncUserChanges($from, $to, $dryRun);

        if (!$dryRun && ($ratings > 0 || $userChanges > 0)) {
            Cache::forever(self::CACHE_KEY, $to);
        }

        return ['ratings' => $ratings, 'user_changes' => $userChanges];
    }

    private function getFromDate(): string
    {
        $cached = Cache::get(self::CACHE_KEY);
        if ($cached) {
            return $cached;
        }

        $latest = DB::table('ratings')
            ->whereNotNull('tn_rating_id')
            ->max('timestamp');

        return $latest ? date('c', strtotime($latest)) : date('c', strtotime('30 days ago'));
    }

    private function syncRatings(string $from, string $to, bool $dryRun): int
    {
        $count = 0;
        $page  = 1;

        do {
            $response = Http::get($this->apiBase . self::RATINGS_PATH, [
                'key'      => $this->apiKey,
                'page'     => $page,
                'per_page' => self::PAGE_SIZE,
                'date_min' => $from,
                'date_max' => $to,
            ]);

            if (!$response->successful()) {
                Log::error('TN sync: ratings API error', ['status' => $response->status()]);
                break;
            }

            $ratings = $response->json('ratings', []);
            $page++;

            foreach ($ratings as $rating) {
                if (!($rating['ratee_fd_user_id'] ?? null)) {
                    continue;
                }

                $rateeId  = $rating['ratee_fd_user_id'];
                $ratingId = $rating['rating_id'];

                if (!User::find($rateeId)) {
                    continue;
                }

                if ($dryRun) {
                    Log::debug('TN sync: dry run — would sync rating', [
                        'ratee'     => $rateeId,
                        'rating_id' => $ratingId,
                        'rating'    => $rating['rating'] ?? null,
                    ]);
                    $count++;
                    continue;
                }

                try {
                    if ($rating['rating'] ?? null) {
                        DB::statement(
                            "INSERT INTO ratings (ratee, rating, timestamp, visible, tn_rating_id)
                             VALUES (?, ?, ?, 1, ?)
                             ON DUPLICATE KEY UPDATE rating = ?, timestamp = ?",
                            [
                                $rateeId,
                                $rating['rating'],
                                $rating['date'],
                                $ratingId,
                                $rating['rating'],
                                $rating['date'],
                            ]
                        );
                    } else {
                        DB::table('ratings')
                            ->where('ratee', $rateeId)
                            ->where('tn_rating_id', $ratingId)
                            ->delete();
                    }
                    $count++;
                } catch (\Throwable $e) {
                    Log::error('TN sync: rating update failed', [
                        'ratee'  => $rateeId,
                        'rating' => $rating,
                        'error'  => $e->getMessage(),
                    ]);
                }
            }
        } while (count($ratings) === self::PAGE_SIZE);

        return $count;
    }

    private function syncUserChanges(string $from, string $to, bool $dryRun): int
    {
        $count = 0;
        $page  = 1;

        do {
            $response = Http::get($this->apiBase . self::CHANGES_PATH, [
                'key'      => $this->apiKey,
                'page'     => $page,
                'per_page' => self::PAGE_SIZE,
                'date_min' => $from,
                'date_max' => $to,
            ]);

            if (!$response->successful()) {
                Log::error('TN sync: user-changes API error', ['status' => $response->status()]);
                break;
            }

            $changes = $response->json('changes', []);
            $page++;

            foreach ($changes as $change) {
                if (!($change['fd_user_id'] ?? null)) {
                    continue;
                }

                $userId = $change['fd_user_id'];
                $user   = User::find($userId);

                if (!$user || !$user->isTN()) {
                    continue;
                }

                if ($dryRun) {
                    Log::debug('TN sync: dry run — would apply user change', [
                        'user'           => $userId,
                        'account_removed'=> isset($change['account_removed']),
                        'has_reply_time' => isset($change['reply_time']),
                        'has_about_me'   => isset($change['about_me']),
                        'has_username'   => isset($change['username']),
                        'has_location'   => isset($change['location']),
                    ]);
                    $count++;
                    continue;
                }

                try {
                    if (isset($change['account_removed'])) {
                        Log::info('TN sync: account removed', ['user' => $userId]);
                        app(UserManagementService::class)->forgetUser($userId, 'TN account removed');
                        $count++;
                        continue;
                    }

                    if (isset($change['reply_time'])) {
                        DB::statement(
                            "REPLACE INTO users_replytime (userid, replytime, timestamp) VALUES (?, ?, ?)",
                            [$userId, $change['reply_time'], $change['date']]
                        );
                    }

                    if (isset($change['about_me'])) {
                        try {
                            DB::statement(
                                "REPLACE INTO users_aboutme (userid, timestamp, text) VALUES (?, ?, ?)",
                                [$userId, $change['date'], $change['about_me']]
                            );
                        } catch (\Throwable $e) {
                            Log::error('TN sync: about_me update failed', ['user' => $userId, 'error' => $e->getMessage()]);
                        }
                    }

                    if (isset($change['username'])) {
                        $oldName = User::removeTNGroup($user->fullname ?? '');
                        if ($oldName !== $change['username']) {
                            Log::info('TN sync: name change', ['user' => $userId, 'from' => $oldName, 'to' => $change['username']]);
                            DB::table('users')->where('id', $userId)->update(['fullname' => $change['username']]);

                            $this->renameTnEmails($userId, $oldName, $change['username']);
                        }
                    }

                    if (isset($change['location']['latitude'], $change['location']['longitude'])) {
                        $lat = (float) $change['location']['latitude'];
                        $lng = (float) $change['location']['longitude'];

                        $loc = Location::closestPostcode($lat, $lng);

                        if ($loc && $loc->id !== $user->lastlocation) {
                            Log::info('TN sync: location updated', ['user' => $userId, 'loc' => $loc->id, 'name' => $loc->name]);
                            DB::table('users')->where('id', $userId)->update(['lastlocation' => $loc->id]);
                        }
                    }

                    $count++;
                } catch (\Throwable $e) {
                    Log::error('TN sync: user change failed', ['user' => $userId, 'error' => $e->getMessage()]);
                }
            }
        } while (count($changes) === self::PAGE_SIZE);

        return $count;
    }

    private function renameTnEmails(int $userId, string $oldName, string $newName): void
    {
        $emails = DB::table('users_emails')->where('userid', $userId)->get();

        foreach ($emails as $emailRow) {
            if (str_contains($emailRow->email, "{$oldName}-")) {
                $newEmail = str_replace("{$oldName}-", "{$newName}-", $emailRow->email);
                Log::info('TN sync: rename email', ['user' => $userId, 'from' => $emailRow->email, 'to' => $newEmail]);
                DB::table('users_emails')->where('id', $emailRow->id)->update(['email' => $newEmail]);
            }
        }
    }
}
