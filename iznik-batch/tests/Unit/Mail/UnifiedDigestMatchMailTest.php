<?php

namespace Tests\Unit\Mail;

use App\Mail\Digest\UnifiedDigest;
use App\Services\UnifiedDigestService;
use Illuminate\Mail\Mailable;
use Tests\Support\IsolatedSpoolDirectory;
use Tests\TestCase;

/**
 * Match mail: the immediate-digest layout, sent to somebody whose own open post
 * of the opposite type, or saved search, matches this one
 * (App\Services\FirstReply\MatchMailService).
 *
 * Two things have to distinguish it from an ordinary digest, and they are the
 * two things under test here. The subject is the post's own, so the inbox line
 * is the item rather than the "[Group] ..." shape every other Freegle mail
 * shares; and the body opens by saying why this mail is for this person. A copy
 * of the digest sent sooner is indistinguishable from the digest they are
 * already not opening, and the entire value of this one is that it answers
 * something they asked for.
 */
class UnifiedDigestMatchMailTest extends TestCase
{
    use IsolatedSpoolDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIsolatedSpoolDirectory();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedSpoolDirectory();
        parent::tearDown();
    }

    /** @return array{0:UnifiedDigest, 1:string} [mailable, recipient email] */
    private function digestFor(?string $matchReason, string $subject = 'OFFER: Bookcase (Ealing)'): array
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $poster = $this->createTestUser();
        $this->createMembership($poster, $group);

        $message = $this->createTestMessage($poster, $group, ['subject' => $subject]);
        $posts = collect([['message' => $message, 'postedToGroups' => [$group->id]]]);

        return [
            new UnifiedDigest(
                $user, $posts, UnifiedDigestService::MODE_IMMEDIATE,
                matchReason: $matchReason
            ),
            $user->email_preferred,
        ];
    }

    private function spoolAndLoad(Mailable $mailable, string $recipient): array
    {
        $id = $this->spooler->spool($mailable, $recipient);

        return json_decode(file_get_contents($this->testSpoolDir . '/pending/' . $id . '.json'), true) ?? [];
    }

    public function test_the_subject_is_the_post_itself_not_the_usual_group_prefix(): void
    {
        [$mail] = $this->digestFor('wanted');

        $this->assertSame('OFFER: Bookcase (Ealing)', $this->spoolAndLoad($mail, 'a@example.com')['subject']);
    }

    public function test_an_ordinary_immediate_digest_keeps_its_group_prefix(): void
    {
        // The prefix is not being removed from digests, only from match mail -
        // otherwise this test would pass for the wrong reason.
        [$mail] = $this->digestFor(null);

        $this->assertStringStartsWith('[', $this->spoolAndLoad($mail, 'a@example.com')['subject']);
        $this->assertStringContainsString('OFFER: Bookcase (Ealing)', $this->spoolAndLoad($mail, 'a@example.com')['subject']);
    }

    public function test_an_open_post_of_theirs_is_named_as_the_reason(): void
    {
        [$mail] = $this->digestFor('wanted');

        $intro = $mail->matchIntro();
        $this->assertNotNull($intro);
        $this->assertStringContainsString('open post', $intro);
        $this->assertStringContainsStringIgnoringCase('Bookcase', $intro, 'says which item, not just "a match"');
    }

    public function test_a_saved_search_is_named_as_the_reason(): void
    {
        [$mail] = $this->digestFor('search');

        $intro = $mail->matchIntro();
        $this->assertNotNull($intro);
        $this->assertStringContainsString('saved', $intro);
        $this->assertStringContainsStringIgnoringCase('Bookcase', $intro);
    }

    public function test_an_ordinary_digest_has_no_intro_at_all(): void
    {
        [$mail] = $this->digestFor(null);

        $this->assertNull($mail->matchIntro(), 'a digest must not gain a line explaining itself');
    }

    public function test_the_reason_reaches_the_rendered_body(): void
    {
        [$mail, $recipient] = $this->digestFor('wanted');

        $spooled = $this->spoolAndLoad($mail, $recipient);
        $body = ($spooled['html'] ?? '') . ($spooled['text'] ?? '');

        $this->assertStringContainsString('open post', $body, 'the reason is rendered, not just computed');
    }
}
