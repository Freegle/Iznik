<?php

namespace Tests\Unit\Services;

use App\Services\EeeVisionService;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EeeVisionServiceTest extends TestCase
{
    private EeeVisionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EeeVisionService();
    }

    public function test_fetch_image_rejects_non_image_content_type(): void
    {
        Http::fake([
            'https://delivery.ilovefreegle.org*' => Http::response(
                '{"status":"error","code":404,"message":"The requested URL timed out"}',
                200,
                ['Content-Type' => 'application/json']
            ),
        ]);

        $result = $this->callFetchImageBase64('https://delivery.ilovefreegle.org?url=test&w=768&h=768');

        $this->assertNull($result, 'Should return null when server returns non-image Content-Type');
    }

    public function test_fetch_image_rejects_text_html_content_type(): void
    {
        Http::fake([
            'https://delivery.ilovefreegle.org*' => Http::response(
                '<html><body>Error</body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
        ]);

        $result = $this->callFetchImageBase64('https://delivery.ilovefreegle.org?url=test&w=768&h=768');

        $this->assertNull($result, 'Should return null when server returns HTML instead of image');
    }

    public function test_fetch_image_accepts_jpeg_content_type(): void
    {
        $fakeJpeg = "\xFF\xD8\xFF\xE0" . str_repeat('x', 100); // minimal JPEG-like bytes

        Http::fake([
            'https://delivery.ilovefreegle.org*' => Http::response(
                $fakeJpeg,
                200,
                ['Content-Type' => 'image/jpeg']
            ),
        ]);

        $result = $this->callFetchImageBase64('https://delivery.ilovefreegle.org?url=test&w=768&h=768');

        $this->assertNotNull($result);
        $this->assertSame('image/jpeg', $result['mime_type']);
        $this->assertSame(base64_encode($fakeJpeg), $result['base64']);
    }

    public function test_fetch_image_accepts_webp_content_type(): void
    {
        Http::fake([
            'https://delivery.ilovefreegle.org*' => Http::response(
                'RIFF' . str_repeat('x', 100),
                200,
                ['Content-Type' => 'image/webp']
            ),
        ]);

        $result = $this->callFetchImageBase64('https://delivery.ilovefreegle.org?url=test&w=768&h=768');

        $this->assertNotNull($result);
        $this->assertSame('image/webp', $result['mime_type']);
    }

    public function test_fetch_image_returns_null_on_4xx(): void
    {
        Http::fake([
            'https://delivery.ilovefreegle.org*' => Http::response('Not Found', 404),
        ]);

        $result = $this->callFetchImageBase64('https://delivery.ilovefreegle.org?url=test&w=768&h=768');

        $this->assertNull($result);
    }

    public function test_build_image_url_strips_the_tus_prefix(): void
    {
        // freegletusd- marks who uploaded the image; it is not part of the stored object name,
        // so it has to come off before the delivery proxy is asked for the object.
        $url = EeeVisionService::buildImageUrl('freegletusd-abc123');

        $this->assertStringContainsString('uploads.ilovefreegle.org:8080/abc123', $url);
        $this->assertStringNotContainsString('freegletusd-', $url);
        $this->assertStringStartsWith('https://delivery.ilovefreegle.org?url=', $url);
    }

    public function test_build_image_url_passes_an_unprefixed_id_through(): void
    {
        // Uploadcare is retired, so an id without the prefix is no longer sent to ucarecdn.com -
        // it goes to our own delivery proxy like everything else.
        $url = EeeVisionService::buildImageUrl('abc123');

        $this->assertStringContainsString('uploads.ilovefreegle.org:8080/abc123', $url);
        $this->assertStringNotContainsString('ucarecdn', $url);
    }

    private function callFetchImageBase64(string $url): ?array
    {
        $reflection = new \ReflectionMethod(EeeVisionService::class, 'fetchImageBase64');
        $reflection->setAccessible(true);
        return $reflection->invoke($this->service, $url);
    }
}
