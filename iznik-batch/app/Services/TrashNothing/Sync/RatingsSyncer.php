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
                            $ratingModel->ratee   = $rating['ratee_fd_user_id'];
                            $ratingModel->visible = 1;
                        }
                        $ratingModel->rating    = $rating['rating'];
                        $ratingModel->timestamp = $rating['date'];
                        Log::info('TN-SYNC-TRACE [WRITE] table=ratings op=upsert where=tn_rating_id=' . $rating['rating_id'] . ' set=ratee=' . $rating['ratee_fd_user_id'] . ',rating=' . $rating['rating'] . ',timestamp=' . $rating['date'] . ',visible=1');
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
                    Log::info('TN-SYNC-TRACE [RATING] id=' . $rating['rating_id'] . ' ratee=' . $rating['ratee_fd_user_id'] . ' rating=' . $rating['rating'] . ' action=error');
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
