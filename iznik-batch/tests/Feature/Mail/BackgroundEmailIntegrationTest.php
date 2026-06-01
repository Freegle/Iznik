<?php

namespace Tests\Feature\Mail;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Support\MailpitHelper;
use Tests\TestCase;

/**
 * End-to-end tests for background task emails.
 *
 * Each test simulates the Go API queueing a task, then verifies Laravel
 * processes it correctly and the resulting email in Mailpit has valid
 * content and working links.
 *
 * Requires Mailpit to be running.
 */
class BackgroundEmailIntegrationTest extends TestCase
{
    protected MailpitHelper $mailpit;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('mail.default', 'smtp');
        Config::set('mail.mailers.smtp.host', 'mailpit');
        Config::set('mail.mailers.smtp.port', 1025);

        $this->mailpit = new MailpitHelper('http://mailpit:8025');
    }

    protected function isMailpitAvailable(): bool
    {
        try {
            return count($this->mailpit->getMessages()) >= 0;
        } catch (\Throwable $e) {
            return FALSE;
        }
    }

    /**
     * Process background tasks and verify a specific task was handled.
     */
    protected function processTaskAndVerify(int $taskId): void
    {
        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        $task = DB::table('background_tasks')->find($taskId);
        $this->assertNotNull($task->processed_at, 'Task should be marked as processed');
        $this->assertNull($task->failed_at, 'Task should not have failed');

        // The handler now writes the mailable to the spool; flush it through
        // the spool processor so the SMTP send actually fires for Mailpit to
        // observe. In production these run as separate supervisor processes;
        // here we chain them inline.
        $this->artisan('mail:spool:process')->assertSuccessful();
    }

    /**
     * Extract a URL matching a path pattern from email HTML/text body.
     */
    protected function extractUrlByPath(string $body, string $pathPattern): ?string
    {
        // Try HTML href first.
        if (preg_match('/href=["\']([^"\']*' . $pathPattern . '[^"\']*)["\']/', $body, $matches)) {
            return html_entity_decode($matches[1]);
        }

        // Try plain text URL.
        if (preg_match('/(https?:\/\/[^\s]*' . $pathPattern . '[^\s<]*)/', $body, $matches)) {
            return $matches[1];
        }

        return NULL;
    }

    /**
     * Get the full email body from a Mailpit message summary.
     */
    protected function getEmailBody(array $message): string
    {
        $full = $this->mailpit->getMessage($message['ID']);
        return $full['HTML'] ?? $full['Text'] ?? '';
    }

    // =========================================================================
    // email_forgot_password
    // =========================================================================

    public function test_forgot_password_end_to_end(): void
    {
        if (!$this->isMailpitAvailable()) {
            $this->markTestSkipped('Mailpit is not available.');
        }

        $user = $this->createTestUser();
        $email = $this->uniqueEmail('forgot');
        $userSite = config('freegle.sites.user');
        $resetUrl = "{$userSite}/settings?u={$user->id}&k=testkey123&src=lostpassword";

        $taskId = DB::table('background_tasks')->insertGetId([
            'task_type' => 'email_forgot_password',
            'data' => json_encode([
                'user_id' => $user->id,
                'email' => $email,
                'reset_url' => $resetUrl,
            ]),
            'created_at' => now(),
        ]);

        $this->processTaskAndVerify($taskId);

        $message = $this->mailpit->assertMessageSentTo($email, 20);
        $body = $this->getEmailBody($message);
        $this->assertNotEmpty($body);

        // The email should contain the reset URL.
        $foundUrl = $this->extractUrlByPath($body, '\/settings');
        $this->assertNotNull($foundUrl, 'Email should contain a settings/reset URL');
        $this->assertStringStartsWith($userSite, $foundUrl, 'Reset URL should start with user site');

        // Verify it contains the user ID and key params.
        $parsed = parse_url($foundUrl);
        parse_str($parsed['query'] ?? '', $params);
        $this->assertEquals($user->id, $params['u'] ?? NULL, 'Reset URL should have correct user ID');
        $this->assertNotEmpty($params['k'] ?? '', 'Reset URL should have a key parameter');
    }

    // =========================================================================
    // email_unsubscribe
    // =========================================================================

    public function test_unsubscribe_end_to_end(): void
    {
        if (!$this->isMailpitAvailable()) {
            $this->markTestSkipped('Mailpit is not available.');
        }

        $user = $this->createTestUser();
        $email = $this->uniqueEmail('unsub');
        $userSite = config('freegle.sites.user');
        $unsubUrl = "{$userSite}/unsubscribe?u={$user->id}&k=unsubkey456";

        $taskId = DB::table('background_tasks')->insertGetId([
            'task_type' => 'email_unsubscribe',
            'data' => json_encode([
                'user_id' => $user->id,
                'email' => $email,
                'unsub_url' => $unsubUrl,
            ]),
            'created_at' => now(),
        ]);

        $this->processTaskAndVerify($taskId);

        $message = $this->mailpit->assertMessageSentTo($email, 20);
        $body = $this->getEmailBody($message);
        $this->assertNotEmpty($body);

        // The email should contain the unsubscribe URL.
        $foundUrl = $this->extractUrlByPath($body, '\/unsubscribe');
        $this->assertNotNull($foundUrl, 'Email should contain an unsubscribe URL');
        $this->assertStringStartsWith($userSite, $foundUrl, 'Unsubscribe URL should start with user site');

        $parsed = parse_url($foundUrl);
        parse_str($parsed['query'] ?? '', $params);
        $this->assertEquals($user->id, $params['u'] ?? NULL, 'Unsubscribe URL should have correct user ID');
        $this->assertNotEmpty($params['k'] ?? '', 'Unsubscribe URL should have a key parameter');
    }

    // =========================================================================
    // email_merge
    // =========================================================================

    public function test_merge_end_to_end(): void
    {
        if (!$this->isMailpitAvailable()) {
            $this->markTestSkipped('Mailpit is not available.');
        }

        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        // createTestUser adds an email like prefix@test.com as preferred.
        // Use those as the expected recipients.
        $email1 = $user1->email_preferred;
        $email2 = $user2->email_preferred;
        $this->assertNotNull($email1, 'User 1 should have a preferred email');
        $this->assertNotNull($email2, 'User 2 should have a preferred email');

        $taskId = DB::table('background_tasks')->insertGetId([
            'task_type' => 'email_merge',
            'data' => json_encode([
                'merge_id' => 99999,
                'uid' => 'testmergeuid',
                'user1' => $user1->id,
                'user2' => $user2->id,
            ]),
            'created_at' => now(),
        ]);

        $this->processTaskAndVerify($taskId);

        // Both users should receive the email.
        $message1 = $this->mailpit->assertMessageSentTo($email1, 20);
        $message2 = $this->mailpit->assertMessageSentTo($email2, 20);

        // Verify link structure in first email.
        $body = $this->getEmailBody($message1);
        $this->assertNotEmpty($body);

        $foundUrl = $this->extractUrlByPath($body, '\/merge');
        $this->assertNotNull($foundUrl, 'Email should contain a merge URL');

        $userSite = config('freegle.sites.user');
        $this->assertStringStartsWith($userSite, $foundUrl, 'Merge URL should start with user site');

        $parsed = parse_url($foundUrl);
        parse_str($parsed['query'] ?? '', $params);
        $this->assertEquals('99999', $params['id'] ?? NULL, 'Merge URL should have correct merge ID');
        $this->assertEquals('testmergeuid', $params['uid'] ?? NULL, 'Merge URL should have correct uid');

    }
}
