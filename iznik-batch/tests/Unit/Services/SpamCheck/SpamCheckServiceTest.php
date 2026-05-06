<?php

namespace Tests\Unit\Services\SpamCheck;

use App\Services\SpamCheck\SpamCheckService;
use App\Services\SpamCheck\SpamResult;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SpamCheckServiceTest extends TestCase
{
    public function test_class_exists(): void
    {
        $this->assertTrue(class_exists(SpamCheckService::class));
    }

    public function test_is_disabled_by_default(): void
    {
        $this->assertFalse(SpamCheckService::isEnabled());
    }

    public function test_check_rspamd_returns_spam_result_on_success(): void
    {
        Http::fake([
            '*' => Http::response([
                'score' => 2.5,
                'action' => 'no action',
                'symbols' => [
                    'DKIM_SIGNED' => ['score' => 0.1],
                ],
            ], 200),
        ]);

        $service = new SpamCheckService();
        $result = $service->checkRspamd("From: test@example.com\r\nSubject: Test\r\n\r\nBody");

        $this->assertInstanceOf(SpamResult::class, $result);
        $this->assertNull($result->error);
        $this->assertFalse($result->isSpam);
        $this->assertEquals(2.5, $result->score);
    }

    public function test_check_rspamd_returns_error_on_http_failure(): void
    {
        Http::fake([
            '*' => Http::response('Server Error', 500),
        ]);

        $service = new SpamCheckService();
        $result = $service->checkRspamd('test email');

        $this->assertInstanceOf(SpamResult::class, $result);
        $this->assertNotNull($result->error);
        $this->assertStringContainsString('HTTP 500', $result->error);
    }

    public function test_check_rspamd_returns_error_on_empty_json(): void
    {
        Http::fake([
            '*' => Http::response('', 200),
        ]);

        $service = new SpamCheckService();
        $result = $service->checkRspamd('test email');

        $this->assertInstanceOf(SpamResult::class, $result);
        $this->assertNotNull($result->error);
    }

    public function test_check_spamassassin_returns_spam_result(): void
    {
        $service = new SpamCheckService();
        $result = $service->checkSpamAssassin("From: test@example.com\r\nSubject: Test\r\n\r\nTest body");

        $this->assertInstanceOf(SpamResult::class, $result);
        $this->assertEquals('SpamAssassin', $result->engine);
    }

    public function test_check_all_returns_keyed_array(): void
    {
        Http::fake([
            '*' => Http::response([
                'score' => 0.1,
                'action' => 'no action',
                'symbols' => [],
            ], 200),
        ]);

        $service = new SpamCheckService();
        $results = $service->checkAll('test email');

        $this->assertIsArray($results);
        $this->assertArrayHasKey('spamassassin', $results);
        $this->assertArrayHasKey('rspamd', $results);
        $this->assertInstanceOf(SpamResult::class, $results['spamassassin']);
        $this->assertInstanceOf(SpamResult::class, $results['rspamd']);
    }
}
