<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Multi-model vision service for EEE image classification.
 *
 * Supported drivers: claude, gemini, openai, together, ollama.
 * Selected via config('freegle.eee.model') / EEE_MODEL env var.
 */
class EeeVisionService
{
    public const PROMPT_VERSION = '1.1.0';

    public const WEEE_CATEGORIES = [
        1 => 'Temperature exchange equipment',
        2 => 'Screens and monitors',
        3 => 'Lamps',
        4 => 'Large equipment (>50cm)',
        5 => 'Small equipment (<50cm)',
        6 => 'Small IT and telecom equipment',
    ];

    protected string $driver;

    public function __construct(protected string $modelOverride = '')
    {
        $this->driver = $modelOverride ?: config('freegle.eee.model', 'gemini');
    }

    public function withDriver(string $driver): static
    {
        $clone = clone $this;
        $clone->driver = $driver;
        return $clone;
    }

    public function getDriver(): string
    {
        return $this->driver;
    }

    public function getModelName(): string
    {
        return match ($this->driver) {
            'claude'        => config('freegle.eee.claude_model', 'claude-sonnet-4-6'),
            'claude-bridge' => 'claude-subscription-bridge',
            'gemini'        => config('freegle.eee.gemini_model', 'gemini-2.0-flash'),
            'openai'        => config('freegle.eee.openai_model', 'gpt-4o'),
            'together'      => config('freegle.eee.together_model', 'meta-llama/Llama-3.2-90B-Vision-Instruct-Turbo'),
            'ollama'        => config('freegle.eee.ollama_model', 'llama3.2-vision'),
            default         => $this->driver,
        };
    }

    public function isConfigured(): bool
    {
        return match ($this->driver) {
            'claude'        => !empty(config('freegle.eee.anthropic_api_key')),
            'claude-bridge' => is_dir(config('freegle.eee.bridge_path', '')),
            'gemini'        => !empty(config('freegle.eee.gemini_api_key')),
            'openai'        => !empty(config('freegle.eee.openai_api_key')),
            'together'      => !empty(config('freegle.eee.together_api_key')),
            'ollama'        => true,
            default         => false,
        };
    }

    public function getPromptVersion(): string
    {
        return self::PROMPT_VERSION;
    }

    /**
     * Analyse an image and return structured EEE classification.
     *
     * @param  string  $imageUrl  Publicly accessible image URL.
     * @param  array   $context   Optional: ['subject'=>..., 'description'=>..., 'chat'=>...]
     * @return array|null  Parsed classification with _meta, or null on failure.
     */
    public function analyse(string $imageUrl, array $context = []): ?array
    {
        $system   = $this->buildSystemPrompt();
        $userText = $this->buildUserText($context);

        $raw = match ($this->driver) {
            'claude'        => $this->callClaude($imageUrl, $system, $userText),
            'claude-bridge' => $this->callClaudeBridge($imageUrl, $system, $userText),
            'gemini'        => $this->callGemini($imageUrl, $system, $userText),
            'openai'        => $this->callOpenAI($imageUrl, $system, $userText),
            'together'      => $this->callTogether($imageUrl, $system, $userText),
            'ollama'        => $this->callOllama($imageUrl, $system, $userText),
            default         => null,
        };

        return $raw ? $this->parseAndAnnotate($raw) : null;
    }

    // -------------------------------------------------------------------------
    // Prompt
    // -------------------------------------------------------------------------

    protected function buildSystemPrompt(): string
    {
        $categories = implode("\n", array_map(
            fn($k, $v) => "  {$k}. {$v}",
            array_keys(self::WEEE_CATEGORIES),
            self::WEEE_CATEGORIES
        ));

        return <<<PROMPT
You are analysing a photo of a second-hand household item being given away free on Freegle.

Step 1 — Photo quality: Before examining the item, rate the photo itself.
  photo_quality: integer 1–5 where 5=sharp, well-lit, item clearly visible; 1=blurry/dark/item obscured.
  photo_quality_notes: brief note on any issues (blur, poor lighting, item only partially visible, multiple unrelated items, etc.), or null if fine.
  A low photo_quality should lower your confidence on ALL attributes below.

Step 2 — Power source: Does this item use electrical power of any kind (mains plug, battery, USB, solar, induction)? Consider unusual items: aquariums (pump/heater/light), salt lamps (bulb), baby bouncers (vibration motor), dimmer switches (electronic component), electric toothbrushes, powered toys, LED fairy lights.

Step 3 — If electrical, assign to one EU WEEE category:
{$categories}

Step 4 — Extract all attributes including completeness and value.

Return ONLY valid JSON with no markdown or explanation:
{
  "photo_quality": 1-5,
  "photo_quality_notes": "notes or null",
  "is_eee": true/false,
  "is_eee_confidence": 0.0-1.0,
  "is_eee_reasoning": "one sentence chain of thought",
  "is_unusual_eee": true/false,
  "unusual_eee_reason": "reason or null",
  "weee_category": 1-6 or null,
  "weee_category_name": "name or null",
  "weee_category_confidence": 0.0-1.0,
  "primary_item": "main item name",
  "brand": "brand or null",
  "brand_confidence": 0.0-1.0,
  "model_number": "exact model/product code if legible in photo or null",
  "model_number_confidence": 0.0-1.0,
  "material_primary": "dominant material",
  "material_secondary": "secondary material or null",
  "material_confidence": 0.0-1.0,
  "weight_kg_min": float or null,
  "weight_kg_max": float or null,
  "weight_kg_confidence": 0.0-1.0,
  "size_cm": {"w": float, "h": float, "d": float} or null,
  "size_confidence": 0.0-1.0,
  "condition": "Reusable" or "Damaged" or "Unknown",
  "condition_confidence": 0.0-1.0,
  "item_complete": true/false/null,
  "item_complete_confidence": 0.0-1.0,
  "item_complete_notes": "e.g. 'missing lid visible' or null",
  "accessories_visible": ["cable", "remote", "manual"] or [],
  "value_band_gbp": "0-20" or "20-100" or "100-500" or "500+" or null,
  "value_band_confidence": 0.0-1.0,
  "short_description": "one sentence from giver perspective",
  "long_description": "two to three sentences from giver perspective"
}
PROMPT;
    }

    protected function buildUserText(array $context): string
    {
        $parts = ['Identify this item and classify it.'];
        if (!empty($context['subject']))     $parts[] = 'Title: '          . $context['subject'];
        if (!empty($context['description'])) $parts[] = 'Description: '    . $context['description'];
        if (!empty($context['chat']))        $parts[] = 'Follow-up chat: ' . $context['chat'];
        return implode("\n", $parts);
    }

    // -------------------------------------------------------------------------
    // Drivers
    // -------------------------------------------------------------------------

    protected function callClaude(string $imageUrl, string $system, string $userText): ?array
    {
        $imageData = $this->fetchImageBase64($imageUrl);
        if (!$imageData) return null;

        $payload = [
            'model'      => $this->getModelName(),
            'max_tokens' => 1024,
            'system'     => $system,
            'messages'   => [[
                'role'    => 'user',
                'content' => [
                    ['type' => 'image', 'source' => [
                        'type'       => 'base64',
                        'media_type' => $imageData['mime_type'],
                        'data'       => $imageData['base64'],
                    ]],
                    ['type' => 'text', 'text' => $userText],
                ],
            ]],
        ];

        try {
            $response = Http::timeout(60)
                ->withHeaders([
                    'x-api-key'         => config('freegle.eee.anthropic_api_key'),
                    'anthropic-version' => '2023-06-01',
                    'content-type'      => 'application/json',
                ])
                ->post('https://api.anthropic.com/v1/messages', $payload);

            if (!$response->successful()) {
                Log::warning('EeeVisionService Claude error', ['status' => $response->status(), 'body' => substr($response->body(), 0, 300)]);
                return null;
            }

            return [
                'text'          => $response->json('content.0.text'),
                'input_tokens'  => $response->json('usage.input_tokens', 0),
                'output_tokens' => $response->json('usage.output_tokens', 0),
                'cost_usd'      => $this->estimateCost('claude',
                    $response->json('usage.input_tokens', 0),
                    $response->json('usage.output_tokens', 0)),
            ];
        } catch (\Exception $e) {
            Log::error('EeeVisionService Claude exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    protected function callGemini(string $imageUrl, string $system, string $userText): ?array
    {
        $imageData = $this->fetchImageBase64($imageUrl);
        if (!$imageData) return null;

        $model   = $this->getModelName();
        $apiKey  = config('freegle.eee.gemini_api_key');
        $url     = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        $payload = [
            'system_instruction' => ['parts' => [['text' => $system]]],
            'contents'           => [[
                'parts' => [
                    ['text' => $userText],
                    ['inline_data' => ['mime_type' => 'image/jpeg', 'data' => $imageData['base64']]],
                ],
            ]],
            'generationConfig' => ['response_mime_type' => 'application/json', 'temperature' => 0.1],
        ];

        try {
            $response = Http::timeout(60)->post($url, $payload);

            if (!$response->successful()) {
                Log::warning('EeeVisionService Gemini error', ['status' => $response->status(), 'body' => substr($response->body(), 0, 300)]);
                return null;
            }

            $inputTokens  = $response->json('usageMetadata.promptTokenCount', 0);
            $outputTokens = $response->json('usageMetadata.candidatesTokenCount', 0);

            return [
                'text'          => $response->json('candidates.0.content.parts.0.text'),
                'input_tokens'  => $inputTokens,
                'output_tokens' => $outputTokens,
                'cost_usd'      => $this->estimateCost('gemini', $inputTokens, $outputTokens),
            ];
        } catch (\Exception $e) {
            Log::error('EeeVisionService Gemini exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    protected function callOpenAI(string $imageUrl, string $system, string $userText): ?array
    {
        $payload = [
            'model'           => $this->getModelName(),
            'max_tokens'      => 1024,
            'temperature'     => 0.1,
            'response_format' => ['type' => 'json_object'],
            'messages'        => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => [
                    ['type' => 'text', 'text' => $userText],
                    ['type' => 'image_url', 'image_url' => ['url' => $imageUrl, 'detail' => 'low']],
                ]],
            ],
        ];

        try {
            $response = Http::timeout(60)
                ->withToken(config('freegle.eee.openai_api_key'))
                ->post('https://api.openai.com/v1/chat/completions', $payload);

            if (!$response->successful()) {
                Log::warning('EeeVisionService OpenAI error', ['status' => $response->status(), 'body' => substr($response->body(), 0, 300)]);
                return null;
            }

            $inputTokens  = $response->json('usage.prompt_tokens', 0);
            $outputTokens = $response->json('usage.completion_tokens', 0);

            return [
                'text'          => $response->json('choices.0.message.content'),
                'input_tokens'  => $inputTokens,
                'output_tokens' => $outputTokens,
                'cost_usd'      => $this->estimateCost('openai', $inputTokens, $outputTokens),
            ];
        } catch (\Exception $e) {
            Log::error('EeeVisionService OpenAI exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    protected function callTogether(string $imageUrl, string $system, string $userText): ?array
    {
        // Together.ai uses the OpenAI-compatible chat completions endpoint.
        $payload = [
            'model'       => $this->getModelName(),
            'max_tokens'  => 1024,
            'temperature' => 0.1,
            'messages'    => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => [
                    ['type' => 'text', 'text' => $userText],
                    ['type' => 'image_url', 'image_url' => ['url' => $imageUrl]],
                ]],
            ],
        ];

        try {
            $response = Http::timeout(90)
                ->withToken(config('freegle.eee.together_api_key'))
                ->post('https://api.together.xyz/v1/chat/completions', $payload);

            if (!$response->successful()) {
                Log::warning('EeeVisionService Together error', ['status' => $response->status(), 'body' => substr($response->body(), 0, 300)]);
                return null;
            }

            $inputTokens  = $response->json('usage.prompt_tokens', 0);
            $outputTokens = $response->json('usage.completion_tokens', 0);

            return [
                'text'          => $response->json('choices.0.message.content'),
                'input_tokens'  => $inputTokens,
                'output_tokens' => $outputTokens,
                'cost_usd'      => $this->estimateCost('together', $inputTokens, $outputTokens),
            ];
        } catch (\Exception $e) {
            Log::error('EeeVisionService Together exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    protected function callOllama(string $imageUrl, string $system, string $userText): ?array
    {
        $imageData = $this->fetchImageBase64($imageUrl);
        if (!$imageData) return null;

        $payload = [
            'model'   => $this->getModelName(),
            'stream'  => false,
            'system'  => $system,
            'prompt'  => $userText,
            'images'  => [$imageData['base64']],
            'format'  => 'json',
            'options' => ['temperature' => 0.1],
        ];

        try {
            $response = Http::timeout(120)
                ->post(config('freegle.eee.ollama_base_url') . '/api/generate', $payload);

            if (!$response->successful()) {
                Log::warning('EeeVisionService Ollama error', ['status' => $response->status(), 'body' => substr($response->body(), 0, 300)]);
                return null;
            }

            return ['text' => $response->json('response'), 'input_tokens' => 0, 'output_tokens' => 0, 'cost_usd' => 0.0];
        } catch (\Exception $e) {
            Log::error('EeeVisionService Ollama exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    protected function callClaudeBridge(string $imageUrl, string $system, string $userText): ?array
    {
        $bridgeDir = rtrim(config('freegle.eee.bridge_path'), '/');
        $jobId     = uniqid('job_', true);

        $pendingFile   = "{$bridgeDir}/pending/{$jobId}.json";
        $doneFile      = "{$bridgeDir}/done/{$jobId}.json";
        $errorFile     = "{$bridgeDir}/errors/{$jobId}.json";

        file_put_contents($pendingFile, json_encode([
            'job_id'         => $jobId,
            'image_url'      => $imageUrl,
            'system'         => $system,
            'user_text'      => $userText,
            'prompt_version' => self::PROMPT_VERSION,
            'created_at'     => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT));

        $timeout = config('freegle.eee.bridge_timeout_seconds', 300);
        $deadline = time() + $timeout;

        while (time() < $deadline) {
            if (file_exists($doneFile)) {
                $result = json_decode(file_get_contents($doneFile), true);
                unlink($doneFile);
                return $result;
            }
            if (file_exists($errorFile)) {
                Log::warning('EeeVisionService bridge error', ['job_id' => $jobId]);
                unlink($errorFile);
                return null;
            }
            sleep(2);
        }

        // Timed out — move job to errors so bridge doesn't keep trying.
        if (file_exists($pendingFile)) {
            rename($pendingFile, $errorFile);
        }
        Log::warning('EeeVisionService bridge timeout', ['job_id' => $jobId, 'timeout' => $timeout]);
        return null;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    protected function fetchImageBase64(string $url): ?array
    {
        try {
            $response = Http::timeout(30)->get($url);
            if (!$response->successful()) {
                Log::warning('EeeVisionService image fetch failed', ['url' => $url, 'status' => $response->status()]);
                return null;
            }
            $contentType = $response->header('Content-Type') ?? 'image/jpeg';
            // Normalise to just mime type without params.
            $mimeType = explode(';', $contentType)[0];
            return ['base64' => base64_encode($response->body()), 'mime_type' => trim($mimeType)];
        } catch (\Exception $e) {
            Log::error('EeeVisionService image fetch exception', ['url' => $url, 'error' => $e->getMessage()]);
            return null;
        }
    }

    protected function parseAndAnnotate(array $raw): ?array
    {
        $text = trim($raw['text'] ?? '');

        // Strip markdown fences.
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $text, $m)) {
            $text = $m[1];
        }

        // Find outermost { }.
        $start = strpos($text, '{');
        $end   = strrpos($text, '}');
        if ($start === false || $end === false) {
            Log::warning('EeeVisionService: no JSON in response', ['driver' => $this->driver, 'text' => substr($text, 0, 200)]);
            return null;
        }
        $text = substr($text, $start, $end - $start + 1);

        try {
            $data = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            Log::warning('EeeVisionService: JSON parse failed', ['driver' => $this->driver, 'error' => $e->getMessage()]);
            return null;
        }

        $data['_meta'] = [
            'driver'        => $this->driver,
            'model'         => $this->getModelName(),
            'input_tokens'  => $raw['input_tokens'],
            'output_tokens' => $raw['output_tokens'],
            'cost_usd'      => $raw['cost_usd'],
            'raw_response'  => $text,
        ];

        return $data;
    }

    protected function estimateCost(string $driver, int $inputTokens, int $outputTokens): float
    {
        // Approximate costs per million tokens (2025 pricing).
        $pricing = [
            'claude'   => ['in' => 3.00,  'out' => 15.00],
            'gemini'   => ['in' => 0.075, 'out' => 0.30],
            'openai'   => ['in' => 2.50,  'out' => 10.00],
            'together' => ['in' => 1.20,  'out' => 1.20],
            'ollama'   => ['in' => 0.0,   'out' => 0.0],
        ];
        $p = $pricing[$driver] ?? ['in' => 0, 'out' => 0];
        return ($inputTokens * $p['in'] + $outputTokens * $p['out']) / 1_000_000;
    }

    public static function buildImageUrl(string $externaluid): string
    {
        return "https://ucarecdn.com/{$externaluid}/-/preview/768x768/-/format/jpeg/";
    }
}
