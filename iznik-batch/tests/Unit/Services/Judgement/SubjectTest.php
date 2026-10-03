<?php

namespace Tests\Unit\Services\Judgement;

use App\Services\Judgement\Subject;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SubjectTest extends TestCase
{
    #[Test]
    public function post_gets_the_full_question_set(): void
    {
        $subject = new Subject(kind: Subject::KIND_POST, title: 'Sofa');

        $ids = $subject->questionIds();

        $this->assertContains('free', $ids);
        $this->assertContains('legal', $ids);
        $this->assertContains('animal', $ids);
        $this->assertContains('unsafe', $ids);
        $this->assertContains('vague', $ids);
        $this->assertNotContains('report', $ids);
    }

    #[Test]
    public function chat_gets_only_free_scam_decent(): void
    {
        $subject = new Subject(kind: Subject::KIND_CHAT, body: 'hello');

        $this->assertSame(['free', 'scam', 'decent'], $subject->questionIds());
    }

    #[Test]
    public function event_volunteering_and_newsfeed_get_only_scam_and_decent(): void
    {
        foreach ([Subject::KIND_EVENT, Subject::KIND_VOLUNTEERING, Subject::KIND_NEWSFEED] as $kind) {
            $subject = new Subject(kind: $kind, title: 'x');
            $this->assertSame(['scam', 'decent'], $subject->questionIds(), "kind={$kind}");
        }
    }

    #[Test]
    public function report_gets_the_post_questions_plus_report(): void
    {
        $subject = new Subject(kind: Subject::KIND_REPORT, title: 'Sofa', reportReason: 'looks like a scam');

        $ids = $subject->questionIds();

        $this->assertContains('report', $ids);
        $this->assertContains('scam', $ids);
        $this->assertContains('animal', $ids);
    }

    #[Test]
    public function carries_no_member_identifying_fields(): void
    {
        // Guards ai-judgement.md's "no member identifiers, emails or locations are sent" —
        // Subject's constructor simply has no such parameters to pass.
        $ref = new \ReflectionClass(Subject::class);
        $paramNames = array_map(fn ($p) => $p->getName(), $ref->getConstructor()->getParameters());

        foreach (['userid', 'email', 'location', 'lat', 'lng', 'name', 'address'] as $banned) {
            $this->assertNotContains($banned, $paramNames);
        }
    }
}
