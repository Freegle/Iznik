<?php

namespace Tests\Unit\Mail\Session;

use App\Mail\Session\LoginLinkMail;
use Tests\TestCase;

class LoginLinkMailTest extends TestCase
{
    private function mail(int $userId = 123): LoginLinkMail
    {
        return new LoginLinkMail(
            userId: $userId,
            email: 'test@example.com',
            loginUrl: 'https://www.ilovefreegle.org/?u='.$userId.'&k=abc',
        );
    }

    public function test_can_be_constructed(): void
    {
        $this->assertInstanceOf(LoginLinkMail::class, $this->mail());
    }

    public function test_subject_names_the_site(): void
    {
        config(['freegle.branding.name' => 'Example Site']);

        $this->assertEquals('Your sign-in link for Example Site', $this->mail()->envelope()->subject);
    }

    public function test_build_returns_self_and_sets_recipient(): void
    {
        $mail = $this->mail();

        $this->assertInstanceOf(LoginLinkMail::class, $mail->build());
        $this->assertTrue($mail->hasTo('test@example.com'));
    }

    public function test_tracks_user_id(): void
    {
        $mail = $this->mail(456);

        $method = new \ReflectionMethod($mail, 'getRecipientUserId');
        $method->setAccessible(true);

        $this->assertEquals(456, $method->invoke($mail));
    }

    public function test_is_transactional_so_carries_no_unsubscribe(): void
    {
        $method = new \ReflectionMethod($this->mail(), 'unsubscribeType');
        $method->setAccessible(true);

        $this->assertNull($method->invoke($this->mail()));
    }
}
