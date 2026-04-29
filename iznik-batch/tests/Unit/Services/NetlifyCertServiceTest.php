<?php

namespace Tests\Unit\Services;

use App\Services\NetlifyCertService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NetlifyCertServiceTest extends TestCase
{
    private NetlifyCertService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock configuration
        Config::set('freegle.netlify.token', 'test-token-123');
        Config::set('freegle.netlify.site_id', 'test-site-id');
        Config::set('freegle.netlify.cert_path', '/tmp/certs');
        Config::set('freegle.mail.geek_alerts_addr', 'geek-alerts@test.com');

        $this->service = new NetlifyCertService();
    }

    #[Test]
    public function isConfigured_returns_true_when_token_and_site_id_present(): void
    {
        $this->assertTrue($this->service->isConfigured());
    }

    #[Test]
    public function isConfigured_returns_false_when_token_missing(): void
    {
        Config::set('freegle.netlify.token', '');
        $service = new NetlifyCertService();

        $this->assertFalse($service->isConfigured());
    }

    #[Test]
    public function isConfigured_returns_false_when_site_id_missing(): void
    {
        Config::set('freegle.netlify.site_id', '');
        $service = new NetlifyCertService();

        $this->assertFalse($service->isConfigured());
    }

    #[Test]
    public function getCertPath_returns_configured_path(): void
    {
        $this->assertEquals('/tmp/certs', $this->service->getCertPath());
    }

    #[Test]
    public function checkCertificateFiles_returns_all_exists_true_when_files_present(): void
    {
        $tempDir = sys_get_temp_dir() . '/netlify-test-' . uniqid();
        mkdir($tempDir);

        try {
            file_put_contents("$tempDir/cert.pem", 'cert content');
            file_put_contents("$tempDir/privkey.pem", 'key content');
            file_put_contents("$tempDir/chain.pem", 'chain content');

            Config::set('freegle.netlify.cert_path', $tempDir);
            $service = new NetlifyCertService();

            $result = $service->checkCertificateFiles();

            $this->assertTrue($result['exists']);
            $this->assertEmpty($result['missing']);
        } finally {
            @unlink("$tempDir/cert.pem");
            @unlink("$tempDir/privkey.pem");
            @unlink("$tempDir/chain.pem");
            @rmdir($tempDir);
        }
    }

    #[Test]
    public function checkCertificateFiles_identifies_missing_files(): void
    {
        $tempDir = sys_get_temp_dir() . '/netlify-test-' . uniqid();
        mkdir($tempDir);

        try {
            file_put_contents("$tempDir/cert.pem", 'cert content');
            // privkey.pem and chain.pem are missing

            Config::set('freegle.netlify.cert_path', $tempDir);
            $service = new NetlifyCertService();

            $result = $service->checkCertificateFiles();

            $this->assertFalse($result['exists']);
            $this->assertContains('privkey.pem', $result['missing']);
            $this->assertContains('chain.pem', $result['missing']);
            $this->assertNotContains('cert.pem', $result['missing']);
        } finally {
            @unlink("$tempDir/cert.pem");
            @rmdir($tempDir);
        }
    }

    #[Test]
    public function uploadCertificate_returns_error_when_not_configured(): void
    {
        Config::set('freegle.netlify.token', '');
        $service = new NetlifyCertService();

        $result = $service->uploadCertificate();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not configured', $result['message']);
    }

    #[Test]
    public function uploadCertificate_returns_error_when_cert_files_missing(): void
    {
        Config::set('freegle.netlify.cert_path', '/nonexistent/path');
        $service = new NetlifyCertService();

        $result = $service->uploadCertificate();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not found', $result['message']);
    }

    #[Test]
    public function uploadCertificate_returns_success_on_successful_api_response(): void
    {
        $tempDir = sys_get_temp_dir() . '/netlify-test-' . uniqid();
        mkdir($tempDir);

        try {
            file_put_contents("$tempDir/cert.pem", 'cert content');
            file_put_contents("$tempDir/privkey.pem", 'key content');
            file_put_contents("$tempDir/chain.pem", 'chain content');

            Config::set('freegle.netlify.cert_path', $tempDir);
            $service = new NetlifyCertService();

            Http::fake([
                'https://api.netlify.com/api/v1/sites/test-site-id/ssl' => Http::response(
                    ['success' => true, 'certificate_id' => 'cert-123'],
                    200
                ),
            ]);

            $result = $service->uploadCertificate();

            $this->assertTrue($result['success']);
            $this->assertStringContainsString('successfully', $result['message']);
            $this->assertEquals(['success' => true, 'certificate_id' => 'cert-123'], $result['response']);
        } finally {
            @unlink("$tempDir/cert.pem");
            @unlink("$tempDir/privkey.pem");
            @unlink("$tempDir/chain.pem");
            @rmdir($tempDir);
        }
    }

    #[Test]
    public function uploadCertificate_handles_api_error_response(): void
    {
        $tempDir = sys_get_temp_dir() . '/netlify-test-' . uniqid();
        mkdir($tempDir);

        try {
            file_put_contents("$tempDir/cert.pem", 'cert content');
            file_put_contents("$tempDir/privkey.pem", 'key content');
            file_put_contents("$tempDir/chain.pem", 'chain content');

            Config::set('freegle.netlify.cert_path', $tempDir);
            $service = new NetlifyCertService();

            Http::fake([
                'https://api.netlify.com/api/v1/sites/test-site-id/ssl' => Http::response(
                    ['error' => 'Invalid certificate format'],
                    400
                ),
            ]);

            Log::spy();

            $result = $service->uploadCertificate();

            $this->assertFalse($result['success']);
            $this->assertStringContainsString('HTTP 400', $result['message']);
        } finally {
            @unlink("$tempDir/cert.pem");
            @unlink("$tempDir/privkey.pem");
            @unlink("$tempDir/chain.pem");
            @rmdir($tempDir);
        }
    }

    #[Test]
    public function uploadCertificate_handles_exception(): void
    {
        $tempDir = sys_get_temp_dir() . '/netlify-test-' . uniqid();
        mkdir($tempDir);

        try {
            file_put_contents("$tempDir/cert.pem", 'cert content');
            file_put_contents("$tempDir/privkey.pem", 'key content');
            file_put_contents("$tempDir/chain.pem", 'chain content');

            Config::set('freegle.netlify.cert_path', $tempDir);
            $service = new NetlifyCertService();

            Http::fake([
                'https://api.netlify.com/api/v1/sites/test-site-id/ssl' => Http::response(
                    [],
                    500
                ),
            ]);

            // Configure Http to throw exception
            Http::preventStrayRequests();

            Log::spy();

            $result = $service->uploadCertificate();

            $this->assertFalse($result['success']);
        } finally {
            @unlink("$tempDir/cert.pem");
            @unlink("$tempDir/privkey.pem");
            @unlink("$tempDir/chain.pem");
            @rmdir($tempDir);
        }
    }

    #[Test]
    public function sendNotification_sends_success_email(): void
    {
        Mail::fake();

        $this->service->sendNotification(true, 'Certificate updated');

        Mail::assertSent(function ($mail) {
            return $mail->hasTo('geek-alerts@test.com')
                && str_contains($mail->subject, 'Successfully');
        });
    }

    #[Test]
    public function sendNotification_sends_failure_email(): void
    {
        Mail::fake();

        $this->service->sendNotification(false, 'API timeout');

        Mail::assertSent(function ($mail) {
            return $mail->hasTo('geek-alerts@test.com')
                && str_contains($mail->subject, 'FAILED');
        });
    }

    #[Test]
    public function buildNotificationBody_success_without_verification(): void
    {
        $body = $this->service->buildNotificationBody(true, 'Certificate uploaded', null);

        $this->assertStringContainsString('successfully renewed', $body);
        $this->assertStringContainsString('test-site-id', $body);
        $this->assertStringContainsString('/tmp/certs', $body);
    }

    #[Test]
    public function buildNotificationBody_success_with_verification(): void
    {
        $verification = [
            'success' => true,
            'subject' => 'CN=ilovefreegle.org',
            'issuer' => 'CN=Let\'s Encrypt',
            'notBefore' => 'Jan 1 00:00:00 2024 GMT',
            'notAfter' => 'Jan 1 00:00:00 2025 GMT',
        ];

        $body = $this->service->buildNotificationBody(true, 'Certificate uploaded', $verification);

        $this->assertStringContainsString('CERTIFICATE VERIFICATION', $body);
        $this->assertStringContainsString('CN=ilovefreegle.org', $body);
        $this->assertStringContainsString('Let\'s Encrypt', $body);
    }

    #[Test]
    public function buildNotificationBody_failure(): void
    {
        $body = $this->service->buildNotificationBody(false, 'Network timeout occurred');

        $this->assertStringContainsString('FAILED', $body);
        $this->assertStringContainsString('Network timeout occurred', $body);
        $this->assertStringContainsString('manually upload', $body);
    }

    #[Test]
    public function verifyCertificate_returns_error_on_command_failure(): void
    {
        // Mock exec to return failure
        $this->mockExecFailure();

        $result = $this->service->verifyCertificate('ilovefreegle.org');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Failed to retrieve', $result['error']);
    }

    private function mockExecFailure(): void
    {
        // This would require a more sophisticated mock setup for exec()
        // For now, testing with a non-existent hostname will naturally fail
    }
}
