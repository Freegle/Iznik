<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Wraps the embedding sidecar HTTP service to provide contextual false-positive
 * suppression for concern-keyword matches.
 *
 * When a keyword (e.g. "gun") matches, this service embeds the post text and
 * compares it to pre-defined "concerning" vs "innocent" prototype sentences
 * for the keyword's category. If the text is clearly more similar to the
 * innocent cluster, the match is suppressed.
 *
 * Conservative by design: returns false (= do not suppress) whenever the
 * sidecar is unavailable, unconfigured, or the category has no prototypes.
 */
class ContentEmbeddingService
{
    // Minimum similarity gap (innocent − concerning) required to suppress a flag.
    // 0.05 = 5% — needs to be clearly more innocent, not just marginally so.
    private const INNOCENT_MARGIN = 0.05;

    // Prototype sentences per concern-keyword category.
    // Each category has a 'concerning' cluster (genuine risk) and an 'innocent'
    // cluster (legitimate everyday use of the same words).
    //
    // These are deliberately short, concrete sentences so the model focuses on
    // the key semantic signal rather than surrounding filler.
    private const PROTOTYPES = [
        'substance_regulated' => [
            'concerning' => [
                'gun handgun pistol revolver for sale',
                'selling rifle ammunition firearm weapon',
                'real gun weapon for sale',
                'knife blade weapon stabbing',
                'sword machete combat blade',
            ],
            'innocent' => [
                'hot glue gun craft supplies hobby',
                'water pistol toy nerf gun children play',
                'staple gun woodwork DIY',
                'kitchen knife chef cooking set',
                'penknife pocket tool camping outdoor',
                'caulk gun silicone applicator',
            ],
        ],
        'substance_reportable' => [
            'concerning' => [
                'acid attack chemical weapon',
                'corrosive dangerous acid substance',
                'mercury toxic poison liquid metal',
                'flammable solvent hazardous chemical',
            ],
            'innocent' => [
                'battery acid car maintenance',
                'swimming pool chemicals chlorine tablets',
                'cleaning products household spray',
                'nail varnish remover acetone beauty',
                'car battery jump start',
            ],
        ],
    ];

    // In-process cache for prototype embeddings so we only embed them once per
    // process lifetime (PHP-FPM worker or artisan invocation).
    private static array $prototypeCache = [];

    public function isInnocentContext(string $text, string $category): bool
    {
        $url = env('EMBEDDING_SIDECAR_URL', '');
        if ($url === '') {
            return false;
        }

        if (!isset(self::PROTOTYPES[$category])) {
            return false;
        }

        $concerning = self::PROTOTYPES[$category]['concerning'];
        $innocent   = self::PROTOTYPES[$category]['innocent'];

        // Fetch prototype embeddings (cached) and the text embedding in one batch.
        $cacheKey = $category;
        if (!isset(self::$prototypeCache[$cacheKey])) {
            $protoTexts = array_merge($concerning, $innocent);
            $protoEmbeddings = $this->fetchEmbeddings($url, $protoTexts);
            if ($protoEmbeddings === null) {
                return false;
            }
            self::$prototypeCache[$cacheKey] = [
                'concerning' => array_slice($protoEmbeddings, 0, count($concerning)),
                'innocent'   => array_slice($protoEmbeddings, count($concerning)),
            ];
        }

        $textEmbeddings = $this->fetchEmbeddings($url, [$text]);
        if ($textEmbeddings === null) {
            return false;
        }
        $textVec = $textEmbeddings[0];

        $concernSim = $this->meanCosineSim($textVec, self::$prototypeCache[$cacheKey]['concerning']);
        $innocentSim = $this->meanCosineSim($textVec, self::$prototypeCache[$cacheKey]['innocent']);

        $suppress = $innocentSim > $concernSim + self::INNOCENT_MARGIN;

        if ($suppress) {
            Log::debug('ContentEmbedding: suppressing false positive', [
                'category'    => $category,
                'concernSim'  => round($concernSim, 4),
                'innocentSim' => round($innocentSim, 4),
                'text'        => mb_substr($text, 0, 100),
            ]);
        }

        return $suppress;
    }

    /** @return float[][]|null null when sidecar is unavailable */
    private function fetchEmbeddings(string $url, array $texts): ?array
    {
        try {
            $response = Http::timeout(3)->post($url . '/embed', ['texts' => $texts]);
            if (!$response->ok()) {
                Log::warning('ContentEmbedding: sidecar returned non-OK', [
                    'status' => $response->status(),
                ]);
                return null;
            }
            return $response->json('embeddings');
        } catch (\Throwable $e) {
            Log::debug('ContentEmbedding: sidecar unavailable', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function meanCosineSim(array $vec, array $others): float
    {
        if (empty($others)) {
            return 0.0;
        }
        $total = 0.0;
        foreach ($others as $other) {
            $total += $this->cosineSim($vec, $other);
        }
        return $total / count($others);
    }

    private function cosineSim(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        $len = min(count($a), count($b));
        for ($i = 0; $i < $len; $i++) {
            $dot   += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }
        if ($normA === 0.0 || $normB === 0.0) {
            return 0.0;
        }
        return $dot / (sqrt($normA) * sqrt($normB));
    }
}
