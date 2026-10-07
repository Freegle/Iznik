<?php

namespace Tests\Unit\Mail;

use App\Mail\Admin\AdminMail;
use App\Mail\Admin\ChaseAdminMail;
use App\Models\User;
use Tests\TestCase;

class AdminMailTest extends TestCase
{
    private function makeAdmin(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'subject' => 'Test Admin Subject',
            'text' => 'Hello, this is a test admin message.',
            'ctalink' => 'https://www.ilovefreegle.org/donate',
            'ctatext' => 'Donate Now',
            'groupid' => 1,
            'parentid' => null,
            'essential' => true,
        ], $overrides);
    }

    public function test_admin_mail_can_be_constructed(): void
    {
        $user = $this->createTestUser();
        $admin = $this->makeAdmin();

        $mail = new AdminMail($user, $admin, 'Test Group', 'test-volunteers@groups.ilovefreegle.org');

        $this->assertInstanceOf(AdminMail::class, $mail);
    }

    public function test_admin_mail_has_correct_subject(): void
    {
        $user = $this->createTestUser();
        $admin = $this->makeAdmin(['subject' => 'Important Freegle Update']);

        $mail = new AdminMail($user, $admin, 'Test Group');
        $envelope = $mail->envelope();

        $this->assertEquals('ADMIN: Important Freegle Update', $envelope->subject);
    }

    public function test_marketing_mail_has_no_admin_prefix(): void
    {
        $user = $this->createTestUser();
        $admin = $this->makeAdmin([
            'subject' => 'Help us make it happen!',
            'template' => 'fundraising',
        ]);

        $mail = new AdminMail($user, $admin, 'Test Group');
        $envelope = $mail->envelope();

        $this->assertEquals('Help us make it happen!', $envelope->subject);
        $this->assertTrue($mail->isMarketing);
    }

    public function test_admin_mail_has_user(): void
    {
        $user = $this->createTestUser();
        $admin = $this->makeAdmin();

        $mail = new AdminMail($user, $admin, 'Test Group');

        $this->assertSame($user->id, $mail->user->id);
    }

    public function test_admin_mail_has_admin_text(): void
    {
        $user = $this->createTestUser();
        $admin = $this->makeAdmin(['text' => 'Custom admin body text']);

        $mail = new AdminMail($user, $admin, 'Test Group');

        $this->assertEquals('Custom admin body text', $mail->adminText);
    }

    public function test_admin_mail_has_cta(): void
    {
        $user = $this->createTestUser();
        $admin = $this->makeAdmin([
            'ctalink' => 'https://example.com/donate',
            'ctatext' => 'Donate',
        ]);

        $mail = new AdminMail($user, $admin, 'Test Group');

        $this->assertEquals('https://example.com/donate', $mail->ctaLink);
        $this->assertEquals('Donate', $mail->ctaText);
    }

    public function test_admin_mail_handles_null_cta(): void
    {
        $user = $this->createTestUser();
        $admin = $this->makeAdmin([
            'ctalink' => null,
            'ctatext' => null,
        ]);

        $mail = new AdminMail($user, $admin, 'Test Group');

        $this->assertNull($mail->ctaLink);
        $this->assertNull($mail->ctaText);
    }

    public function test_admin_mail_build_returns_self(): void
    {
        $user = $this->createTestUser();
        $admin = $this->makeAdmin();

        $mail = new AdminMail($user, $admin, 'Test Group');
        $result = $mail->build();

        $this->assertInstanceOf(AdminMail::class, $result);
    }

    public function test_admin_mail_has_group_name(): void
    {
        $user = $this->createTestUser();
        $admin = $this->makeAdmin();

        $mail = new AdminMail($user, $admin, 'My Freegle Group');

        $this->assertEquals('My Freegle Group', $mail->groupName);
    }

    public function test_admin_mail_has_mods_email(): void
    {
        $user = $this->createTestUser();
        $admin = $this->makeAdmin();
        $modsEmail = 'testgroup-volunteers@groups.ilovefreegle.org';

        $mail = new AdminMail($user, $admin, 'Test Group', $modsEmail);

        $this->assertEquals($modsEmail, $mail->modsEmail);
    }

    public function test_admin_mail_essential_flag(): void
    {
        $user = $this->createTestUser();

        $essentialMail = new AdminMail($user, $this->makeAdmin(['essential' => true]), 'Test Group');
        $this->assertTrue($essentialMail->essential);

        $nonEssentialMail = new AdminMail($user, $this->makeAdmin(['essential' => false]), 'Test Group');
        $this->assertFalse($nonEssentialMail->essential);
    }

    public function test_admin_mail_marketing_optout_for_non_essential(): void
    {
        $user = $this->createTestUser();

        $nonEssentialMail = new AdminMail($user, $this->makeAdmin(['essential' => false]), 'Test Group');
        $this->assertNotNull($nonEssentialMail->marketingOptOutUrl);
        $this->assertStringContainsString('marketing-optout', $nonEssentialMail->marketingOptOutUrl);
        $this->assertStringContainsString((string) $user->id, $nonEssentialMail->marketingOptOutUrl);
    }

    public function test_admin_mail_no_marketing_optout_for_essential(): void
    {
        $user = $this->createTestUser();

        $essentialMail = new AdminMail($user, $this->makeAdmin(['essential' => true]), 'Test Group');
        $this->assertNull($essentialMail->marketingOptOutUrl);
    }

    public function test_admin_mail_has_volunteers(): void
    {
        $user = $this->createTestUser();
        $admin = $this->makeAdmin();
        $volunteers = [
            ['id' => 1, 'displayname' => 'Jane Smith', 'firstname' => 'Jane'],
            ['id' => 2, 'displayname' => 'Bob Jones', 'firstname' => 'Bob'],
        ];

        $mail = new AdminMail($user, $admin, 'Test Group', null, null, $volunteers);

        $this->assertCount(2, $mail->volunteers);
        $this->assertEquals('Jane', $mail->volunteers[0]['firstname']);
        $this->assertEquals('Bob', $mail->volunteers[1]['firstname']);
    }

    public function test_admin_mail_default_empty_volunteers(): void
    {
        $user = $this->createTestUser();
        $admin = $this->makeAdmin();

        $mail = new AdminMail($user, $admin, 'Test Group');

        $this->assertIsArray($mail->volunteers);
        $this->assertEmpty($mail->volunteers);
    }

    public function test_admin_mail_envelope_with_group_short(): void
    {
        $user = $this->createTestUser();
        $admin = $this->makeAdmin();

        $mail = new AdminMail($user, $admin, 'Freegle Testington', null, 'FreegleTestington');
        $envelope = $mail->envelope();

        $this->assertEquals('FreegleTestington-auto@groups.ilovefreegle.org', $envelope->from->address);
        $this->assertEquals('Freegle Testington Volunteers', $envelope->from->name);
    }

    public function test_admin_mail_has_attachments(): void
    {
        $user = $this->createTestUser();
        $admin = $this->makeAdmin();

        $mail = new AdminMail($user, $admin, 'Test Group');
        $attachments = $mail->attachments();

        $this->assertIsArray($attachments);
        $this->assertEmpty($attachments);
    }

    public function test_admin_mail_envelope_from_address(): void
    {
        $user = $this->createTestUser();
        $admin = $this->makeAdmin();

        $mail = new AdminMail($user, $admin, 'Test Group');
        $envelope = $mail->envelope();

        $this->assertEquals(config('freegle.mail.noreply_addr'), $envelope->from->address);
    }

    public function test_chase_admin_preheader_shows_group_subject_and_pending_time(): void
    {
        // The inbox preview should tell the moderator which group needs attention,
        // which admin is waiting, and how long it has been pending — all without
        // opening the email.
        $html = view('emails.mjml.admin.chase', [
            'groupName'       => 'Freegle Testington',
            'adminSubject'    => 'Welcome post for new members',
            'pendingTimeText' => '3 days',
            'pendingHours'    => 72,
            'modToolsUrl'     => 'https://modtools.org/admins',
            'adminId'         => 1,
            'userName'        => 'Test Mod',
            'siteName'        => config('freegle.branding.name', 'Freegle'),
            'trackingPixelMjml' => null,
        ])->render();

        $this->assertStringContainsString('Freegle Testington', $html,
            'Preheader must contain the group name');
        $this->assertStringContainsString('Welcome post for new members', $html,
            'Preheader must contain the admin subject');
        $this->assertStringContainsString('3 days', $html,
            'Preheader must state how long the admin has been pending');
    }

    public function test_admin_mail_tracking_pixel_is_wrapped_in_section_and_column(): void
    {
        // A bare <mj-image> as a direct child of <mj-body> violates the MJML
        // schema (mj-image must live inside mj-column > mj-section). mrml
        // renders that malformed nesting without the Outlook-conditional
        // wrapper table every other section gets, which is what left the
        // reported admin email blank on reopen in Outlook (topic 9925). Every
        // other mailable (welcome, chase, digest siblings, etc.) wraps its
        // tracking pixel in its own <mj-section padding="0"><mj-column> - this
        // pins admin.blade.php to the same pattern.
        $pixel = '<mj-image src="https://example.com/pixel.png" width="1px" height="1px" alt="" padding="0" />';

        $html = view('emails.mjml.admin.admin', [
            'user' => (object) ['email_preferred' => 'test@example.com', 'displayname' => 'Test User'],
            'userSite' => config('freegle.sites.user'),
            'adminSubject' => 'Test Subject',
            'adminText' => 'Test body text',
            'ctaLink' => null,
            'ctaText' => null,
            'groupName' => 'Test Group',
            'modsEmail' => null,
            'essential' => true,
            'settingsUrl' => 'https://www.ilovefreegle.org/settings',
            'marketingOptOutUrl' => null,
            'unsubscribeUrl' => 'https://www.ilovefreegle.org/unsubscribe',
            'volunteers' => [],
            'trackingPixelMjml' => $pixel,
        ])->render();

        $this->assertMatchesRegularExpression(
            '#<mj-section[^>]*>\s*<mj-column>\s*'.preg_quote($pixel, '#').'\s*</mj-column>\s*</mj-section>#',
            $html,
            'Tracking pixel must be wrapped in its own mj-section/mj-column, not a bare mj-body child.'
        );
    }

    public function test_moderator_guidance_never_appears_in_rendered_email(): void
    {
        $guidance = 'GUIDANCE-FOR-MODS-ONLY please add local details';
        $user = $this->createTestUser();
        $admin = $this->makeAdmin(['modguidance' => $guidance]);

        $mail = new AdminMail($user, $admin, 'Test Group', 'mods@groups.ilovefreegle.org', 'testgroup');

        $html = $mail->render();
        $text = view('emails.text.admin.admin', $mail->buildViewData())->render();

        // Positive control: the real body is rendered, so a missing guidance string is meaningful.
        $this->assertStringContainsString('this is a test admin message', $html);
        $this->assertStringContainsString('this is a test admin message', $text);

        $this->assertStringNotContainsString($guidance, $html);
        $this->assertStringNotContainsString('GUIDANCE-FOR-MODS-ONLY', $html);
        $this->assertStringNotContainsString($guidance, $text);
        $this->assertStringNotContainsString('GUIDANCE-FOR-MODS-ONLY', $text);
        $this->assertStringNotContainsString($guidance, $mail->adminText);
        $this->assertStringNotContainsString($guidance, $mail->envelope()->subject);
    }

    public function test_mjml_part_replaces_the_text_in_the_html_only(): void
    {
        $user = $this->createTestUser();
        $admin = $this->makeAdmin([
            'text' => 'PLAIN-VERSION of the news',
            'mjml' => '<mj-section><mj-column><mj-text>DESIGNED-VERSION <b>bold</b>'
                . '<script>alert("x")</script><img src="https://x.example/i.png" onerror="alert(1)"></mj-text>'
                . '</mj-column></mj-section>',
        ]);

        $mail = new AdminMail($user, $admin, 'Test Group', 'mods@groups.ilovefreegle.org', 'testgroup');

        $html = $mail->render();
        $text = view('emails.text.admin.admin', $mail->buildViewData())->render();

        $this->assertStringContainsString('DESIGNED-VERSION', $html);
        $this->assertStringNotContainsString('PLAIN-VERSION', $html);
        $this->assertStringContainsString('PLAIN-VERSION', $text);
        $this->assertStringNotContainsString('DESIGNED-VERSION', $text);

        // Sanitised before compiling: no script, no handler.
        $this->assertStringNotContainsString('alert', $html);
        $this->assertStringNotContainsString('onerror', $html);

        // Freegle's own footer still frames it.
        $this->assertStringContainsString('Test Group', $html);
    }

    public function test_mjml_that_will_not_build_falls_back_to_the_text(): void
    {
        $admin = $this->makeAdmin([
            'text' => 'PLAIN-VERSION of the news',
            'mjml' => '<mj-section><mj-column><mj-text>Broken</mj-column></mj-section>',
        ]);

        // A member gets the plain text, and is not told about the failure.
        $mail = new AdminMail($this->createTestUser(), $admin, 'Test Group');
        $html = $mail->render();
        $this->assertNotNull($mail->mjmlFailure);
        $this->assertNull($mail->adminMjml);
        $this->assertStringContainsString('PLAIN-VERSION', $html);
        $this->assertStringNotContainsString('could not be built', $html);

        // A test send says why at the top.
        $test = new AdminMail($this->createTestUser(), $admin, 'Test Group', null, null, [], 'tester@example.com');
        $html = $test->render();
        $this->assertStringContainsString('could not be built', $html);
        $this->assertStringContainsString('PLAIN-VERSION', $html);
    }

    public function test_mjml_builds_tells_good_from_broken(): void
    {
        $this->assertTrue(AdminMail::mjmlBuilds('<mj-section><mj-column><mj-text>Fine</mj-text></mj-column></mj-section>'));
        $this->assertFalse(AdminMail::mjmlBuilds('<mj-section><mj-column><mj-text>Broken</mj-column></mj-section>'));
    }

    public function test_mjml_version_has_no_freegle_button(): void
    {
        $admin = $this->makeAdmin([
            'ctatext' => 'FREEGLE-BUTTON',
            'mjml' => '<mj-section><mj-column><mj-text>Designed</mj-text></mj-column></mj-section>',
        ]);
        $html = (new AdminMail($this->createTestUser(), $admin, 'Test Group'))->render();
        $this->assertStringContainsString('Designed', $html);
        $this->assertStringNotContainsString('FREEGLE-BUTTON', $html);

        // The text-only email still has it.
        $html = (new AdminMail($this->createTestUser(), $this->makeAdmin(['ctatext' => 'FREEGLE-BUTTON']), 'Test Group'))->render();
        $this->assertStringContainsString('FREEGLE-BUTTON', $html);
    }

    public function test_text_only_admin_has_no_mjml(): void
    {
        $mail = new AdminMail($this->createTestUser(), $this->makeAdmin(['mjml' => '']), 'Test Group');
        $this->assertNull($mail->adminMjml);
        $this->assertStringContainsString('this is a test admin message', $mail->render());
    }

    public function test_test_send_goes_to_the_test_address_with_test_subject(): void
    {
        $user = $this->createTestUser();
        $mail = new AdminMail($user, $this->makeAdmin(['subject' => 'News']), 'Test Group', null, null, [], 'tester@example.com');
        $mail->build();

        $this->assertTrue($mail->hasTo('tester@example.com'));
        $this->assertFalse($mail->hasTo($user->email_preferred));
        $this->assertSame('TEST: ADMIN: News', $mail->envelope()->subject);
    }

    private function subjectFor(array $overrides): string
    {
        $mail = new AdminMail($this->createTestUser(), $this->makeAdmin($overrides), 'Test Group');

        return $mail->envelope()->subject;
    }

    public function test_essential_admin_subject_is_admin_prefixed(): void
    {
        $this->assertEquals('ADMIN: Rota change', $this->subjectFor(['subject' => 'Rota change', 'essential' => true]));
    }

    public function test_non_essential_admin_subject_is_newsletter_prefixed(): void
    {
        $this->assertEquals('NEWSLETTER: Spring news', $this->subjectFor(['subject' => 'Spring news', 'essential' => false]));
    }

    public function test_typed_prefix_is_never_doubled_in_subject(): void
    {
        $this->assertEquals('ADMIN: Rota change', $this->subjectFor(['subject' => 'ADMIN: Rota change', 'essential' => true]));
        $this->assertEquals('ADMIN: Rota change', $this->subjectFor(['subject' => 'ADMIN Rota change', 'essential' => true]));
        $this->assertEquals('NEWSLETTER: Spring news', $this->subjectFor(['subject' => 'ADMIN: Spring news', 'essential' => false]));
        $this->assertEquals('NEWSLETTER: Spring news', $this->subjectFor(['subject' => 'NEWSLETTER: Spring news', 'essential' => false]));
        $this->assertEquals('ADMIN: Rota change', $this->subjectFor(['subject' => 'NEWSLETTER Rota change', 'essential' => true]));
    }

    public function test_a_subject_merely_containing_admin_is_kept(): void
    {
        $this->assertEquals('ADMIN: Admin team meeting', $this->subjectFor(['subject' => 'Admin team meeting']));
    }

    public function test_marketing_template_subject_unchanged(): void
    {
        $this->assertEquals('ADMIN: Help us', $this->subjectFor(['subject' => 'ADMIN: Help us', 'template' => 'fundraising', 'essential' => false]));
    }
}
