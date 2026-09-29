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

    public function test_text_prompts_use_the_material_focus_line_not_primary_function(): void
    {
        foreach (['buildTextSystemPrompt', 'buildSystemPrompt', 'buildTextBatchSystemPrompt'] as $method) {
            $prompt = $this->callProtected($method);

            $this->assertStringContainsString('a plug, a battery or a cable', $prompt, $method);
            $this->assertStringContainsString('fish tank with a pump', $prompt, $method);
            $this->assertStringNotContainsString('PRIMARY FUNCTION', $prompt, $method);
        }
    }

    public function test_text_prompts_offer_the_seven_uk_streams(): void
    {
        foreach (['buildTextSystemPrompt', 'buildSystemPrompt', 'buildTextBatchSystemPrompt'] as $method) {
            $prompt = $this->callProtected($method);

            foreach (EeeVisionService::WEEE_STREAMS as $n => $name) {
                $this->assertStringContainsString("{$n} = {$name}", $prompt, $method);
            }
            // The EU six split by size, which the UK streams do not.
            $this->assertStringNotContainsString('Large equipment (>50cm)', $prompt, $method);
        }
    }

    public function test_streams_are_the_seven_collection_streams_a_to_g(): void
    {
        $this->assertCount(7, EeeVisionService::WEEE_STREAMS);
        $this->assertSame(range(1, 7), array_keys(EeeVisionService::WEEE_STREAMS));
        foreach (array_values(EeeVisionService::WEEE_STREAMS) as $i => $name) {
            $this->assertStringStartsWith(chr(ord('A') + $i) . ':', $name);
        }
    }

    public function test_batch_request_numbers_listings_from_one_and_trims_descriptions(): void
    {
        $request = $this->service->buildTextBatchRequest([
            'row-9' => ['subject' => 'OFFER: Kettle', 'description' => str_repeat('x', 900)],
            'row-4' => ['subject' => 'WANTED: Sofa', 'description' => ''],
        ]);

        $items = json_decode($request['contents'][0]['parts'][0]['text'], true);
        $this->assertSame([1, 2], array_column($items, 'n'));
        $this->assertSame('OFFER: Kettle', $items[0]['t']);
        $this->assertSame(500, mb_strlen($items[0]['d']));
        $this->assertArrayNotHasKey('d', $items[1], 'An empty description is left out');
        $this->assertSame('application/json', $request['generationConfig']['response_mime_type']);
    }

    public function test_batch_answer_maps_numbers_back_to_keys(): void
    {
        $batch = ['a' => ['subject' => 'x'], 'b' => ['subject' => 'y'], 'c' => ['subject' => 'z'], 'd' => ['subject' => 'w']];

        $out = $this->service->parseTextBatchAnswer(json_encode([
            ['n' => 1, 'e' => 1, 's' => 2],
            ['n' => 2, 'e' => 0, 's' => 7],     // not electrical, so no stream
            ['n' => 3, 'e' => null, 's' => null],
            ['n' => 9, 'e' => 1, 's' => 1],     // not in the batch
        ]), $batch);

        $this->assertSame(['is_eee' => true, 'weee_stream' => 2], $out['a']);
        $this->assertSame(['is_eee' => false, 'weee_stream' => null], $out['b']);
        $this->assertSame(['is_eee' => null, 'weee_stream' => null], $out['c']);
        $this->assertArrayNotHasKey('d', $out, 'A listing the model left out is absent, not guessed');
        $this->assertCount(3, $out);
    }

    public function test_batch_answer_drops_a_stream_outside_one_to_seven(): void
    {
        $out = $this->service->parseTextBatchAnswer('[{"n":1,"e":1,"s":8}]', ['a' => ['subject' => 'x']]);

        $this->assertSame(['is_eee' => true, 'weee_stream' => null], $out['a']);
    }

    public function test_batch_answer_that_is_not_json_gives_nothing(): void
    {
        $this->assertSame([], $this->service->parseTextBatchAnswer('sorry', ['a' => ['subject' => 'x']]));
        $this->assertSame([], $this->service->parseTextBatchAnswer(null, ['a' => ['subject' => 'x']]));
    }

    public function test_classify_text_batches_calls_gemini_once_per_batch(): void
    {
        config(['freegle.eee.gemini_api_key' => 'test-key']);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates'    => [['content' => ['parts' => [['text' => '[{"n":1,"e":1,"s":2},{"n":2,"e":0,"s":null}]']]]]],
                'usageMetadata' => ['promptTokenCount' => 100, 'candidatesTokenCount' => 10],
            ]),
        ]);

        $out = (new EeeVisionService('gemini'))->classifyTextBatches([
            ['k1' => ['subject' => 'Fridge'], 'k2' => ['subject' => 'Chair']],
            ['k3' => ['subject' => 'Freezer'], 'k4' => ['subject' => 'Table']],
        ]);

        Http::assertSentCount(2);
        $this->assertSame(2, $out['k1']['weee_stream']);
        $this->assertFalse($out['k4']['is_eee']);
        $this->assertCount(4, $out);
    }

    public function test_classify_text_batches_refuses_other_drivers(): void
    {
        $this->expectException(\RuntimeException::class);
        (new EeeVisionService('claude'))->classifyTextBatches([['k' => ['subject' => 'x']]]);
    }

    public function test_gemini_batch_job_reads_either_response_shape(): void
    {
        config(['freegle.eee.gemini_api_key' => 'test-key']);
        Http::fake([
            '*/batches/nested' => Http::response([
                'metadata' => ['state' => 'BATCH_STATE_SUCCEEDED', 'batchStats' => ['requestCount' => '2']],
                'response' => ['responsesFile' => 'files/out1'],
            ]),
            '*/batches/flat' => Http::response(['state' => 'JOB_STATE_RUNNING', 'dest' => ['fileName' => 'files/out2']]),
        ]);

        $nested = $this->service->geminiBatchJob('batches/nested');
        $flat   = $this->service->geminiBatchJob('batches/flat');

        $this->assertSame(['state' => 'BATCH_STATE_SUCCEEDED', 'results_file' => 'files/out1', 'stats' => ['requestCount' => '2']], $nested);
        $this->assertSame('JOB_STATE_RUNNING', $flat['state']);
        $this->assertSame('files/out2', $flat['results_file']);
    }

    private function callProtected(string $method): string
    {
        $ref = new \ReflectionMethod(EeeVisionService::class, $method);
        $ref->setAccessible(true);
        return $ref->invoke($this->service);
    }
}
