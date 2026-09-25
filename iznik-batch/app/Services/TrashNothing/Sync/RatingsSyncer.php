<?php

namespace App\Services\TrashNothing\Sync;

use App\Models\Rating;
use App\Models\User;
use App\Services\LokiService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RatingsSyncer
{
    private const PAGE_SIZE = 100;

    public function __construct(
        private readonly bool $dryRun,
        private readonly bool $localTesting,
        private readonly string $apiKey,
        private readonly string $apiBaseUrl,
        private readonly LokiService $loki,
        // Shared with the other TN syncers — TN rate-limits per API key and one
        // tn:sync run calls three endpoints with the same key, so a throttle
        // that only paces this class's own requests protects nothing.
        private readonly ?TrashNothingRateLimiter $rateLimiter = null,
    ) {}

    /**
     * @return array{int, string|null} [count, maxDate]
     */
    public function sync(string $from, string $to): array
    {
        $page    = 1;
        $count   = 0;
        $maxDate = null;

        do {
            $ratings = $this->fetchPage($page, $from, $to);
            if ($ratings === null) {
                break;
            }

            $page++;
            Log::info('TN-SYNC-TRACE [RATINGS-PAGE] page=' . ($page - 1) . ' count=' . count($ratings));

            foreach ($ratings as $rating) {
                $count++;

                // A row without a date must not move the watermark: taking the
                // missing value would either warn and compare as '' (leaving
                // $maxDate null on the first row, so the whole page's progress
                // is lost) or, worse, be stored as the new sync date.
                $ratingDate = $rating['date'] ?? null;
                if ($ratingDate !== null && (!$maxDate || $ratingDate > $maxDate)) {
                    $maxDate = $ratingDate;
                }

                if (!($rating['ratee_fd_user_id'] ?? null)) {
                    continue;
                }

                $user = User::find($rating['ratee_fd_user_id']);
                if (!$user) {
                    continue;
                }

                try {
                    // Only an absent/null rating means "deleted" — a falsy but
                    // present value is a real rating, not a deletion.
                    if (($rating['rating'] ?? null) !== null) {
                        $ratingModel = Rating::firstOrNew(['tn_rating_id' => $rating['rating_id']]);
                        $isNew = !$ratingModel->exists;
                        if ($isNew) {
                            $ratingModel->ratee   = $rating['ratee_fd_user_id'];
                            $ratingModel->visible = 1;
                        }
                        $ratingModel->rating    = $rating['rating'];
                        $ratingModel->timestamp = $ratingDate;
                        Log::info('TN-SYNC-TRACE [WRITE] table=ratings op=upsert where=tn_rating_id=' . $rating['rating_id'] . ' set=ratee=' . $rating['ratee_fd_user_id'] . ',rating=' . $rating['rating'] . ',timestamp=' . ($ratingDate ?? 'null') . ',visible=1');
                        if (!$this->dryRun) {
                            $ratingModel->save();
                        }
                        $this->loki->logEvent('tn-sync', 'rating-upsert', [
                            'action'       => $isNew ? 'insert' : 'update',
                            'tn_rating_id' => $rating['rating_id'],
                            'user_id'      => $rating['ratee_fd_user_id'],
                        ]);
                    } else {
                        Log::info('TN-SYNC-TRACE [WRITE] table=ratings op=delete where=ratee=' . $rating['ratee_fd_user_id'] . ',tn_rating_id=' . $rating['rating_id']);
                        $existing = Rating::where('ratee', $rating['ratee_fd_user_id'])
                            ->where('tn_rating_id', $rating['rating_id'])
                            ->first();
                        if ($existing) {
                            if (!$this->dryRun) {
                                $existing->delete();
                            }
                            $this->loki->logEvent('tn-sync', 'rating-delete', [
                                'tn_rating_id' => $rating['rating_id'],
                                'user_id'      => $rating['ratee_fd_user_id'],
                            ]);
                        }
                    }
                } catch (\Exception $e) {
                    Log::info('TN-SYNC-TRACE [RATING] id=' . ($rating['rating_id'] ?? 'null') . ' ratee=' . ($rating['ratee_fd_user_id'] ?? 'null') . ' rating=' . ($rating['rating'] ?? 'null') . ' action=error');
                    Log::error('TN sync: ratings sync failed', [
                        'error'  => $e->getMessage(),
                        'rating' => $rating,
                    ]);
                    if (function_exists('\Sentry\captureException')) {
                        \Sentry\captureException($e);
                    }
                }
            }
        } while ($ratings && count($ratings) === self::PAGE_SIZE);

        return [$count, $maxDate];
    }

    /**
     * @return array|null  Rating rows, or null on API error.
     */
    private function fetchPage(int $page, string $from, string $to): ?array
    {
        if ($this->localTesting) {
            $file = base_path("tests/fixtures/tn_sync/ratings_page_{$page}.json");
            if (!file_exists($file)) {
                Log::info('TN-SYNC-TRACE [RATINGS-PAGE] missing fixture file=' . $file);
                return [];
            }
            $payload = json_decode(file_get_contents($file), true);
            return is_array($payload) ? ($payload['ratings'] ?? []) : [];
        }

        ($this->rateLimiter ?? app(TrashNothingRateLimiter::class))->await();

        $response = Http::get("{$this->apiBaseUrl}/ratings", [
            'key'      => $this->apiKey,
            'page'     => $page,
            'per_page' => self::PAGE_SIZE,
            'date_min' => $from,
            'date_max' => $to,
        ]);

        if (!$response->successful()) {
            Log::error('TN sync: ratings API failed on page ' . $page, ['status' => $response->status()]);
            return null;
        }

        return $response->json('ratings', []);
    }
}
