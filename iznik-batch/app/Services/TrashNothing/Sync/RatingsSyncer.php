<?php

namespace App\Services\TrashNothing\Sync;

use App\Models\Rating;
use App\Services\LokiService;
use App\Support\Savepoint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RatingsSyncer
{
    use ReadsTnFeedByDay;

    private const PAGE_SIZE = 100;

    // Whether the last sync() stopped because TN's ratings feed could not be read.
    private bool $fetchFailed = false;

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
     * Whether the last sync() stopped at a page it could not fetch. Some of the ratings after
     * it were never seen, and the feed's order is TN's, so the page's position is not known:
     * the caller must not store a sync date this run, or the next run starts after them.
     */
    public function fetchFailed(): bool
    {
        return $this->fetchFailed;
    }

    /**
     * @return array{int, string|null} [count, maxDate]
     */
    public function sync(string $from, string $to): array
    {
        $count   = 0;
        $maxDate = null;

        foreach ($this->pages($from, $to) as $page => $ratings) {
            Log::info('TN-SYNC-TRACE [RATINGS-PAGE] page=' . $page . ' count=' . count($ratings));

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

                $rateeId = $this->resolveRatee($rating);
                if ($rateeId === null) {
                    continue;
                }

                try {
                    // A failed write must not spoil an outer transaction: PostgreSQL aborts it without a savepoint.
                    Savepoint::around(function () use ($rating, $ratingDate, $rateeId) {
                        // Only an absent/null rating means "deleted" — a falsy but
                        // present value is a real rating, not a deletion.
                        if (($rating['rating'] ?? null) !== null) {
                            $ratingModel = Rating::firstOrNew(['tn_rating_id' => $rating['rating_id']]);
                            $isNew = !$ratingModel->exists;
                            if ($isNew) {
                                $ratingModel->ratee   = $rateeId;
                                $ratingModel->visible = 1;
                            }
                            $ratingModel->rating    = $rating['rating'];
                            $ratingModel->timestamp = $ratingDate;
                            Log::info('TN-SYNC-TRACE [WRITE] table=ratings op=upsert where=tn_rating_id=' . $rating['rating_id'] . ' set=ratee=' . $rateeId . ',rating=' . $rating['rating'] . ',timestamp=' . ($ratingDate ?? 'null') . ',visible=1');
                            if (!$this->dryRun) {
                                $ratingModel->save();
                            }
                            $this->loki->logEvent('tn-sync', 'rating-upsert', [
                                'action'       => $isNew ? 'insert' : 'update',
                                'tn_rating_id' => $rating['rating_id'],
                                'user_id'      => $rateeId,
                            ]);
                        } else {
                            // By TN's id alone, which is unique: a rating filed before a split stays
                            // on the account it was filed on, which may not be the one resolved now.
                            Log::info('TN-SYNC-TRACE [WRITE] table=ratings op=delete where=tn_rating_id=' . $rating['rating_id']);
                            $existing = Rating::where('tn_rating_id', $rating['rating_id'])->first();
                            if ($existing) {
                                if (!$this->dryRun) {
                                    $existing->delete();
                                }
                                $this->loki->logEvent('tn-sync', 'rating-delete', [
                                    'tn_rating_id' => $rating['rating_id'],
                                    'user_id'      => (int) $existing->ratee,
                                ]);
                            }
                        }
                    });
                } catch (\Exception $e) {
                    Log::info('TN-SYNC-TRACE [RATING] id=' . ($rating['rating_id'] ?? 'null') . ' ratee=' . $rateeId . ' rating=' . ($rating['rating'] ?? 'null') . ' action=error');
                    Log::error('TN sync: ratings sync failed', [
                        'error'  => $e->getMessage(),
                        'rating' => $rating,
                    ]);
                    if (function_exists('\Sentry\captureException')) {
                        \Sentry\captureException($e);
                    }
                }
            }
        }

        return [$count, $maxDate];
    }

    /**
     * The Freegle account a rating is about, or null to skip it: the one TnMemberMatch says TN
     * means. A rating asks less of that answer than a user change does. Any account can be rated,
     * not only a TN one, and filing a rating loses nothing, so an account with no TN id gives way
     * to the one holding it without a person having to say. A rating is skipped only when the
     * account TN names belongs to another member sharing it.
     */
    private function resolveRatee(array $rating): ?int
    {
        $tnId  = (int) ($rating['ratee_tn_user_id'] ?? 0);
        $match = TnMemberMatch::find((int) ($rating['ratee_fd_user_id'] ?? 0), $tnId);
        $ratee = $match->account();

        if ($ratee === null && $match->named !== null) {
            Log::info('TN-SYNC-TRACE [RATING] id=' . ($rating['rating_id'] ?? 'null') . ' ratee=' . $match->named->id . ' action=skip reason=tn-user-id-mismatch');
            $this->loki->logEvent('tn-sync', 'rating-skip', [
                'reason'     => 'tn-user-id-mismatch',
                'user_id'    => $match->named->id,
                'tn_user_id' => $tnId,
                'other'      => (int) $match->named->tnuserid,
            ]);
        }

        return $ratee?->id;
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
