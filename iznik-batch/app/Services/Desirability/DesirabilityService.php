<?php

namespace App\Services\Desirability;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Scores a message's item desirability from the item_desirability artifact.
 *
 * Resolution order for a canonicalised subject:
 *  1. exact  - the canonical key has a row (covers clustered variants too, since
 *              the artifact stores every member key of a cluster).
 *  2. knn    - embed the canonical via the embedding sidecar (query-space, the
 *              same space the artifact's reference vectors were built in) and
 *              take a similarity-weighted average of the nearest reference
 *              titles' log-lifts. Only trusted when the best neighbour clears
 *              the configured cosine floor.
 *  3. default - score 1.0, bucket medium.
 *
 * kNN buckets are derived from the blended score with a conservatism rule: a
 * kNN score only earns low/high when the top neighbour is very close AND the
 * neighbours agree on the side; otherwise medium. Exact rows carry their
 * posterior-derived bucket from the artifact.
 */
class DesirabilityService
{
    public const EMBEDDING_DIM = 256;

    private TitleCanonicalService $canonicaliser;

    /** @var ?array{canonicals: array<int, string>, lifts: array<int, float>, evidence: array<int, float>, vectors: array<int, array<int, float>>} */
    private ?array $reference = null;

    public function __construct(TitleCanonicalService $canonicaliser)
    {
        $this->canonicaliser = $canonicaliser;
    }

    public function modelVersion(): string
    {
        return (string) config('freegle.desirability.model_version', 'desir-2026-08');
    }

    public function artifactReady(): bool
    {
        return DB::table('item_desirability')->where('model_version', $this->modelVersion())->exists();
    }

    /**
     * @return array{score: float, bucket: string, source: string, matched_canonical: ?string, canonical: string}
     */
    public function scoreSubject(?string $subject): array
    {
        $canon = $this->canonicaliser->canonicalise($subject);
        $key = mb_substr((string) $canon['canonical'], 0, 191);
        $default = ['score' => 1.0, 'bucket' => 'medium', 'source' => 'default', 'matched_canonical' => null, 'canonical' => $key];
        if (! strlen($key)) {
            return $default;
        }

        $row = DB::table('item_desirability')
            ->where('model_version', $this->modelVersion())
            ->where('canonical', $key)
            ->first(['lift_replies', 'bucket']);
        if ($row) {
            return ['score' => (float) $row->lift_replies, 'bucket' => $row->bucket, 'source' => 'exact', 'matched_canonical' => $key, 'canonical' => $key];
        }

        $knn = $this->scoreByNearestReference($key);

        return $knn ?? $default;
    }

    /** @return ?array{score: float, bucket: string, source: string, matched_canonical: string, canonical: string} */
    private function scoreByNearestReference(string $canonical): ?array
    {
        $vec = $this->embed($canonical);
        if (! $vec) {
            return null;
        }
        $ref = $this->loadReference();
        if (! $ref || ! count($ref['canonicals'])) {
            return null;
        }

        $k = (int) config('freegle.desirability.knn_k', 10);
        $minCos = (float) config('freegle.desirability.knn_min_cos', 0.80);
        $gamma = (float) config('freegle.desirability.knn_gamma', 8);

        $hits = [];
        foreach ($ref['vectors'] as $i => $rv) {
            $dot = 0.0;
            for ($d = 0; $d < self::EMBEDDING_DIM; $d++) {
                $dot += $vec[$d] * $rv[$d];
            }
            if ($dot >= $minCos) {
                $hits[] = [$i, $dot];
            }
        }
        if (! count($hits)) {
            return null;
        }
        usort($hits, fn ($a, $b) => $b[1] <=> $a[1]);
        $hits = array_slice($hits, 0, $k);

        $num = 0.0;
        $den = 0.0;
        $above = 0;
        $below = 0;
        foreach ($hits as [$i, $cos]) {
            $w = pow($cos, $gamma) * log(1 + $ref['evidence'][$i]);
            $logLift = log(max($ref['lifts'][$i], 1e-6));
            $num += $w * $logLift;
            $den += $w;
            if ($logLift > 0) {
                $above++;
            } else {
                $below++;
            }
        }
        if ($den <= 0) {
            return null;
        }
        $score = exp($num / $den);
        $topCos = $hits[0][1];

        // Conservative bucketing for inferred scores: low/high only with a very close
        // neighbour and unanimous side agreement; otherwise medium.
        $bucket = 'medium';
        $strongCos = (float) config('freegle.desirability.knn_strong_cos', 0.90);
        $lowMax = (float) config('freegle.desirability.bucket_low_max', 0.6);
        $highMin = (float) config('freegle.desirability.bucket_high_min', 1.6);
        if ($topCos >= $strongCos) {
            if ($score >= $highMin && $below === 0) {
                $bucket = 'high';
            } elseif ($score <= $lowMax && $above === 0) {
                $bucket = 'low';
            }
        }

        return [
            'score' => round($score, 4),
            'bucket' => $bucket,
            'source' => 'knn',
            'matched_canonical' => $ref['canonicals'][$hits[0][0]],
            'canonical' => $canonical,
        ];
    }

    /** @return ?array<int, float> */
    private function embed(string $text): ?array
    {
        $url = rtrim((string) env('EMBEDDING_SIDECAR_URL', ''), '/');
        if (! $url) {
            return null;
        }
        try {
            $response = Http::timeout(5)->post($url.'/embed', ['texts' => [$text]]);
            if (! $response->successful()) {
                Log::warning('Desirability embed failed', ['status' => $response->status()]);

                return null;
            }
            $vec = $response->json('embeddings.0');

            return is_array($vec) && count($vec) === self::EMBEDDING_DIM ? array_map('floatval', $vec) : null;
        } catch (\Exception $e) {
            Log::warning('Desirability embed exception', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Reference rows (the artifact rows that carry an embedding) are loaded once
     * per process and unpacked from 256 x little-endian float32 blobs.
     */
    private function loadReference(): ?array
    {
        if ($this->reference !== null) {
            return $this->reference;
        }
        $rows = DB::table('item_desirability')
            ->where('model_version', $this->modelVersion())
            ->whereNotNull('embedding')
            ->get(['canonical', 'lift_replies', 'evidence', 'embedding']);
        $ref = ['canonicals' => [], 'lifts' => [], 'evidence' => [], 'vectors' => []];
        foreach ($rows as $r) {
            $vec = array_values(unpack('g'.self::EMBEDDING_DIM, $r->embedding) ?: []);
            if (count($vec) !== self::EMBEDDING_DIM) {
                continue;
            }
            $ref['canonicals'][] = $r->canonical;
            $ref['lifts'][] = (float) $r->lift_replies;
            $ref['evidence'][] = (float) $r->evidence;
            $ref['vectors'][] = $vec;
        }
        $this->reference = $ref;

        return $ref;
    }
}
