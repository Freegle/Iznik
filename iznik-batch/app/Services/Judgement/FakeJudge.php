<?php

namespace App\Services\Judgement;

/**
 * Test double bound in place of ClaudeJudge. Canned answers keyed by a substring of the
 * subject's title+body+reportReason (case-insensitive); the first match wins. Nothing in the
 * test suite calls the network — every test that touches the judge uses this.
 */
class FakeJudge implements Judge
{
    /** @var array<int, array{substring: string, answers: array, available?: bool}> */
    protected array $rules = [];

    protected ?Verdict $default = null;

    /**
     * @param array $answers e.g. ['animal' => ['answer' => 'yes', 'confidence' => 0.95, 'reason' => '...']]
     */
    public function when(string $substring, array $answers): static
    {
        $this->rules[] = ['substring' => $substring, 'answers' => $answers];
        return $this;
    }

    /** The next judge() call (matching or not) returns "unavailable". */
    public function unavailableWhen(string $substring): static
    {
        $this->rules[] = ['substring' => $substring, 'answers' => [], 'available' => false];
        return $this;
    }

    /** Fallback verdict when no rule's substring matches. Defaults to all-clean (every question "no"). */
    public function defaultTo(Verdict $verdict): static
    {
        $this->default = $verdict;
        return $this;
    }

    public function judge(Subject $subject): Verdict
    {
        $haystack = mb_strtolower(implode(' ', array_filter([
            $subject->title, $subject->body, $subject->itemName, $subject->reportReason,
        ])));

        foreach ($this->rules as $rule) {
            if ($haystack !== '' && str_contains($haystack, mb_strtolower($rule['substring']))) {
                if (($rule['available'] ?? true) === false) {
                    return Verdict::unavailable();
                }
                return $this->fill($subject, $rule['answers']);
            }
        }

        if ($this->default !== null) {
            return $this->default;
        }

        return $this->fill($subject, []);
    }

    /** Every question the subject is asked gets a "no", confidence 0.99, unless overridden. */
    protected function fill(Subject $subject, array $answers): Verdict
    {
        $full = [];
        foreach ($subject->questionIds() as $id) {
            $full[$id] = $answers[$id] ?? ['answer' => 'no', 'confidence' => 0.99, 'reason' => 'Nothing of concern.'];
        }
        return new Verdict(available: true, answers: $full, inputTokens: 0, outputTokens: 0);
    }
}
