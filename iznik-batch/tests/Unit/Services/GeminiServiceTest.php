<?php

namespace Tests\Unit\Services;

use App\Services\GeminiService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('Unit')]
class GeminiServiceTest extends TestCase
{
    private const API_KEY = 'test-api-key-12345';
    private const MODELS_URL = 'https://generativelanguage.googleapis.com/v1beta/models*';
    private const GENERATE_URL = 'https://generativelanguage.googleapis.com/v1beta/models/:generateContent*';

    protected function setUp(): void
    {
        parent::setUp();
        config(['freegle.git_summary.gemini_api_key' => self::API_KEY]);
    }

    // ── isConfigured ────────────────────────────────────────────────────────

    public function test_is_configured_returns_false_when_no_key(): void
    {
        config(['freegle.git_summary.gemini_api_key' => '']);
        $service = new GeminiService();
        $this->assertFalse($service->isConfigured());
    }

    public function test_is_configured_returns_true_when_key_set(): void
    {
        $service = new GeminiService();
        $this->assertTrue($service->isConfigured());
    }

    // ── getModel ────────────────────────────────────────────────────────────

    public function test_get_model_returns_highest_version_flash_model(): void
    {
        Http::fake([
            self::MODELS_URL => Http::response($this->flashModels([
                'models/gemini-1.5-flash' => '1.5',
                'models/gemini-2.0-flash' => '2.0',
                'models/gemini-1.0-flash' => '1.0',
            ]), 200),
        ]);

        Log::shouldReceive('info')->zeroOrMoreTimes();

        $service = new GeminiService();
        $this->assertSame('gemini-2.0-flash', $service->getModel());
    }

    public function test_get_model_strips_models_prefix(): void
    {
        Http::fake([
            self::MODELS_URL => Http::response($this->flashModels(['models/gemini-1.5-flash']), 200),
        ]);

        Log::shouldReceive('info')->zeroOrMoreTimes();

        $service = new GeminiService();
        $model = $service->getModel();

        $this->assertStringStartsNotWith('models/', $model);
        $this->assertSame('gemini-1.5-flash', $model);
    }

    public function test_get_model_excludes_experimental_models(): void
    {
        Http::fake([
            self::MODELS_URL => Http::response([
                'models' => [
                    $this->modelEntry('models/gemini-2.0-flash-exp'),
                    $this->modelEntry('models/gemini-1.5-flash'),
                ],
            ], 200),
        ]);

        Log::shouldReceive('info')->zeroOrMoreTimes();

        $service = new GeminiService();
        $this->assertSame('gemini-1.5-flash', $service->getModel());
    }

    public function test_get_model_excludes_vision_only_models(): void
    {
        Http::fake([
            self::MODELS_URL => Http::response([
                'models' => [
                    $this->modelEntry('models/gemini-1.5-flash-vision'),
                    $this->modelEntry('models/gemini-1.5-flash'),
                ],
            ], 200),
        ]);

        Log::shouldReceive('info')->zeroOrMoreTimes();

        $service = new GeminiService();
        $this->assertSame('gemini-1.5-flash', $service->getModel());
    }

    public function test_get_model_excludes_models_without_generate_content(): void
    {
        Http::fake([
            self::MODELS_URL => Http::response([
                'models' => [
                    $this->modelEntry('models/gemini-2.0-flash', ['embedContent']),
                    $this->modelEntry('models/gemini-1.5-flash'),
                ],
            ], 200),
        ]);

        Log::shouldReceive('info')->zeroOrMoreTimes();

        $service = new GeminiService();
        $this->assertSame('gemini-1.5-flash', $service->getModel());
    }

    public function test_get_model_falls_back_to_gemini_pro_when_no_flash_models(): void
    {
        Http::fake([
            self::MODELS_URL => Http::response([
                'models' => [
                    $this->modelEntry('models/gemini-pro'),
                ],
            ], 200),
        ]);

        Log::shouldReceive('warning')->once()->withArgs(fn ($m) => str_contains($m, 'No flash models'));
        Log::shouldReceive('info')->zeroOrMoreTimes();

        $service = new GeminiService();
        $this->assertSame('gemini-pro', $service->getModel());
    }

    public function test_get_model_falls_back_to_gemini_pro_on_api_error(): void
    {
        Http::fake([
            self::MODELS_URL => Http::response([], 500),
        ]);

        Log::shouldReceive('warning')->once()->withArgs(fn ($m) => str_contains($m, 'Failed to list models'));

        $service = new GeminiService();
        $this->assertSame('gemini-pro', $service->getModel());
    }

    public function test_get_model_caches_result_after_first_call(): void
    {
        Http::fake([
            self::MODELS_URL => Http::response($this->flashModels(['models/gemini-1.5-flash']), 200),
        ]);

        Log::shouldReceive('info')->zeroOrMoreTimes();

        $service = new GeminiService();
        $first = $service->getModel();
        $second = $service->getModel();

        $this->assertSame($first, $second);
        Http::assertSentCount(1);
    }

    // ── generateContent ─────────────────────────────────────────────────────

    public function test_generate_content_returns_null_when_not_configured(): void
    {
        config(['freegle.git_summary.gemini_api_key' => '']);

        Log::shouldReceive('error')->once()->withArgs(fn ($m) => str_contains($m, 'API key not configured'));

        $service = new GeminiService();
        $this->assertNull($service->generateContent('Test prompt'));
    }

    public function test_generate_content_returns_text_on_success(): void
    {
        $this->fakeModelsAndContent('gemini-1.5-flash', 'Generated response text');

        $service = new GeminiService();
        $this->assertSame('Generated response text', $service->generateContent('What is Freegle?'));
    }

    public function test_generate_content_returns_null_on_api_failure(): void
    {
        $model = 'gemini-1.5-flash';
        $generateUrl = 'https://generativelanguage.googleapis.com/v1beta/models/'.$model.':generateContent*';

        Http::fake([
            // More specific generate-content pattern first.
            $generateUrl => Http::response([], 503),
            self::MODELS_URL => Http::response($this->flashModels(['models/'.$model]), 200),
        ]);

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('error')->once()->withArgs(fn ($m) => str_contains($m, 'API request failed'));

        $service = new GeminiService();
        $this->assertNull($service->generateContent('Test prompt'));
    }

    public function test_generate_content_returns_null_on_empty_response(): void
    {
        $model = 'gemini-1.5-flash';
        $generateUrl = 'https://generativelanguage.googleapis.com/v1beta/models/'.$model.':generateContent*';

        Http::fake([
            $generateUrl => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => '']]]]],
            ], 200),
            self::MODELS_URL => Http::response($this->flashModels(['models/'.$model]), 200),
        ]);

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->once()->withArgs(fn ($m) => str_contains($m, 'Empty response'));

        $service = new GeminiService();
        $this->assertNull($service->generateContent('Test prompt'));
    }

    // ── generateJson ─────────────────────────────────────────────────────────

    public function test_generate_json_returns_null_when_content_is_null(): void
    {
        config(['freegle.git_summary.gemini_api_key' => '']);
        Log::shouldReceive('error')->once();

        $service = new GeminiService();
        $this->assertNull($service->generateJson('Return JSON please'));
    }

    public function test_generate_json_parses_plain_json(): void
    {
        $this->fakeModelsAndContent('gemini-1.5-flash', '{"status": "ok", "count": 42}');

        $service = new GeminiService();
        $result = $service->generateJson('Return JSON');

        $this->assertIsArray($result);
        $this->assertSame('ok', $result['status']);
        $this->assertSame(42, $result['count']);
    }

    public function test_generate_json_parses_json_fenced_in_markdown(): void
    {
        $this->fakeModelsAndContent('gemini-1.5-flash', "```json\n{\"key\": \"value\", \"items\": [1, 2, 3]}\n```");

        $service = new GeminiService();
        $result = $service->generateJson('Return JSON');

        $this->assertIsArray($result);
        $this->assertSame('value', $result['key']);
        $this->assertSame([1, 2, 3], $result['items']);
    }

    public function test_generate_json_parses_generic_fenced_code_block(): void
    {
        $this->fakeModelsAndContent('gemini-1.5-flash', "```\n{\"type\": \"offer\", \"valid\": true}\n```");

        $service = new GeminiService();
        $result = $service->generateJson('Return JSON');

        $this->assertIsArray($result);
        $this->assertSame('offer', $result['type']);
        $this->assertTrue($result['valid']);
    }

    public function test_generate_json_returns_null_on_invalid_json(): void
    {
        $this->fakeModelsAndContent('gemini-1.5-flash', 'This is not JSON at all');

        Log::shouldReceive('warning')->once()->withArgs(fn ($m) => str_contains($m, 'Failed to parse JSON'));

        $service = new GeminiService();
        $this->assertNull($service->generateJson('Return JSON'));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Build a models-list response body. $models is a list of model names, or
     * an associative array of name => version string (used for sorting tests).
     */
    private function flashModels(array $models): array
    {
        $entries = [];
        foreach ($models as $key => $value) {
            // If key is int, $value is the model name; otherwise key is name, value is ignored.
            $name = is_int($key) ? $value : $key;
            $entries[] = $this->modelEntry($name);
        }

        return ['models' => $entries];
    }

    private function modelEntry(string $name, array $methods = ['generateContent']): array
    {
        return [
            'name' => $name,
            'displayName' => ucwords(str_replace(['-', '/'], ' ', str_replace('models/', '', $name))),
            'supportedGenerationMethods' => $methods,
        ];
    }

    private function fakeModelsAndContent(string $modelName, string $generatedText): void
    {
        // The generate-content URL pattern is more specific; it MUST be listed
        // first because Http::fake uses first-match-wins, and 'models*' would
        // otherwise greedily swallow the generate-content request too.
        $generateUrl = 'https://generativelanguage.googleapis.com/v1beta/models/'.$modelName.':generateContent*';

        Http::fake([
            $generateUrl => Http::response([
                'candidates' => [
                    ['content' => ['parts' => [['text' => $generatedText]]]],
                ],
            ], 200),
            self::MODELS_URL => Http::response($this->flashModels(['models/'.$modelName]), 200),
        ]);

        Log::shouldReceive('info')->zeroOrMoreTimes();
    }
}
