<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Component knowledge base for deterministic EEE classification.
 *
 * The model observes electrical components in images; this service maps those
 * raw strings to canonical component types with categories:
 *   primary_eee      — primary function of the item requires electricity (motor, compressor, heating element)
 *   supplementary_eee — incidental electrical parts (clock, ignition, indicator lights)
 *   non_electrical   — mechanical/structural parts with no electrical function
 *   unknown          — needs manual review
 *
 * EEE decision rule:
 *   ANY primary_eee component present  → is_eee = true
 *   Only supplementary_eee components  → is_eee deferred to text signals
 *   No EEE components at all           → is_eee = false
 */
class EeeComponentService
{
    protected const EMBEDDING_MODEL = 'text-embedding-3-small';
    protected const EMBEDDING_DIMS  = 1536;
    protected const SIMILARITY_THRESHOLD = 0.60;

    /** Keyword rules for initial auto-categorisation (regex on lowercase canonical name). */
    protected const CATEGORY_RULES = [
        'primary_eee' => [
            // Motor / drive
            '/\bmotor\b/',
            '/\bcompressor\b/',
            '/\bdrum assembly\b/',
            '/\belectric fan\b/',
            '/\bfan motor\b/',
            '/\bpump motor\b/',
            '/\belectric pump\b/',
            '/mains.?powered motor/',
            // Heating as primary function
            '/\bheating element(s)?\b/',
            '/\bgrill element\b/',
            '/\bquartz.*grill\b/',
            '/\bmagnetron\b/',
            '/\belectric (oven|hob|cooker)\b/',
            '/\bceramic (glass )?hob\b/',
            '/\bceramic\/glass hob\b/',
            '/\belectric ceramic\b/',
            '/\belectric.*hob\b/',
            '/\bhob heating element\b/',
            '/\bdouble oven\b/',
            '/\boven cavity\b/',
            '/\boven with.*element\b/',
            '/mains.?powered.*oven/',
            '/mains.?powered.*microwave/',
            '/mains.*cooker\b/',
            '/\bmains electric cooker\b/',
            '/\bsubmersible heater\b/',
            '/\baquarium heater\b/',
            '/\bheater\b/',
            // Batteries / power storage (primary function of laptops, tablets, cordless tools)
            '/\brechargeable battery\b/',
            '/\brechargeable lithium/',
            '/\binternal.*battery\b/',
            '/\bbuilt.in.*battery\b/',
            '/\blithium.*battery\b/',
            '/\bbattery pack\b/',
            // Printing mechanisms (primary function of printers)
            '/print(ing)? (head|mechanism|engine)/',
            '/\blaser print/',
            '/\binkjet print/',
            '/mains.?powered.*print/',
            // Signal processing / tuners (primary function of set-top boxes, radios)
            '/\btuner\b/',
            '/\bsignal tuner\b/',
            '/\bfreeview tuner\b/',
            '/\bdvb.t\b/',
            '/\bsignal processor\b/',
            // Image sensors (primary function of cameras)
            '/\bimage sensor\b/',
            '/\b(ccd|cmos) (image )?sensor\b/',
            // Chain/trigger for power tools
            '/\btrigger switch\b/',
            '/\bon\/off trigger\b/',
            // Display panels as primary function (TVs / monitors — model uses v1.4.2 inference suffix)
            '/\bbuilt.in (lcd|led) (panel|screen|display)\b/i',
            '/primary EEE component/i',
            // Audio transducers (speakers, hi-fi)
            '/\baudio amplifier\b/i',
            '/\bspeaker driver\b/i',
            '/\bvoice coil\b/i',
            // Suction motors (vacuums — belt-drive "motor" already matched by /\bmotor\b/, belt-drive explicit)
            '/\belectric suction motor\b/i',
            '/\bsuction motor\b/i',
            // Light-emitting primary elements (LED/CFL bulbs, lamps)
            '/\blight.emitting element\b/i',
            '/\bLED (chip|element|driver)\b/i',
            '/\blight.emitting diode element\b/i',
        ],
        'supplementary_eee' => [
            // Display / indicators
            '/digital (display|clock|timer)/',
            '/\bdisplay panel\b/',
            '/\belectronic display\b/',
            '/\bindicator panel\b/',
            '/digital.*panel/',
            '/\bdigital console\b/',
            '/\btemperature control electronic/',
            // Switches / on-off controls
            '/\bon\/off switch\b/',
            '/\bpower switch\b/',
            '/\bpower.*control\b/',
            '/\bswitch.*trigger mechanism\b/',
            '/\bcoiled cable\b/',
            // Ignition
            '/electronic ignition/',
            '/electric ignition/',
            // Lights (not primary function)
            '/indicator light/',
            '/\bled (light|lighting|lights|accent|string|fairy)\b/',
            '/\bled\/fairy\b/',
            '/\bfairy light/',
            '/\bstring light/',
            '/\bpre.installed led\b/',
            '/\bpre.lit\b/',
            '/multicolour led/',
            '/interior (light|lighting|led)/',
            '/interior cavity light/',
            '/internal lighting/',
            '/\bneedle.*light/',
            '/\bsewing light\b/',
            '/\bbuilt.in.*light(ing)?\b/',
            '/\bhood.*light\b/',
            '/\blid.*light\b/',
            '/oven.*light/',
            '/\blight unit\b/',
            // Clocks/timers
            '/\bdigital clock\b/',
            '/\bdigital timer\b/',
            '/\btimer dial\b/',
            '/\bclock module\b/',
            '/\boven timer\b/',
            // Control interfaces
            '/control (button|panel|keypad)/',
            '/push.?button/',
            '/\bstart and stop button\b/',
            '/\bstart.*stop.*button/',
            '/programme selector/',
            '/\bmembrane.*keypad\b/',
            '/\bstitch selector\b/',
            // Sensors
            '/\bpulse sensor/',
            '/\bheart rate sensor/',
            '/handlebar.*sensor/',
            '/handlebar.*display/',
            // Electronic controls (not primary function)
            '/\belectronic (control|resistance)\b/',
            '/\belectronic resistance control\b/',
            // Foot pedals (supplementary speed control)
            '/\bfixed.?pedal\b/',
            '/\bfoot pedal\b/',
            // Mains power connections (confirm electrical, not primary function)
            '/\bmains power (flex|cable|cord|connection|plug|adapter)/',
            '/\bmains power flex\b/',
            '/mains.?powered appliance/',
            '/\belectrical cable/',
            '/\bpower cable/',
            '/\bpower flex\b/',
            '/\bmains hardwire\b/',
            '/\bmains appliance\b/',
            '/mains\/power connection/',
            '/\bplug socket\b/',
            '/\buk (3-pin|plug)\b/',
            // Safety switches/guards
            '/\bsafety (switch|guard|button|key)\b/',
            '/\block.off switch\b/',
            '/\bmagnetic safety key\b/',
        ],
        'non_electrical' => [
            '/\bdrain hose\b/',
            '/\bfilter\b(?! electronic)/',
            '/\bfabric\b/',
            '/\bdoor seal\b/',
            '/\bglass turntable\b/',
            '/\bturntable plate\b/',
            '/\bdetergent drawer\b/',
            '/\bdoor lock mechanism\b/',
            '/\bdoor interlock mechanism\b/',
            '/\bdoor (handle|hinge)\b(?!.*control)/',
            '/\bdoor with (mesh|microwave|glass)\b/',
            '/\bdoor handles? with gold/',
            '/\bintegrated door hinge/',
            '/\brotary (dial|knob)\b(?! (power|function))/',
            '/\banalog.*timer dial\b/',
            '/\bmechanical timer\b/',
            '/\brotary control knob\b/',
            '/\bcontrol knob(s)?\b(?! (with|electronic))/',
            '/\bwarning label\b/',
            '/\bsmart energy monitor\b/',
            '/\bsmall display.*non.functional\b/',
        ],
    ];

    public function __construct(protected EeeSqliteService $sqlite) {}

    /** Return true if the component index needs to be built (table is empty). */
    public function needsBuilding(): bool
    {
        $pdo  = $this->sqlite->getPdo();
        $row  = $pdo->query("SELECT COUNT(*) FROM eee_component_types")->fetchColumn();
        return (int) $row === 0;
    }

    /**
     * Build the component index from observed component strings in eee_classifications.
     * Idempotent: skips strings already in the index.
     */
    public function buildIndex(callable $progress = null): array
    {
        $rawStrings = $this->collectRawStrings();

        if (empty($rawStrings)) {
            return ['added' => 0, 'skipped' => 0, 'total' => 0];
        }

        $existing = $this->getExistingRawStrings();
        $toIndex  = array_values(array_diff($rawStrings, $existing));

        if (empty($toIndex)) {
            return ['added' => 0, 'skipped' => count($rawStrings), 'total' => count($rawStrings)];
        }

        $embeddings = $this->fetchEmbeddingsBatch($toIndex, $progress);
        $added      = 0;

        foreach ($toIndex as $i => $raw) {
            $embedding = $embeddings[$i] ?? null;
            $category  = $this->autoCategory($raw);

            $this->sqlite->upsertComponentType([
                'canonical_name' => $raw,
                'category'       => $category,
                'embedding'      => $embedding ? $this->packEmbedding($embedding) : null,
                'raw_strings'    => json_encode([$raw]),
            ]);

            $added++;
        }

        return [
            'added'   => $added,
            'skipped' => count($rawStrings) - count($toIndex),
            'total'   => count($rawStrings),
        ];
    }

    /**
     * Find the canonical component type for a raw string.
     * Returns ['canonical_name', 'category', 'similarity'] or null if no match.
     */
    public function lookup(string $rawComponent): ?array
    {
        $raw = strtolower(trim($rawComponent));
        if (empty($raw)) return null;

        // Exact match first.
        $exact = $this->sqlite->getComponentTypeByName($raw);
        if ($exact) {
            return ['canonical_name' => $exact['canonical_name'], 'category' => $exact['category'], 'similarity' => 1.0];
        }

        // Vector similarity search.
        $embedding = $this->fetchEmbedding($raw);
        if (!$embedding) return null;

        return $this->findNearest($embedding, self::SIMILARITY_THRESHOLD);
    }

    /**
     * Classify a set of raw component strings and return the aggregate EEE verdict.
     *
     * @param  string[]  $components  Raw component strings from the model
     * @return array{is_eee: bool|null, contains_eee_components: bool, categories: array, unmatched: array}
     */
    public function classifyComponents(array $components): array
    {
        $categories  = [];
        $unmatched   = [];
        $hasPrimary  = false;
        $hasSupp     = false;

        foreach ($components as $raw) {
            $match = $this->lookup($raw);
            if ($match) {
                $categories[$raw] = $match;
                if ($match['category'] === 'primary_eee')      $hasPrimary = true;
                if ($match['category'] === 'supplementary_eee') $hasSupp    = true;
            } else {
                $unmatched[] = $raw;
            }
        }

        return [
            'is_eee'                  => $hasPrimary ? true : ($hasSupp ? null : (empty($components) ? null : false)),
            'contains_eee_components' => $hasPrimary || $hasSupp,
            'categories'              => $categories,
            'unmatched'               => $unmatched,
        ];
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    protected function collectRawStrings(): array
    {
        $pdo  = $this->sqlite->getPdo();
        $rows = $pdo->query("
            SELECT DISTINCT LOWER(TRIM(part)) AS raw
            FROM (
                SELECT value AS part
                FROM eee_classifications, json_each(
                    '[\"' || REPLACE(electrical_components_description, ';', '\",\"') || '\"]'
                )
                WHERE electrical_components_description IS NOT NULL
                  AND electrical_components_description != ''
            )
            WHERE TRIM(part) != ''
        ")->fetchAll(\PDO::FETCH_COLUMN);

        return array_values(array_unique(array_filter($rows)));
    }

    protected function getExistingRawStrings(): array
    {
        $pdo = $this->sqlite->getPdo();
        return $pdo->query("SELECT LOWER(canonical_name) FROM eee_component_types")
                   ->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function autoCategory(string $raw): string
    {
        $lower = strtolower(trim($raw));
        foreach (self::CATEGORY_RULES as $category => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $lower)) {
                    return $category;
                }
            }
        }
        return 'unknown';
    }

    /** Fetch a single embedding from Gemini text-embedding-004. */
    protected function fetchEmbedding(string $text): ?array
    {
        $results = $this->fetchEmbeddingsBatch([$text]);
        return $results[0] ?? null;
    }

    /** Batch-fetch embeddings via OpenAI text-embedding-3-small (max 2048 per call). */
    protected function fetchEmbeddingsBatch(array $texts, callable $progress = null): array
    {
        $apiKey  = config('freegle.eee.openai_api_key');
        $model   = self::EMBEDDING_MODEL;
        $url     = 'https://api.openai.com/v1/embeddings';
        $results = [];
        $chunks  = array_chunk($texts, 100, true);

        foreach ($chunks as $chunk) {
            try {
                $response = Http::timeout(60)
                    ->withToken($apiKey)
                    ->post($url, ['model' => $model, 'input' => array_values($chunk)]);

                if (!$response->successful()) {
                    Log::error('EeeComponentService embed failed', ['status' => $response->status(), 'body' => $response->body()]);
                    foreach (array_keys($chunk) as $origIdx) {
                        $results[$origIdx] = null;
                    }
                    continue;
                }

                $data = $response->json('data', []);
                foreach (array_keys($chunk) as $i => $origIdx) {
                    $results[$origIdx] = $data[$i]['embedding'] ?? null;
                }
            } catch (\Throwable $e) {
                Log::error('EeeComponentService embed exception', ['error' => $e->getMessage()]);
                foreach (array_keys($chunk) as $origIdx) {
                    $results[$origIdx] = null;
                }
            }

            if ($progress) {
                $progress(count($results), count($texts));
            }
        }

        return array_values($results);
    }

    protected function findNearest(array $queryEmbedding, float $threshold): ?array
    {
        $pdo  = $this->sqlite->getPdo();
        $rows = $pdo->query("SELECT canonical_name, category, embedding FROM eee_component_types WHERE embedding IS NOT NULL")
                    ->fetchAll(\PDO::FETCH_ASSOC);

        $best     = null;
        $bestSim  = -1.0;

        foreach ($rows as $row) {
            $vec = $this->unpackEmbedding($row['embedding']);
            if (!$vec) continue;
            $sim = $this->cosineSimilarity($queryEmbedding, $vec);
            if ($sim > $bestSim) {
                $bestSim = $sim;
                $best    = $row;
            }
        }

        if ($best === null || $bestSim < $threshold) return null;

        return [
            'canonical_name' => $best['canonical_name'],
            'category'       => $best['category'],
            'similarity'     => $bestSim,
        ];
    }

    protected function cosineSimilarity(array $a, array $b): float
    {
        $dot = 0.0;
        $magA = 0.0;
        $magB = 0.0;
        $n = min(count($a), count($b));
        for ($i = 0; $i < $n; $i++) {
            $dot  += $a[$i] * $b[$i];
            $magA += $a[$i] * $a[$i];
            $magB += $b[$i] * $b[$i];
        }
        if ($magA === 0.0 || $magB === 0.0) return 0.0;
        return $dot / (sqrt($magA) * sqrt($magB));
    }

    protected function packEmbedding(array $floats): string
    {
        return pack('f*', ...$floats);
    }

    protected function unpackEmbedding(string $blob): ?array
    {
        if (empty($blob)) return null;
        $unpacked = unpack('f*', $blob);
        return $unpacked ? array_values($unpacked) : null;
    }
}
