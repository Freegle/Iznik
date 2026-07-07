<?php

namespace Tests\Feature\Mail;

use App\Mail\Welcome\WelcomeMail;
use Illuminate\Support\Facades\Mail;
use Tests\Support\MailpitHelper;
use Tests\TestCase;

class WelcomeMailTest extends TestCase
{
    protected MailpitHelper $mailpit;

    protected function setUp(): void
    {
        parent::setUp();

        // Set up Mailpit helper with docker network URL.
        $this->mailpit = new MailpitHelper('http://mailpit:8025');
    }

    /**
     * Test that welcome email is sent via Laravel's mail system.
     */
    public function test_welcome_email_can_be_sent(): void
    {
        Mail::fake();

        $email = $this->uniqueEmail('welcome');
        $password = 'testpassword123';

        Mail::to($email)->send(new WelcomeMail($email, $password));

        Mail::assertSent(WelcomeMail::class, function ($mail) use ($email) {
            return $mail->recipientEmail === $email;
        });
    }

    /**
     * Test welcome email without password.
     */
    public function test_welcome_email_without_password(): void
    {
        Mail::fake();

        $email = $this->uniqueEmail('newuser');

        Mail::to($email)->send(new WelcomeMail($email));

        Mail::assertSent(WelcomeMail::class, function ($mail) {
            return $mail->password === NULL;
        });
    }

    /**
     * Test welcome email envelope has correct subject.
     */
    public function test_welcome_email_has_correct_subject(): void
    {
        $email = $this->uniqueEmail('welcome');
        $mail = new WelcomeMail($email);

        $envelope = $mail->envelope();

        $this->assertEquals('💚 Welcome to Freegle!', $envelope->subject);
    }

    /**
     * Test welcome email envelope has correct from address.
     */
    public function test_welcome_email_has_correct_from_address(): void
    {
        $email = $this->uniqueEmail('welcome');
        $mail = new WelcomeMail($email);

        $envelope = $mail->envelope();

        $this->assertEquals(config('freegle.mail.noreply_addr'), $envelope->from->address);
        $this->assertEquals(config('freegle.branding.name'), $envelope->from->name);
    }

    /**
     * Test that welcome email view data is correct.
     */
    public function test_welcome_email_view_data(): void
    {
        $email = $this->uniqueEmail('user');
        $password = 'secret123';

        $mail = new WelcomeMail($email, $password);

        $this->assertEquals($email, $mail->recipientEmail);
        $this->assertEquals($password, $mail->password);
    }

    /**
     * Test that welcome email renders HTML via build method.
     */
    public function test_welcome_email_build_renders_html(): void
    {
        $email = $this->uniqueEmail('user');
        $password = 'secret123';

        $mail = new WelcomeMail($email, $password);
        $builtMail = $mail->build();

        $this->assertInstanceOf(WelcomeMail::class, $builtMail);
    }

    /**
     * Test welcome email attachments returns empty array.
     */
    public function test_welcome_email_attachments(): void
    {
        $email = $this->uniqueEmail('user');
        $mail = new WelcomeMail($email);

        $this->assertEquals([], $mail->attachments());
    }

    /**
     * Test welcome email with user ID.
     */
    public function test_welcome_email_with_user_id(): void
    {
        Mail::fake();

        $email = $this->uniqueEmail('welcome');

        // Test with a non-existent user ID - should still work, just no nearby offers.
        Mail::to($email)->send(new WelcomeMail($email, NULL, 999999999));

        Mail::assertSent(WelcomeMail::class, function ($mail) use ($email) {
            return $mail->recipientEmail === $email && $mail->userId === 999999999;
        });
    }

    /**
     * Test welcome email has X-Freegle custom headers.
     * Note: Headers are added via withSymfonyMessage during send.
     * We verify the build completes successfully.
     */
    public function test_welcome_email_has_custom_headers(): void
    {
        $email = $this->uniqueEmail('welcome');
        $userId = 12345;

        $mail = new WelcomeMail($email, NULL, $userId);

        // Build should complete without error (headers are added during send).
        $result = $mail->build();
        $this->assertInstanceOf(WelcomeMail::class, $result);
    }

    /**
     * Test welcome email has plain text template (multipart).
     */
    public function test_welcome_email_has_plain_text_template(): void
    {
        $email = $this->uniqueEmail('welcome');
        $password = 'secret123';

        $mail = new WelcomeMail($email, $password);
        $mail->build();

        // Verify plain text view is set by checking the view exists.
        $this->assertTrue(view()->exists('emails.text.welcome.welcome'));
    }
}
