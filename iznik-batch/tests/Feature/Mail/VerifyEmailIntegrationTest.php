<?php

namespace Tests\Feature\Mail;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Support\MailpitHelper;
use Tests\TestCase;

/**
 * End-to-end test for email verification flow.
 *
 * Simulates what happens when the Go API queues an email_verify task:
 * 1. Insert background_tasks row (same as Go's queue.QueueTask)
 * 2. Run ProcessBackgroundTasksCommand (Laravel picks up and sends)
 * 3. Verify email arrives in Mailpit with correct content
 * 4. Verify the confirm link has the right structure and will work
 *
 * Requires Mailpit to be running.
 */
class VerifyEmailIntegrationTest extends TestCase
{
    protected MailpitHelper $mailpit;
    protected string $testRunId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testRunId = uniqid('verify_', TRUE);

        // Configure for actual SMTP sending.
        Config::set('mail.default', 'smtp');
        Config::set('mail.mailers.smtp.host', 'mailpit');
        Config::set('mail.mailers.smtp.port', 1025);

        $this->mailpit = new MailpitHelper('http://mailpit:8025');
    }

    /**
     * End-to-end: Go queues email_verify → Laravel sends → Mailpit receives → link works.
     */
    public function test_email_verify_end_to_end(): void
    {
        if (!$this->isMailpitAvailable()) {
            $this->markTestSkipped('Mailpit is not available.');
        }

        // Create a test user.
        $user = $this->createTestUser();
        $newEmail = $this->uniqueEmail('verify');

        // Simulate what Go's queue.QueueTask(queue.TaskEmailVerify, ...) does:
        // insert a row into background_tasks.
        $taskId = DB::table('background_tasks')->insertGetId([
            'task_type' => 'email_verify',
            'data' => json_encode([
                'user_id' => $user->id,
                'email' => $newEmail,
            ]),
            'created_at' => now(),
        ]);

        // Process tasks — our task will be picked up along with any others.
        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        // Verify our specific task was processed.
        $task = DB::table('background_tasks')->find($taskId);
        $this->assertNotNull($task->processed_at, 'Task should be marked as processed');
        $this->assertNull($task->failed_at, 'Task should not have failed');

        // The handler writes the mailable to the spool; flush it so the SMTP
        // send actually fires for Mailpit to observe. In production the spool
        // processor runs as a separate supervisor process.
        $this->artisan('mail:spool:process')->assertSuccessful();

        // Find the email in Mailpit.
        $message = $this->mailpit->assertMessageSentTo($newEmail, 20);

        // Verify subject.
        $subject = $this->mailpit->getSubject($message);
        $this->assertStringContainsString('Please verify your email', $subject);

        // Get the full message body.
        $fullMessage = $this->mailpit->getMessage($message['ID']);
        $html = $fullMessage['HTML'] ?? '';
        $text = $fullMessage['Text'] ?? '';
        $body = $html ?: $text;

        $this->assertNotEmpty($body, 'Email body should not be empty');

        // Extract the confirm URL from the email body.
        $confirmUrl = $this->extractConfirmUrl($body);
        $this->assertNotNull($confirmUrl, 'Email should contain a confirm URL');

        // Verify the confirm URL structure.
        $parsed = parse_url($confirmUrl);
        $this->assertNotFalse($parsed, 'Confirm URL should be a valid URL');

        $userSite = config('freegle.sites.user');
        $this->assertStringStartsWith($userSite, $confirmUrl, 'Confirm URL should start with user site');

        // Path should be /settings/confirmmail/{key}
        $this->assertMatchesRegularExpression(
            '#^/settings/confirmmail/[a-z0-9]+$#',
            $parsed['path'],
            'Confirm URL path should be /settings/confirmmail/{key}'
        );

        // Query params should include u (user ID) and k (login key).
        parse_str($parsed['query'] ?? '', $params);
        $this->assertArrayHasKey('u', $params, 'Confirm URL should have u parameter');
        $this->assertArrayHasKey('k', $params, 'Confirm URL should have k parameter');
        $this->assertEquals($user->id, $params['u'], 'u parameter should be the user ID');
        $this->assertNotEmpty($params['k'], 'k parameter (login key) should not be empty');

        // Verify the validatekey was stored in users_emails.
        $emailRow = DB::table('users_emails')
            ->where('email', $newEmail)
            ->first();
        $this->assertNotNull($emailRow, 'Email row should exist in users_emails');
        $this->assertNotNull($emailRow->validatekey, 'validatekey should be set');

        // The key in the URL path should match the validatekey in the DB.
        $urlKey = basename($parsed['path']);
        $this->assertEquals($emailRow->validatekey, $urlKey, 'URL key should match DB validatekey');

        // Verify the login key (k parameter) exists in users_logins.credentials.
        $loginKey = DB::table('users_logins')
            ->where('userid', $user->id)
            ->where('type', 'Link')
            ->where('credentials', $params['k'])
            ->exists();
        $this->assertTrue($loginKey, 'Login key from URL should exist in users_logins.credentials');

        // Clean up.
        DB::table('users_emails')->where('email', $newEmail)->delete();
    }

    /**
     * Extract the confirm URL from email HTML or text body.
     */
    protected function extractConfirmUrl(string $body): ?string
    {
        // Try HTML href first.
        if (preg_match('/href=["\']([^"\']*\/settings\/confirmmail\/[^"\']*)["\']/', $body, $matches)) {
            return html_entity_decode($matches[1]);
        }

        // Try plain text URL.
        if (preg_match('/(https?:\/\/[^\s]*\/settings\/confirmmail\/[^\s<]*)/', $body, $matches)) {
            return $matches[1];
        }

        return NULL;
    }

    protected function isMailpitAvailable(): bool
    {
        try {
            return count($this->mailpit->getMessages()) >= 0;
        } catch (\Throwable $e) {
            return FALSE;
        }
    }
}
