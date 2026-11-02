<?php

namespace Tests\Unit\Mail\Message;

use App\Mail\Message\ModStdMessageMail;
use App\Support\TrashNothingSignOff;
use Tests\TestCase;

/**
 * A standard message, or a moderator's reply about a post, to a TrashNothing member carries
 * a sign-off in the body. TrashNothing shows our mail as coming from a Freegle user, so the
 * moderator's sender name is lost and the member can't tell it from another member's.
 */
class ModStdMessageMailSignOffTest extends TestCase
{
    private function mailTo(int $userId): ModStdMessageMail
    {
        return new ModStdMessageMail(
            modName: 'Test Moderator',
            groupName: 'Testville Freegle',
            groupNameShort: 'Testville',
            stdSubject: 'About your post',
            stdBody: 'Please add a photo.',
            messageSubject: 'OFFER: Chair (Testville)',
            msgId: 0,
            recipientUserId: $userId,
            recipientEmail: 'someone@example.com',
        );
    }

    private function bodyOf(ModStdMessageMail $mail): string
    {
        return (fn () => $this->mjmlData['body'])->call($mail);
    }

    public function test_a_trashnothing_member_gets_the_sign_off(): void
    {
        $member = $this->createTestUser(['tnuserid' => random_int(900000000, 999999999)]);

        $mail = $this->mailTo($member->id);
        $body = $this->bodyOf($mail);

        $this->assertStringStartsWith('Please add a photo.', $body);
        $this->assertStringEndsWith(TrashNothingSignOff::line('Testville Freegle'), $body);
        $this->assertSame('Please add a photo.', $mail->stdBody, 'the standard message itself is unchanged');
    }

    public function test_an_ordinary_member_does_not(): void
    {
        $member = $this->createTestUser();

        $this->assertSame('Please add a photo.', $this->bodyOf($this->mailTo($member->id)));
    }

    public function test_the_sign_off_says_who_it_is_from(): void
    {
        $this->assertSame('From the volunteers who run Testville Freegle', TrashNothingSignOff::line('Testville Freegle'));
        $this->assertSame("Hello\n\nFrom the volunteers who run Testville Freegle", TrashNothingSignOff::append('Hello', 'Testville Freegle'));
    }
}
