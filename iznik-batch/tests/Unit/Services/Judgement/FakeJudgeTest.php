<?php

namespace Tests\Unit\Services\Judgement;

use App\Services\Judgement\FakeJudge;
use App\Services\Judgement\Subject;
use App\Services\Judgement\Verdict;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FakeJudgeTest extends TestCase
{
    #[Test]
    public function defaults_every_question_to_a_confident_no(): void
    {
        $judge   = new FakeJudge();
        $subject = new Subject(kind: Subject::KIND_CHAT, body: 'hiya, free to good home');

        $verdict = $judge->judge($subject);

        $this->assertTrue($verdict->available);
        foreach (['free', 'scam', 'decent'] as $id) {
            $this->assertSame('no', $verdict->answer($id)['answer']);
            $this->assertSame(0.99, $verdict->answer($id)['confidence']);
        }
    }

    #[Test]
    public function when_matches_a_substring_of_title_body_or_report_reason(): void
    {
        $judge = (new FakeJudge())->when('parrot', [
            'animal' => ['answer' => 'yes', 'confidence' => 0.95, 'reason' => 'A live parrot.'],
        ]);

        $verdict = $judge->judge(new Subject(kind: Subject::KIND_POST, title: 'African Grey Parrot'));

        $this->assertTrue($verdict->yesAtOrAbove('animal', 0.9));
        // Other questions for this kind are still answered (backfilled to "no").
        $this->assertTrue($verdict->noAtOrAbove('unsafe_by_design', 0.9));
    }

    #[Test]
    public function match_is_case_insensitive_and_checks_report_reason_too(): void
    {
        $judge = (new FakeJudge())->when('SCAM', [
            'report' => ['answer' => 'yes', 'confidence' => 0.9, 'reason' => 'Looks like a scam.'],
        ]);

        $verdict = $judge->judge(new Subject(
            kind: Subject::KIND_REPORT,
            title: 'Free sofa',
            reportReason: 'this looks like a scam to me',
        ));

        $this->assertTrue($verdict->yesAtOrAbove('report', 0.8));
    }

    #[Test]
    public function unavailable_when_forces_an_unavailable_verdict(): void
    {
        $judge = (new FakeJudge())->unavailableWhen('broken');

        $verdict = $judge->judge(new Subject(kind: Subject::KIND_POST, title: 'broken washing machine'));

        $this->assertFalse($verdict->available);
        $this->assertSame([], $verdict->answers);
    }

    #[Test]
    public function default_to_overrides_the_no_match_fallback(): void
    {
        $custom = new Verdict(available: true, answers: ['scam' => ['answer' => 'yes', 'confidence' => 1.0, 'reason' => 'x']]);
        $judge  = (new FakeJudge())->defaultTo($custom);

        $verdict = $judge->judge(new Subject(kind: Subject::KIND_CHAT, body: 'nothing special'));

        $this->assertSame($custom, $verdict);
    }

    #[Test]
    public function first_matching_rule_wins(): void
    {
        $judge = (new FakeJudge())
            ->when('sofa', ['free' => ['answer' => 'no', 'confidence' => 0.9, 'reason' => 'Free item.']])
            ->when('sofa for sale', ['free' => ['answer' => 'yes', 'confidence' => 0.9, 'reason' => 'Asking for money.']]);

        $verdict = $judge->judge(new Subject(kind: Subject::KIND_POST, title: 'Sofa for sale, £50'));

        $this->assertTrue($verdict->noAtOrAbove('free', 0.8));
    }
}
