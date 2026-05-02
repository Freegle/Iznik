<?php

namespace App\Services;

use Illuminate\Http\Client\Pool;
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
    public const PROMPT_VERSION = '1.3.0';

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

    /**
     * Classify multiple images concurrently. Returns array of results (nulls for failures),
     * in the same order as $jobs. Each job: ['imageUrl' => string, 'context' => array].
     * Falls back to sequential for drivers that don't support pooling.
     */
    public function analyseMany(array $jobs): array
    {
        return match ($this->driver) {
            'claude' => $this->analyseManyClaude($jobs),
            'gemini' => $this->analyseManyGemini($jobs),
            'openai' => $this->analyseManyOpenAI($jobs),
            default  => array_map(fn($job) => $this->analyse($job['imageUrl'], $job['context']), $jobs),
        };
    }

    /** Fetch all images concurrently; returns array of base64 data (null on failure), same order as $jobs. */
    protected function fetchImagesMany(array $jobs): array
    {
        $responses = Http::pool(function (Pool $pool) use ($jobs) {
            return array_map(fn($job) => $pool->timeout(30)->get($job['imageUrl']), $jobs);
        });

        return array_map(function ($response) {
            if ($response instanceof \Throwable || !$response->successful()) return null;
            $mime = trim(explode(';', $response->header('Content-Type') ?? '')[0]);
            if (!str_starts_with($mime, 'image/')) return null;
            return ['base64' => base64_encode($response->body()), 'mime_type' => $mime];
        }, $responses);
    }

    /**
     * Pool API calls for jobs whose images fetched successfully.
     * $buildPayload(job, imageData) → payload array.
     * $parseResponse(response) → raw array or null.
     * Returns results in original job order.
     */
    protected function poolApiCalls(array $jobs, array $imageData, callable $parseResponse, callable $buildRequest): array
    {
        $apiRequests = [];
        $indexMap    = [];
        foreach ($imageData as $i => $img) {
            if ($img === null) continue;
            $indexMap[]    = $i;
            $apiRequests[] = $img;
        }

        if (empty($apiRequests)) {
            return array_fill(0, count($jobs), null);
        }

        $apiResponses = Http::pool(function (Pool $pool) use ($apiRequests, $jobs, $indexMap, $buildRequest) {
            return array_map(function ($img, $k) use ($pool, $jobs, $indexMap, $buildRequest) {
                return $buildRequest($pool, $jobs[$indexMap[$k]], $img);
            }, $apiRequests, array_keys($apiRequests));
        });

        $results = array_fill(0, count($jobs), null);
        foreach ($apiResponses as $k => $response) {
            $results[$indexMap[$k]] = $parseResponse($response);
        }
        return $results;
    }

    protected function analyseManyClaude(array $jobs): array
    {
        $system  = $this->buildSystemPrompt();
        $headers = [
            'x-api-key'         => config('freegle.eee.anthropic_api_key'),
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ];

        return $this->poolApiCalls(
            $jobs,
            $this->fetchImagesMany($jobs),
            function ($response) {
                if ($response instanceof \Throwable) {
                    Log::error('EeeVisionService Claude pool exception', ['error' => $response->getMessage()]);
                    return null;
                }
                if (!$response->successful()) {
                    Log::warning('EeeVisionService Claude pool error', ['status' => $response->status(), 'body' => substr($response->body(), 0, 300)]);
                    return null;
                }
                return $this->parseAndAnnotate([
                    'text'          => $response->json('content.0.text'),
                    'input_tokens'  => $response->json('usage.input_tokens', 0),
                    'output_tokens' => $response->json('usage.output_tokens', 0),
                    'cost_usd'      => $this->estimateCost('claude', $response->json('usage.input_tokens', 0), $response->json('usage.output_tokens', 0)),
                ]);
            },
            function ($pool, $job, $img) use ($system, $headers) {
                $payload = [
                    'model' => $this->getModelName(), 'max_tokens' => 1500, 'system' => $system,
                    'messages' => [['role' => 'user', 'content' => [
                        ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $img['mime_type'], 'data' => $img['base64']]],
                        ['type' => 'text', 'text' => $this->buildUserText($job['context'])],
                    ]]],
                ];
                return $pool->withHeaders($headers)->timeout(90)->post('https://api.anthropic.com/v1/messages', $payload);
            }
        );
    }

    protected function analyseManyGemini(array $jobs): array
    {
        $system = $this->buildSystemPrompt();
        $model  = $this->getModelName();
        $apiKey = config('freegle.eee.gemini_api_key');
        $url    = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        return $this->poolApiCalls(
            $jobs,
            $this->fetchImagesMany($jobs),
            function ($response) {
                if ($response instanceof \Throwable) {
                    Log::error('EeeVisionService Gemini pool exception', ['error' => $response->getMessage()]);
                    return null;
                }
                if (!$response->successful()) {
                    Log::warning('EeeVisionService Gemini pool error', ['status' => $response->status(), 'body' => substr($response->body(), 0, 300)]);
                    return null;
                }
                $in  = $response->json('usageMetadata.promptTokenCount', 0);
                $out = $response->json('usageMetadata.candidatesTokenCount', 0);
                return $this->parseAndAnnotate([
                    'text'          => $response->json('candidates.0.content.parts.0.text'),
                    'input_tokens'  => $in,
                    'output_tokens' => $out,
                    'cost_usd'      => $this->estimateCost('gemini', $in, $out),
                ]);
            },
            function ($pool, $job, $img) use ($system, $url) {
                $payload = [
                    'system_instruction' => ['parts' => [['text' => $system]]],
                    'contents'           => [['parts' => [
                        ['text' => $this->buildUserText($job['context'])],
                        ['inline_data' => ['mime_type' => $img['mime_type'], 'data' => $img['base64']]],
                    ]]],
                    'generationConfig'   => ['response_mime_type' => 'application/json', 'temperature' => 0.1],
                ];
                return $pool->timeout(90)->post($url, $payload);
            }
        );
    }

    protected function analyseManyOpenAI(array $jobs): array
    {
        $system = $this->buildSystemPrompt();
        $token  = config('freegle.eee.openai_api_key');

        // OpenAI can fetch image URLs directly, so no base64 pre-fetch needed.
        $fakeImageData = array_fill(0, count($jobs), ['placeholder' => true]);

        return $this->poolApiCalls(
            $jobs,
            $fakeImageData,
            function ($response) {
                if ($response instanceof \Throwable) {
                    Log::error('EeeVisionService OpenAI pool exception', ['error' => $response->getMessage()]);
                    return null;
                }
                if (!$response->successful()) {
                    Log::warning('EeeVisionService OpenAI pool error', ['status' => $response->status(), 'body' => substr($response->body(), 0, 300)]);
                    return null;
                }
                $in  = $response->json('usage.prompt_tokens', 0);
                $out = $response->json('usage.completion_tokens', 0);
                return $this->parseAndAnnotate([
                    'text'          => $response->json('choices.0.message.content'),
                    'input_tokens'  => $in,
                    'output_tokens' => $out,
                    'cost_usd'      => $this->estimateCost('openai', $in, $out),
                ]);
            },
            function ($pool, $job, $_img) use ($system, $token) {
                $payload = [
                    'model' => $this->getModelName(), 'max_tokens' => 1500, 'temperature' => 0.1,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => [
                            ['type' => 'text', 'text' => $this->buildUserText($job['context'])],
                            ['type' => 'image_url', 'image_url' => ['url' => $job['imageUrl'], 'detail' => 'low']],
                        ]],
                    ],
                ];
                return $pool->withToken($token)->timeout(90)->post('https://api.openai.com/v1/chat/completions', $payload);
            }
        );
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
You are examining a photo of a second-hand item being given away on Freegle (UK free reuse platform).

PART 1 — PHOTO QUALITY:
Rate the photo. photo_quality: 1–5 (5=sharp and clear, 1=blurry/dark/item obscured). photo_quality_notes: brief note on issues, or null.

PART 2 — ELECTRICAL COMPONENTS (image only):
List every component you can directly observe in the photo that uses mains power, battery power, USB, solar, or any other external electrical supply. Describe each one briefly in plain English (e.g. "digital display panel", "mains power flex", "LED strip lights", "rechargeable battery pack"). Only include components that are part of the item being offered — exclude items visible in the background that are clearly separate. Be inclusive: even a clock, indicator light, or built-in rechargeable counts. If you cannot see any such components, return an empty list.

PART 3 — TEXT SIGNALS (from title and description only):
From the item title and description, extract any words or phrases that explicitly name the item's primary power source. Examples: "gas cooker", "petrol engine", "electric", "battery-powered", "manual", "solar", "cordless", "wind-up", "no electricity needed".

PART 4 — IS_EEE FROM TEXT:
Based on text signals only (ignore the image for this part), does this item's PRIMARY FUNCTION require electricity? WEEE test: would the item be completely unable to perform its main purpose without electricity?
- true  = primary function requires electricity (electric cooker, laptop, cordless drill)
- false = primary function does not (gas cooker, petrol lawnmower, manual wheelchair, acoustic guitar)
- null  = text signals insufficient to decide

PART 5 — WEEE CATEGORY:
If the item's primary function requires electricity (is_eee_from_text true, or substantial electrical components clearly make it primarily electrical), assign a WEEE category:
{$categories}
Otherwise return null.

PART 6 — PHYSICAL ATTRIBUTES:
Extract all observable attributes.

Return ONLY valid JSON with no markdown or explanation:
{
  "photo_quality": 1-5,
  "photo_quality_notes": "notes or null",
  "electrical_components_observed": ["digital display panel", "mains power flex"],
  "text_power_signals": ["gas cooker"],
  "is_eee_from_text": true/false/null,
  "observation_notes": "brief note on anything ambiguous or notable, or null",
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
  "item_complete_notes": "e.g. missing lid or null",
  "accessories_visible": ["cable", "remote"] or [],
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
            'max_tokens' => 1500,
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
        // Llama NIM vision models reject a separate 'system' role when an image is present,
        // so we fold the system prompt into the user message.
        $payload = [
            'model'           => $this->getModelName(),
            'max_tokens'      => 2048,
            'temperature'     => 0.1,
            'response_format' => ['type' => 'json_object'],
            'messages'        => [
                ['role' => 'user', 'content' => [
                    ['type' => 'text', 'text' => $system . "\n\n" . $userText],
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
            $contentType = $response->header('Content-Type') ?? '';
            $mimeType = trim(explode(';', $contentType)[0]);
            if (!str_starts_with($mimeType, 'image/')) {
                Log::warning('EeeVisionService image fetch returned non-image content', ['url' => $url, 'content_type' => $contentType]);
                return null;
            }
            return ['base64' => base64_encode($response->body()), 'mime_type' => $mimeType];
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

        // Derive is_eee, contains_eee_components, and confidence from new structured fields.
        $components    = $data['electrical_components_observed'] ?? [];
        $isEeeFromText = $data['is_eee_from_text'] ?? null;
        $containsEee   = !empty($components);

        if ($isEeeFromText !== null) {
            $data['is_eee']            = $isEeeFromText;
            $data['is_eee_confidence'] = 0.90;
        } elseif (!$containsEee) {
            $data['is_eee']            = false;
            $data['is_eee_confidence'] = 0.70;
        } else {
            $data['is_eee']            = null;   // uncertain: has components but text didn't specify
            $data['is_eee_confidence'] = 0.50;
        }

        $data['contains_eee_components']          = $containsEee;
        $data['electrical_components_description'] = $containsEee ? implode('; ', $components) : null;
        $data['is_eee_reasoning']                  = $data['observation_notes'] ?? null;

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
        if (str_starts_with($externaluid, 'freegletusd-')) {
            // TUS-uploaded image: strip prefix, route via delivery proxy (wsrv.nl-compatible).
            $fileId = substr($externaluid, 12);
            return 'https://delivery.ilovefreegle.org?url=https://uploads.ilovefreegle.org:8080/' . $fileId . '&w=768&h=768&fit=inside&output=jpg';
        }
        // Legacy Uploadcare CDN UUID.
        return "https://ucarecdn.com/{$externaluid}/-/preview/768x768/-/format/jpeg/";
    }
}
