<?php

namespace Tests\Unit\Services\Judgement;

use App\Services\Judgement\Verdict;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VerdictTest extends TestCase
{
    #[Test]
    public function unavailable_is_not_available_and_has_no_answers(): void
    {
        $verdict = Verdict::unavailable();

        $this->assertFalse($verdict->available);
        $this->assertSame([], $verdict->answers);
        $this->assertNull($verdict->answer('free'));
    }

    #[Test]
    public function yes_at_or_above_threshold_is_true_only_at_or_above_it(): void
    {
        $verdict = new Verdict(available: true, answers: [
            'animal' => ['answer' => 'yes', 'confidence' => 0.8, 'reason' => 'A live animal.'],
        ]);

        $this->assertTrue($verdict->yesAtOrAbove('animal', 0.8));
        $this->assertTrue($verdict->yesAtOrAbove('animal', 0.5));
        $this->assertFalse($verdict->yesAtOrAbove('animal', 0.81));
    }

    #[Test]
    public function yes_at_or_above_is_false_for_a_no_answer(): void
    {
        $verdict = new Verdict(available: true, answers: [
            'animal' => ['answer' => 'no', 'confidence' => 0.99, 'reason' => 'Just a cage.'],
        ]);

        $this->assertFalse($verdict->yesAtOrAbove('animal', 0.1));
        $this->assertTrue($verdict->noAtOrAbove('animal', 0.9));
    }

    #[Test]
    public function yes_at_or_above_is_false_for_a_missing_question(): void
    {
        $verdict = new Verdict(available: true, answers: []);

        $this->assertFalse($verdict->yesAtOrAbove('animal', 0.0));
        $this->assertFalse($verdict->noAtOrAbove('animal', 0.0));
    }

    #[Test]
    public function first_takedown_reason_returns_the_first_matching_question_in_order(): void
    {
        $verdict = new Verdict(available: true, answers: [
            'free'   => ['answer' => 'no', 'confidence' => 0.9, 'reason' => 'Free item.'],
            'animal' => ['answer' => 'yes', 'confidence' => 0.9, 'reason' => 'A live animal.'],
            'unsafe_by_design' => ['answer' => 'yes', 'confidence' => 0.95, 'reason' => 'A weapon.'],
        ]);

        $reason = $verdict->firstTakedownReason(['free', 'animal', 'unsafe_by_design'], 0.8);

        $this->assertSame('animal', $reason['id']);
        $this->assertSame('yes', $reason['answer']);
    }

    #[Test]
    public function first_takedown_reason_skips_yeses_below_threshold(): void
    {
        $verdict = new Verdict(available: true, answers: [
            'animal' => ['answer' => 'yes', 'confidence' => 0.5, 'reason' => 'Maybe a live animal.'],
            'unsafe_by_design' => ['answer' => 'yes', 'confidence' => 0.95, 'reason' => 'A weapon.'],
        ]);

        $reason = $verdict->firstTakedownReason(['animal', 'unsafe_by_design'], 0.8);

        $this->assertSame('unsafe_by_design', $reason['id']);
    }

    #[Test]
    public function first_takedown_reason_null_when_nothing_qualifies(): void
    {
        $verdict = new Verdict(available: true, answers: [
            'animal' => ['answer' => 'no', 'confidence' => 0.99, 'reason' => 'No animal.'],
        ]);

        $this->assertNull($verdict->firstTakedownReason(['animal', 'unsafe_by_design'], 0.8));
    }
}
