<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Writes electricals classifications into production MySQL (`messages_eee`).
 *
 * The research pipeline persists a very wide row into a dev-side SQLite file, holding every
 * attribute and several models side by side so they can be scored against human labels. That
 * stays as it is. This service is the narrow production projection of the same run: only the
 * fields the public page is allowed to use, in MySQL, where the API can read them.
 *
 * Two deliberate narrowings:
 *
 *  - `is_eee` is taken from the component rule (`is_eee_from_components`), never from the
 *    model's own overall verdict. Asking a model "is this EEE?" was measured as unreliable on
 *    exactly the boundary cases that matter; observing components and deciding by rule is
 *    auditable and was the central finding of the research.
 *  - Weight and size are stored only as buckets. Per-item weight is 65% accurate and size 72%
 *    against volunteer quorum, so neither may become a published figure. Published tonnage
 *    comes from the `weights` reference table instead.
 */
class EeeProductionStore
{
    /**
     * Longest-dimension thresholds in cm for the size buckets, matching the buckets the
     * micro-volunteering task put in front of members so the two remain comparable.
     */
    protected const SIZE_THRESHOLDS_CM = [
        'tiny'   => 15,
        'small'  => 40,
        'medium' => 80,
        // anything above the last threshold is 'large'
    ];

    /** Upper bounds in kg for the weight buckets. */
    protected const WEIGHT_THRESHOLDS_KG = [
        'under_1kg' => 1,
        '1_5kg'     => 5,
        '5_20kg'    => 20,
        '20_100kg'  => 100,
        // above that is 'over_100kg'
    ];

    /**
     * Upsert one classification. Keyed on (msgid, model, prompt_version) so a re-run of the
     * same model and prompt corrects the row rather than duplicating it, and a different model
     * lands alongside for comparison.
     *
     * $data is the wide array the classifier builds for SQLite; only the used keys matter.
     */
    public function upsert(array $data): void
    {
        $msgid = (int) ($data['messageid'] ?? 0);
        if ($msgid === 0) {
            return;
        }

        $row = [
            'msgid'                   => $msgid,
            'attid'                   => isset($data['attid']) ? (int) $data['attid'] : null,
            'is_eee'                  => $this->normaliseTriState($data['is_eee_from_components'] ?? null),
            'is_eee_reason'           => $data['is_eee_reason'] ?? null,
            'contains_eee_components' => $this->normaliseTriState($data['contains_eee_components'] ?? null),
            'weee_category'           => isset($data['weee_category']) ? (int) $data['weee_category'] : null,
            'item_condition'          => $this->conditionBucket($data['condition'] ?? null),
            'size_bucket'             => $this->sizeBucket($data['size_cm'] ?? null),
            'weight_bucket'           => $this->weightBucket(
                $data['weight_kg_min'] ?? null,
                $data['weight_kg_max'] ?? null,
            ),
            'electrical_components'   => $data['electrical_components_description'] ?? null,
            'model'                   => (string) ($data['model'] ?? 'unknown'),
            'prompt_version'          => (string) ($data['prompt_version'] ?? '0'),
            'classified_at'           => now()->toDateTimeString(),
        ];

        DB::table('messages_eee')->upsert(
            [$row],
            ['msgid', 'model', 'prompt_version'],
            [
                'attid', 'is_eee', 'is_eee_reason', 'contains_eee_components', 'weee_category',
                'item_condition', 'size_bucket', 'weight_bucket', 'electrical_components',
                'classified_at',
            ],
        );
    }

    /**
     * Newest message arrival already classified under this model and prompt, for the
     * incremental run's high-water mark.
     *
     * Reads `messages.arrival` rather than `classified_at`, because the mark has to be a
     * position in the message stream, not a wall-clock time. Using the classification time
     * would skip anything that arrived while the previous run was in flight.
     */
    public function highWaterMark(string $model, string $promptVersion): ?string
    {
        $mark = DB::table('messages_eee')
            ->join('messages', 'messages.id', '=', 'messages_eee.msgid')
            ->where('messages_eee.model', $model)
            ->where('messages_eee.prompt_version', $promptVersion)
            ->max('messages.arrival');

        return $mark ?: null;
    }

    /** True if this message already has a row for the model and prompt. */
    public function has(int $msgid, string $model, string $promptVersion): bool
    {
        return DB::table('messages_eee')
            ->where('msgid', $msgid)
            ->where('model', $model)
            ->where('prompt_version', $promptVersion)
            ->exists();
    }

    // ── Mapping helpers ───────────────────────────────────────────────────────

    /**
     * 1/0/null passthrough. null must survive: it means the model observed nothing, which the
     * page has to exclude from denominators rather than count as "not electrical".
     */
    protected function normaliseTriState(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value ? 1 : 0;
    }

    /**
     * Collapse the model's free-text condition onto the three buckets volunteers were shown.
     *
     * Ordered damaged-first: "spares or repair" and "working but scratched" both contain a
     * reusable-ish word, and the damage signal is the one worth keeping.
     */
    protected function conditionBucket(?string $condition): ?string
    {
        if ($condition === null || trim($condition) === '') {
            return null;
        }

        $lower = strtolower($condition);

        $damaged = ['damag', 'broken', 'faulty', 'spares', 'repair', 'not working', 'cracked', 'incomplete'];
        foreach ($damaged as $needle) {
            if (str_contains($lower, $needle)) {
                return 'damaged';
            }
        }

        $reusable = ['reusable', 'working', 'good', 'new', 'excellent', 'fine', 'used but', 'fair'];
        foreach ($reusable as $needle) {
            if (str_contains($lower, $needle)) {
                return 'reusable';
            }
        }

        return 'unsure';
    }

    /**
     * Bucket by longest dimension. $sizeCm is the JSON the model returns, which may be an
     * object of named dimensions or a flat list.
     */
    protected function sizeBucket(mixed $sizeCm): ?string
    {
        $longest = $this->longestDimension($sizeCm);
        if ($longest === null) {
            return null;
        }

        foreach (self::SIZE_THRESHOLDS_CM as $bucket => $limit) {
            if ($longest < $limit) {
                return $bucket;
            }
        }

        return 'large';
    }

    /** Largest numeric value anywhere in the size structure, or null if there is none. */
    protected function longestDimension(mixed $sizeCm): ?float
    {
        if (is_string($sizeCm)) {
            $sizeCm = json_decode($sizeCm, true);
        }

        if (!is_array($sizeCm)) {
            return null;
        }

        $longest = null;
        array_walk_recursive($sizeCm, function ($value) use (&$longest) {
            if (is_numeric($value) && (float) $value > 0) {
                $longest = max($longest ?? 0.0, (float) $value);
            }
        });

        return $longest;
    }

    /** Bucket by the midpoint of the model's weight range. */
    protected function weightBucket(mixed $min, mixed $max): ?string
    {
        $values = array_values(array_filter(
            [$min, $max],
            fn($v) => is_numeric($v) && (float) $v > 0,
        ));

        if ($values === []) {
            return null;
        }

        $midpoint = array_sum(array_map('floatval', $values)) / count($values);

        foreach (self::WEIGHT_THRESHOLDS_KG as $bucket => $limit) {
            if ($midpoint < $limit) {
                return $bucket;
            }
        }

        return 'over_100kg';
    }
}
