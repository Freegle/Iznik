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
}
