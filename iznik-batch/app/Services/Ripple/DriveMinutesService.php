<?php

namespace App\Services\Ripple;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Drive minutes from a recipient to candidate posts, via the routing engine's
 * batched /v1/drive-metrics (the same endpoint apiv2 stamps browse feeds
 * with). Used by the digest distance-preference filter so email selection
 * applies the member's drive-time budget with the SAME rule as the site.
 *
 * The engine serves precomputed, memory-mapped leaf tables, so per-target
 * lookups are uniform (~µs) everywhere in the country; the only per-call cost
 * is the origin's boundary search (~30-85ms cold, cached engine-side). Batch
 * accordingly: one call per recipient covering every candidate post
 * (prefetch), never one call per (recipient, post) pair.
 *
 * Fail-soft by design: any transport or engine problem yields nulls, and the
 * caller's crow-miles rule takes over — the filter can degrade, never break
 * mail. Answers are memoised per rounded (origin, target) pair for the run,
 * so the immediate paths (one post, recipients arriving one at a time) and
 * the digest path (one recipient, many posts) both hit the memo after their
 * first fetch of a pair.
 */
class DriveMinutesService
{
    /** Above any real member budget; the engine treats >120 as 120 anyway. */
    private const MAX_MINUTES_HORIZON = 120;

    private const CHUNK = 1000;

    /** @var array<string, float|null> */
    private array $memo = [];

    /**
     * Warm the memo with one batched call: drive minutes from ($olat,$olng)
     * to every target. $targets is [key => [lat, lng]]; keys are preserved in
     * the returned map. Null = no answer (unroutable, beyond 120 min, or the
     * engine unavailable) — the caller falls back to crow miles.
     *
     * @param  array<int|string, array{0: float, 1: float}>  $targets
     * @return array<int|string, float|null>
     */
    public function prefetch(float $olat, float $olng, array $targets): array
    {
        $out = [];
        $missing = [];
        foreach ($targets as $key => $t) {
            $memoKey = $this->memoKey($olat, $olng, (float) $t[0], (float) $t[1]);
            if (array_key_exists($memoKey, $this->memo)) {
                $out[$key] = $this->memo[$memoKey];
            } else {
                $missing[$key] = $t;
            }
        }

        foreach (array_chunk($missing, self::CHUNK, true) as $chunk) {
            $keys = array_keys($chunk);
            $payload = [];
            foreach (array_values($chunk) as $i => $t) {
                $payload[] = ['id' => $i, 'lat' => (float) $t[0], 'lng' => (float) $t[1]];
            }

            $answers = [];
            try {
                $base = rtrim((string) config('freegle.routing_server_url'), '/');
                $response = Http::timeout(10)->post($base.'/v1/drive-metrics', [
                    'lat' => $olat,
                    'lng' => $olng,
                    'max_minutes' => self::MAX_MINUTES_HORIZON,
                    'targets' => $payload,
                ]);
                if ($response->successful()) {
                    foreach (($response->json('results') ?? []) as $r) {
                        if (isset($r['id']) && isset($r['mins']) && is_numeric($r['mins'])) {
                            $answers[(int) $r['id']] = (float) $r['mins'];
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('digest: drive-minutes lookup failed, crow fallback', [
                    'targets' => count($payload), 'error' => $e->getMessage(),
                ]);
            }

            foreach ($keys as $i => $key) {
                $t = $chunk[$key];
                $mins = $answers[$i] ?? null;
                $this->memo[$this->memoKey($olat, $olng, (float) $t[0], (float) $t[1])] = $mins;
                $out[$key] = $mins;
            }
        }

        return $out;
    }

    /**
     * Drive minutes for a single (recipient, post) pair — the immediate
     * pipelines' shape. Served from the memo when a prefetch or an earlier
     * pair already answered it; otherwise one single-target call.
     */
    public function minutesBetween(float $olat, float $olng, float $tlat, float $tlng): ?float
    {
        $memoKey = $this->memoKey($olat, $olng, $tlat, $tlng);
        if (array_key_exists($memoKey, $this->memo)) {
            return $this->memo[$memoKey];
        }

        $answers = $this->prefetch($olat, $olng, [0 => [$tlat, $tlng]]);

        return $answers[0] ?? null;
    }

    /**
     * ~11m grid at UK latitudes: fine enough that no two distinct member or
     * post locations collapse, coarse enough that float noise from different
     * resolution paths of the same point cannot split the memo.
     */
    private function memoKey(float $olat, float $olng, float $tlat, float $tlng): string
    {
        return sprintf('%.4f,%.4f|%.4f,%.4f', $olat, $olng, $tlat, $tlng);
    }
}
