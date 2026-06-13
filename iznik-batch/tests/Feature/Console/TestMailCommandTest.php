<?php

namespace Tests\Feature\Console;

use App\Models\UserEmail;
use App\Services\EmailSpoolerService;
use App\Services\MjmlCompilerService;
use Tests\TestCase;

class TestMailCommandTest extends TestCase
{
    protected string $spoolDir;

    protected function setUp(): void
    {
        parent::setUp();

        // Use a unique spool directory for each test to avoid race conditions
        // when running tests in parallel with ParaTest.
        $this->spoolDir = storage_path('spool/mail-test-' . uniqid());

        // Create a spooler with the test-specific directory.
        $spooler = new EmailSpoolerService();

        // Override the spool directory using reflection.
        $reflection = new \ReflectionClass($spooler);

        $spoolDirProperty = $reflection->getProperty('spoolDir');
        $spoolDirProperty->setAccessible(true);
        $spoolDirProperty->setValue($spooler, $this->spoolDir);

        $pendingDirProperty = $reflection->getProperty('pendingDir');
        $pendingDirProperty->setAccessible(true);
        $pendingDirProperty->setValue($spooler, $this->spoolDir . '/pending');

        $sendingDirProperty = $reflection->getProperty('sendingDir');
        $sendingDirProperty->setAccessible(true);
        $sendingDirProperty->setValue($spooler, $this->spoolDir . '/sending');

        $failedDirProperty = $reflection->getProperty('failedDir');
        $failedDirProperty->setAccessible(true);
        $failedDirProperty->setValue($spooler, $this->spoolDir . '/failed');

        $sentDirProperty = $reflection->getProperty('sentDir');
        $sentDirProperty->setAccessible(true);
        $sentDirProperty->setValue($spooler, $this->spoolDir . '/sent');

        // Create directories.
        $ensureMethod = $reflection->getMethod('ensureDirectoriesExist');
        $ensureMethod->setAccessible(true);
        $ensureMethod->invoke($spooler);

        // Bind as singleton so the command uses our test instance.
        $this->app->instance(EmailSpoolerService::class, $spooler);

        // Array mail driver (set in phpunit.xml) prevents actual sending.
        // Don't use Mail::fake() here - it interferes with the spooler's Mail::html() call.

        // Mock MJML to avoid BoundedPool capacity issues in tests.
        // The mail:test command triggers multiple MJML compilations which
        // exceed the pool capacity of 1 in the test environment.
        $this->mock(MjmlCompilerService::class, function ($mock) {
            $mock->shouldReceive('compile')
                ->andReturnUsing(function ($mjml) {
                    return '<html><body>' . strip_tags($mjml) . '</body></html>';
                });
        });
    }

    protected function tearDown(): void
    {
        // Clean up test spool directory.
        if (is_dir($this->spoolDir)) {
            $this->recursiveDelete($this->spoolDir);
        }

        parent::tearDown();
    }

    protected function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    /**
     * Test that --send-to option overrides the delivery address.
     */
    public function test_send_to_option_overrides_delivery_address(): void
    {
        // Create test users using helper methods.
        $recipient = $this->createTestUser(['fullname' => 'Test Recipient']);
        $sender = $this->createTestUser(['fullname' => 'Test Sender']);

        // Use unique emails for this test to avoid parallel test collisions.
        $originalEmail = $this->uniqueEmail('original');
        $overrideEmail = $this->uniqueEmail('override');

        // Update recipient's email to our unique address.
        UserEmail::where('userid', $recipient->id)->update(['email' => $originalEmail]);
        $recipient = $recipient->fresh();

        // Create a chat room.
        $chatRoom = $this->createTestChatRoom($recipient, $sender);

        // Create a chat message from sender to recipient.
        $this->createTestChatMessage($chatRoom, $sender, [
            'message' => 'Hello, this is a test message!',
        ]);

        // Run the command with --send-to override.
        $this->artisan('mail:test', [
            'type' => 'chat:user2user',
            '--to' => $originalEmail,
            '--send-to' => $overrideEmail,
            '--chat' => $chatRoom->id,
        ])->assertExitCode(0);

        // Check spool file for the delivery address.
        $spoolFiles = glob($this->spoolDir . '/sent/*.json.gz');
        $this->assertNotEmpty($spoolFiles, 'Should have a spool file in sent directory');

        $spoolData = json_decode(gzdecode(file_get_contents($spoolFiles[0])), true);
        $toAddresses = array_column($spoolData['to'], 'address');

        // Verify the override address was used, not the original.
        $this->assertContains($overrideEmail, $toAddresses);
        $this->assertNotContains($originalEmail, $toAddresses);
    }

    /**
     * Test that without --send-to, the original address is used.
     */
    public function test_without_send_to_uses_original_address(): void
    {
        // Create test users using helper methods.
        $recipient = $this->createTestUser(['fullname' => 'Test Recipient']);
        $sender = $this->createTestUser(['fullname' => 'Test Sender']);

        // Use unique email for this test to avoid parallel test collisions.
        $originalEmail = $this->uniqueEmail('original');

        // Update recipient's email to our unique address.
        UserEmail::where('userid', $recipient->id)->update(['email' => $originalEmail]);
        $recipient = $recipient->fresh();

        // Create a chat room.
        $chatRoom = $this->createTestChatRoom($recipient, $sender);

        // Create a chat message from sender to recipient.
        $this->createTestChatMessage($chatRoom, $sender, [
            'message' => 'Hello, this is a test message!',
        ]);

        // Run the command WITHOUT --send-to.
        $this->artisan('mail:test', [
            'type' => 'chat:user2user',
            '--to' => $originalEmail,
            '--chat' => $chatRoom->id,
        ])->assertExitCode(0);

        // Check spool file for the delivery address.
        $spoolFiles = glob($this->spoolDir . '/sent/*.json.gz');
        $this->assertNotEmpty($spoolFiles, 'Should have a spool file in sent directory');

        $spoolData = json_decode(gzdecode(file_get_contents($spoolFiles[0])), true);
        $toAddresses = array_column($spoolData['to'], 'address');

        // Verify the original address was used.
        $this->assertContains($originalEmail, $toAddresses);
    }

    /**
     * Test that --send-to properly clears and replaces the mailable's to address.
     *
     * This is the key regression test - the mailable builds with the recipient's
     * real address in the content, but --send-to should override where it's delivered.
     */
    public function test_send_to_replaces_mailable_to_address(): void
    {
        // Create test users using helper methods.
        $recipient = $this->createTestUser(['fullname' => 'Real User']);
        $sender = $this->createTestUser(['fullname' => 'Test Sender']);

        // Use unique emails for this test to avoid parallel test collisions.
        $realUserEmail = $this->uniqueEmail('realuser');
        $testDeliveryEmail = $this->uniqueEmail('testdelivery');

        // Update recipient's email to our unique address.
        UserEmail::where('userid', $recipient->id)->update(['email' => $realUserEmail]);
        $recipient = $recipient->fresh();

        // Create a chat room.
        $chatRoom = $this->createTestChatRoom($recipient, $sender);

        // Create a chat message from sender to recipient.
        $this->createTestChatMessage($chatRoom, $sender, [
            'message' => 'Test message for address override test',
        ]);

        // Run the command with --send-to to a different address.
        $this->artisan('mail:test', [
            'type' => 'chat:user2user',
            '--to' => $realUserEmail,
            '--send-to' => $testDeliveryEmail,
            '--chat' => $chatRoom->id,
        ])->assertExitCode(0);

        // Check spool file for the delivery address.
        $spoolFiles = glob($this->spoolDir . '/sent/*.json.gz');
        $this->assertNotEmpty($spoolFiles, 'Should have a spool file in sent directory');

        $spoolData = json_decode(gzdecode(file_get_contents($spoolFiles[0])), true);
        $toAddresses = array_column($spoolData['to'], 'address');

        // Verify the override address was used.
        $this->assertContains($testDeliveryEmail, $toAddresses);
        $this->assertNotContains($realUserEmail, $toAddresses);

        // Verify the email content still references the sender (not the override address).
        $this->assertStringContainsString('Test Sender', $spoolData['html']);
    }
}
