<?php

namespace Tests\Unit\Mail\Ripple;

use App\Mail\Ripple\RippleIntroMail;
use App\Models\Message;
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

    public function test_preheader_shows_post_subject_when_post_is_present(): void
    {
        // When a Message is provided, the inbox preview should name the post so
        // the recipient knows immediately which item triggered the ripple.
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group, [
            'subject' => 'OFFER: Sofa (London)',
        ]);

        $html = view('emails.mjml.ripple.intro', [
            'firstName'      => $user->firstname,
            'postSubject'    => $message->subject,
            'email'          => 'test@example.com',
            'userSite'       => config('freegle.sites.user'),
            'settingsUrl'    => config('freegle.sites.user') . '/settings',
            'unsubscribeUrl' => config('freegle.sites.user') . '/unsubscribe',
            'welcomeGroups'  => [],
        ])->render();

        $this->assertStringContainsString(
            '<mj-preview>Nearby freeglers might be interested in: OFFER: Sofa (London)</mj-preview>',
            $html,
            'Preheader must include the post subject so the inbox preview names the item'
        );
    }

    public function test_preheader_shows_fallback_when_no_post_subject(): void
    {
        // When no Message is provided (or subject is empty), the preheader should
        // fall back to a sensible generic string rather than an empty preview.
        $user = $this->createTestUser();

        $html = view('emails.mjml.ripple.intro', [
            'firstName'      => null,
            'postSubject'    => null,
            'email'          => 'test@example.com',
            'userSite'       => config('freegle.sites.user'),
            'settingsUrl'    => config('freegle.sites.user') . '/settings',
            'unsubscribeUrl' => config('freegle.sites.user') . '/unsubscribe',
            'welcomeGroups'  => [],
        ])->render();

        $this->assertStringContainsString(
            '<mj-preview>Nearby freeglers might be interested in your post</mj-preview>',
            $html,
            'Preheader must use the fallback string when no post subject is available'
        );
    }

    public function test_preheader_truncates_long_post_subject(): void
    {
        // Str::limit($postSubject, 60) is applied in the template, so subjects
        // longer than 60 characters should be truncated with "..." in the preheader.
        $user  = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $longSubject = 'OFFER: Very Long Item Name That Exceeds Sixty Characters Easily Here (London)';
        $message = $this->createTestMessage($user, $group, ['subject' => $longSubject]);

        $html = view('emails.mjml.ripple.intro', [
            'firstName'      => null,
            'postSubject'    => $message->subject,
            'email'          => 'test@example.com',
            'userSite'       => config('freegle.sites.user'),
            'settingsUrl'    => config('freegle.sites.user') . '/settings',
            'unsubscribeUrl' => config('freegle.sites.user') . '/unsubscribe',
            'welcomeGroups'  => [],
        ])->render();

        // The full long subject must NOT appear verbatim.
        $this->assertStringNotContainsString($longSubject, $html);
        // The preview preamble must still be present.
        $this->assertStringContainsString('Nearby freeglers might be interested in:', $html);
        // The truncation marker must be present.
        $this->assertStringContainsString('...', $html);
    }
}
