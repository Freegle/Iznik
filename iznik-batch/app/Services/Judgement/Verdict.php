<?php

namespace App\Services\Judgement;

/**
 * The judge's answer to every question asked about one Subject, plus whether the judge could
 * be reached at all.
 */
class Verdict
{
    /**
     * @param bool $available False when the judge was unreachable, timed out, errored, or
     *   returned something that could not be parsed as an answer. Callers must treat that as
     *   "no signal", never as a no answered "no" (ai-judgement.md: JudgementUnavailable never
     *   holds or takes anything down).
     * @param array<string, array{answer: string, confidence: float, reason: string}> $answers
     *   Keyed by question id. answer is 'yes' or 'no'.
     * @param int $inputTokens
     * @param int $outputTokens
     */
    public function __construct(
        public readonly bool $available,
        public readonly array $answers = [],
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
    ) {
    }

    public static function unavailable(): self
    {
        return new self(available: false);
    }

    public function answer(string $questionId): ?array
    {
        return $this->answers[$questionId] ?? null;
    }

    /** Whether $questionId was answered "yes" with confidence at or above $threshold. */
    public function yesAtOrAbove(string $questionId, float $threshold): bool
    {
        $a = $this->answer($questionId);
        return $a !== null && $a['answer'] === 'yes' && (float) $a['confidence'] >= $threshold;
    }

    /** Whether $questionId was answered "no" with confidence at or above $threshold. */
    public function noAtOrAbove(string $questionId, float $threshold): bool
    {
        $a = $this->answer($questionId);
        return $a !== null && $a['answer'] === 'no' && (float) $a['confidence'] >= $threshold;
    }

    /**
     * The first takedown-outcome question answered "yes" at or above $threshold, in the order
     * given, or null if none. Used to pick the one reason a takedown is reported in.
     */
    public function firstTakedownReason(array $takedownQuestionIds, float $threshold): ?array
    {
        foreach ($takedownQuestionIds as $id) {
            if ($this->yesAtOrAbove($id, $threshold)) {
                return ['id' => $id] + $this->answer($id);
            }
        }
        return null;
    }
}
