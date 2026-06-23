<?php

namespace Tests\Unit\Mail\Ripple;

use App\Mail\Ripple\RippleIntroMail;
use Tests\TestCase;

class RippleIntroMailTest extends TestCase
{
    public function test_can_be_constructed_with_user(): void
    {
        $user = $this->createTestUser();
        $mail = new RippleIntroMail($user);

        $this->assertInstanceOf(RippleIntroMail::class, $mail);
        $this->assertSame($user->id, $mail->user->id);
        $this->assertNull($mail->message);
    }

    public function test_from_is_the_freegle_noreply_address_not_a_group(): void
    {
        $user = $this->createTestUser();
        $envelope = (new RippleIntroMail($user))->envelope();

        $this->assertNotNull($envelope->from);
        $this->assertSame(config('freegle.mail.noreply_addr'), $envelope->from->address);
    }

    public function test_subject_is_about_reaching_more_people(): void
    {
        $user = $this->createTestUser();
        $envelope = (new RippleIntroMail($user))->envelope();

        $this->assertStringContainsString('reaching more people', $envelope->subject);
    }

    public function test_build_renders_and_sets_recipient(): void
    {
        $user = $this->createTestUser();
        $this->createTestUserEmail($user);

        $mail = new RippleIntroMail($user);
        $result = $mail->build();

        $this->assertInstanceOf(RippleIntroMail::class, $result);
        $this->assertContains($user->email_preferred, array_column($mail->to, 'address'));
    }

    public function test_carries_per_community_welcome_text_and_builds(): void
    {
        $user = $this->createTestUser();
        $this->createTestUserEmail($user);

        $groups = [
            ['name' => 'Freegle Testown', 'welcome' => "Welcome to Testown!\nPlease be kind."],
            ['name' => 'Freegle Exampleford', 'welcome' => 'Hi and welcome.'],
        ];
        $mail = new RippleIntroMail($user, null, $groups);

        $this->assertSame($groups, $mail->welcomeGroups);
        // Builds (renders the welcome section) without error.
        $this->assertInstanceOf(RippleIntroMail::class, $mail->build());
    }
}
