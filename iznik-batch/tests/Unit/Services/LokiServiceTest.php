<?php

namespace Tests\Unit\Services;

use App\Services\LokiService;
use Tests\TestCase;

class LokiServiceTest extends TestCase
{
    protected string $testLogPath;

    protected LokiService $lokiService;

    protected function setUp(): void
    {
        parent::setUp();

        // Use a test-specific log path.
        $this->testLogPath = storage_path('logs/loki-test-'.uniqid());
        if (! is_dir($this->testLogPath)) {
            mkdir($this->testLogPath, 0755, true);
        }

        // Configure Loki to use test path.
        config([
            'freegle.loki.enabled' => true,
            'freegle.loki.log_path' => $this->testLogPath,
        ]);

        $this->lokiService = new LokiService;
    }

    protected function tearDown(): void
    {
        // Clean up test log path.
        if (is_dir($this->testLogPath)) {
            $this->recursiveDelete($this->testLogPath);
        }

        parent::tearDown();
    }

    protected function recursiveDelete(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir.'/'.$file;
            if (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    public function test_is_enabled_returns_config_value(): void
    {
        config(['freegle.loki.enabled' => true]);
        $service = new LokiService;
        $this->assertTrue($service->isEnabled());

        config(['freegle.loki.enabled' => false]);
        $service = new LokiService;
        $this->assertFalse($service->isEnabled());
    }

    public function test_log_batch_job_does_nothing_when_disabled(): void
    {
        config(['freegle.loki.enabled' => false]);
        $service = new LokiService;

        $service->logBatchJob('TestJob', 'started', ['key' => 'value']);

        // File should not be created.
        $this->assertFileDoesNotExist($this->testLogPath.'/batch.log');
    }

    public function test_log_batch_job_writes_to_file_when_enabled(): void
    {
        $this->lokiService->logBatchJob('TestJob', 'started', ['items' => 10]);

        $logFile = $this->testLogPath.'/batch.log';
        $this->assertFileExists($logFile);

        $content = file_get_contents($logFile);
        $entry = json_decode(trim($content), true);

        $this->assertArrayHasKey('timestamp', $entry);
        $this->assertArrayHasKey('labels', $entry);
        $this->assertArrayHasKey('message', $entry);

        $this->assertEquals('freegle', $entry['labels']['app']);
        $this->assertEquals('batch', $entry['labels']['source']);
        $this->assertEquals('TestJob', $entry['labels']['job_name']);
        $this->assertEquals('started', $entry['labels']['event']);

        $this->assertEquals('TestJob', $entry['message']['job']);
        $this->assertEquals('started', $entry['message']['event']);
        $this->assertEquals(10, $entry['message']['items']);
    }

    public function test_log_email_send_does_nothing_when_disabled(): void
    {
        config(['freegle.loki.enabled' => false]);
        $service = new LokiService;

        $service->logEmailSend('digest', $this->uniqueEmail('loki'), 'Test Subject');

        $this->assertFileDoesNotExist($this->testLogPath.'/email.log');
    }

    public function test_log_email_send_writes_to_file_when_enabled(): void
    {
        $email = $this->uniqueEmail('loki');
        $this->lokiService->logEmailSend(
            'digest',
            $email,
            'Test Subject',
            123,
            456,
            'freegle-abc123_def456',
            ['extra' => 'data']
        );

        $logFile = $this->testLogPath.'/email.log';
        $this->assertFileExists($logFile);

        $content = file_get_contents($logFile);
        $entry = json_decode(trim($content), true);

        $this->assertEquals('freegle', $entry['labels']['app']);
        $this->assertEquals('email', $entry['labels']['source']);
        $this->assertEquals('digest', $entry['labels']['email_type']);
        $this->assertEquals('sent', $entry['labels']['event']);
        $this->assertEquals('123', $entry['labels']['user_id']);
        $this->assertEquals('456', $entry['labels']['groupid']);
        $this->assertEquals('freegle-abc123_def456', $entry['labels']['trace_id']);

        $this->assertEquals($email, $entry['message']['recipient']);
        $this->assertEquals('Test Subject', $entry['message']['subject']);
        $this->assertEquals(123, $entry['message']['user_id']);
        $this->assertEquals(456, $entry['message']['group_id']);
        $this->assertEquals('freegle-abc123_def456', $entry['message']['trace_id']);
        $this->assertEquals('data', $entry['message']['extra']);
    }

    public function test_log_email_send_includes_mailable_class_label(): void
    {
        $this->lokiService->logEmailSend(
            'WelcomeMail',
            $this->uniqueEmail('loki'),
            'Welcome to Freegle!',
            123,
            null,
            'freegle-trace123',
            ['mailable_class' => 'App\\Mail\\Welcome\\WelcomeMail']
        );

        $logFile = $this->testLogPath.'/email.log';
        $content = file_get_contents($logFile);
        $entry = json_decode(trim($content), true);

        // Should extract just the class basename.
        $this->assertEquals('WelcomeMail', $entry['labels']['mailable_class']);
    }

    public function test_log_email_send_omits_trace_id_label_when_null(): void
    {
        $this->lokiService->logEmailSend(
            'digest',
            $this->uniqueEmail('loki'),
            'Test Subject',
            123,
            456,
            null,
            []
        );

        $logFile = $this->testLogPath.'/email.log';
        $content = file_get_contents($logFile);
        $entry = json_decode(trim($content), true);

        $this->assertArrayNotHasKey('trace_id', $entry['labels']);
    }

    public function test_log_email_send_omits_null_user_id_from_labels(): void
    {
        $this->lokiService->logEmailSend(
            'notification',
            $this->uniqueEmail('loki'),
            'Test Subject',
            null,
            null,
            null,
            []
        );

        $logFile = $this->testLogPath.'/email.log';
        $content = file_get_contents($logFile);
        $entry = json_decode(trim($content), true);

        $this->assertArrayNotHasKey('user_id', $entry['labels']);
        $this->assertArrayNotHasKey('groupid', $entry['labels']);
    }

    public function test_log_event_does_nothing_when_disabled(): void
    {
        config(['freegle.loki.enabled' => false]);
        $service = new LokiService;

        $service->logEvent('purge', 'messages', ['count' => 100]);

        $this->assertFileDoesNotExist($this->testLogPath.'/batch_event.log');
    }

    public function test_log_event_writes_to_file_when_enabled(): void
    {
        $this->lokiService->logEvent('purge', 'messages', ['count' => 100]);

        $logFile = $this->testLogPath.'/batch_event.log';
        $this->assertFileExists($logFile);

        $content = file_get_contents($logFile);
        $entry = json_decode(trim($content), true);

        $this->assertEquals('freegle', $entry['labels']['app']);
        $this->assertEquals('batch_event', $entry['labels']['source']);
        $this->assertEquals('purge', $entry['labels']['type']);
        $this->assertEquals('messages', $entry['labels']['subtype']);

        $this->assertEquals('purge', $entry['message']['type']);
        $this->assertEquals('messages', $entry['message']['subtype']);
        $this->assertEquals(100, $entry['message']['count']);
    }

    public function test_log_event_includes_groupid_in_labels_when_present(): void
    {
        $this->lokiService->logEvent('digest', 'sent', [
            'groupid' => 789,
            'recipients' => 50,
        ]);

        $logFile = $this->testLogPath.'/batch_event.log';
        $content = file_get_contents($logFile);
        $entry = json_decode(trim($content), true);

        $this->assertEquals('789', $entry['labels']['groupid']);
    }

    public function test_multiple_log_entries_append_to_file(): void
    {
        $this->lokiService->logBatchJob('Job1', 'started');
        $this->lokiService->logBatchJob('Job1', 'completed');

        $logFile = $this->testLogPath.'/batch.log';
        $content = file_get_contents($logFile);
        $lines = array_filter(explode("\n", $content));

        $this->assertCount(2, $lines);
    }

    public function test_log_creates_directory_if_not_exists(): void
    {
        $nestedPath = $this->testLogPath.'/nested/deep/path';
        config(['freegle.loki.log_path' => $nestedPath]);

        $service = new LokiService;
        $service->logBatchJob('TestJob', 'started');

        $this->assertFileExists($nestedPath.'/batch.log');
    }

    public function test_log_incoming_email_writes_to_file(): void
    {
        $this->lokiService->logIncomingEmail(
            'sender@example.com',
            'group@groups.ilovefreegle.org',
            'sender@example.com',
            'OFFER: Test Item',
            '<msg-123@example.com>',
            'Pending',
        );

        $logFile = $this->testLogPath.'/incoming_mail.log';
        $this->assertFileExists($logFile);

        $content = file_get_contents($logFile);
        $entry = json_decode(trim($content), true);

        $this->assertEquals('freegle', $entry['labels']['app']);
        $this->assertEquals('incoming_mail', $entry['labels']['source']);
        $this->assertEquals('routed', $entry['labels']['type']);
        $this->assertEquals('Pending', $entry['labels']['subtype']);

        $this->assertEquals('sender@example.com', $entry['message']['envelope_from']);
        $this->assertEquals('group@groups.ilovefreegle.org', $entry['message']['envelope_to']);
        $this->assertEquals('OFFER: Test Item', $entry['message']['subject']);
        $this->assertEquals('Pending', $entry['message']['routing_outcome']);
    }

    public function test_log_incoming_email_includes_context(): void
    {
        $this->lokiService->logIncomingEmail(
            'sender@example.com',
            'group@groups.ilovefreegle.org',
            'sender@example.com',
            'OFFER: Test Item',
            '<msg-123@example.com>',
            'Pending',
            ['group_id' => 42, 'group_name' => 'FreeglePlayground', 'user_id' => 99],
        );

        $logFile = $this->testLogPath.'/incoming_mail.log';
        $content = file_get_contents($logFile);
        $entry = json_decode(trim($content), true);

        $this->assertEquals(42, $entry['message']['group_id']);
        $this->assertEquals('FreeglePlayground', $entry['message']['group_name']);
        $this->assertEquals(99, $entry['message']['user_id']);
    }

    public function test_log_bounce_event_writes_to_file(): void
    {
        $this->lokiService->logBounceEvent(
            'user@example.com',
            123,
            true,
            'Mailbox not found',
        );

        $logFile = $this->testLogPath.'/bounce.log';
        $this->assertFileExists($logFile);

        $content = file_get_contents($logFile);
        $entry = json_decode(trim($content), true);

        $this->assertEquals('freegle', $entry['labels']['app']);
        $this->assertEquals('bounce', $entry['labels']['source']);
        $this->assertEquals('bounced', $entry['labels']['type']);
        $this->assertEquals('permanent', $entry['labels']['subtype']);

        $this->assertEquals('user@example.com', $entry['message']['email']);
        $this->assertEquals(123, $entry['message']['user_id']);
        $this->assertTrue($entry['message']['is_permanent']);
        $this->assertEquals('Mailbox not found', $entry['message']['reason']);
    }

    public function test_log_bounce_event_temporary(): void
    {
        $this->lokiService->logBounceEvent(
            'user@example.com',
            456,
            false,
            'Mailbox full',
        );

        $logFile = $this->testLogPath.'/bounce.log';
        $content = file_get_contents($logFile);
        $entry = json_decode(trim($content), true);

        $this->assertEquals('temporary', $entry['labels']['subtype']);
        $this->assertFalse($entry['message']['is_permanent']);
    }

    public function test_log_bounce_event_does_nothing_when_disabled(): void
    {
        config(['freegle.loki.enabled' => false]);
        $service = new LokiService;

        $service->logBounceEvent('user@example.com', 123, true, 'Bounce');

        $this->assertFileDoesNotExist($this->testLogPath.'/bounce.log');
    }

    public function test_log_entries_are_valid_json(): void
    {
        $this->lokiService->logBatchJob('TestJob', 'started', [
            'special' => 'chars "quotes" and \\backslash',
            'unicode' => '日本語',
        ]);

        $logFile = $this->testLogPath.'/batch.log';
        $content = file_get_contents($logFile);
        $entry = json_decode(trim($content), true);

        $this->assertNotNull($entry);
        $this->assertEquals('chars "quotes" and \\backslash', $entry['message']['special']);
        $this->assertEquals('日本語', $entry['message']['unicode']);
    }
}
