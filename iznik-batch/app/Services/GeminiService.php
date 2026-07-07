<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service for interacting with Google Gemini API.
 *
 * Provides AI-powered text analysis for git summaries and release classification.
 */
class GeminiService
{
    /**
     * Gemini API base URL.
     */
    protected const API_BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';

    /**
     * API key for authentication.
     */
    protected string $apiKey;

    /**
     * Cached model name.
     */
    protected ?string $cachedModel = null;

    public function __construct()
    {
        $this->apiKey = config('freegle.git_summary.gemini_api_key', '');
    }

    /**
     * Check if the service is configured with an API key.
     */
    public function isConfigured(): bool
    {
        return !empty($this->apiKey);
    }

    /**
     * Get the best available Gemini Flash model dynamically.
     *
     * Queries the API to find the latest flash model for speed and cost efficiency.
     */
    public function getModel(): string
    {
        if ($this->cachedModel !== null) {
            return $this->cachedModel;
        }

        try {
            $response = Http::timeout(30)
                ->get(self::API_BASE_URL . '/models', [
                    'key' => $this->apiKey,
                ]);

            if (!$response->successful()) {
                Log::warning('GeminiService: Failed to list models', [
                    'status' => $response->status(),
                ]);
                $this->cachedModel = 'gemini-pro';
                return $this->cachedModel;
            }

            $models = $response->json('models', []);
            $flashModels = [];

            foreach ($models as $model) {
                $modelName = $model['name'] ?? '';
                $supportedMethods = $model['supportedGenerationMethods'] ?? [];

                // Look for flash models that support text generation.
                if (
                    stripos($modelName, 'flash') !== false &&
                    stripos($modelName, 'gemini') !== false &&
                    stripos($modelName, 'exp') === false && // Exclude experimental.
                    stripos($modelName, 'vision') === false && // Exclude vision-only.
                    in_array('generateContent', $supportedMethods)
                ) {
                    // Extract version number for sorting (e.g., 2.0, 1.5).
                    $version = 0;
                    if (preg_match('/gemini-(\d+)\.(\d+)-flash/i', $modelName, $matches)) {
                        $version = floatval($matches[1] . '.' . $matches[2]);
                    }

                    $flashModels[] = [
                        'name' => $modelName,
                        'display' => $model['displayName'] ?? $modelName,
                        'version' => $version,
                    ];
                }
            }

            if (empty($flashModels)) {
                Log::warning('GeminiService: No flash models found, using fallback');
                $this->cachedModel = 'gemini-pro';
                return $this->cachedModel;
            }

            // Sort by version descending to get the latest.
            usort($flashModels, fn($a, $b) => $b['version'] <=> $a['version']);

            $selectedModel = $flashModels[0];

            // Remove 'models/' prefix if present.
            $modelName = $selectedModel['name'];
            if (str_starts_with($modelName, 'models/')) {
                $modelName = substr($modelName, 7);
            }

            Log::info('GeminiService: Using model', [
                'model' => $modelName,
                'display' => $selectedModel['display'],
            ]);

            $this->cachedModel = $modelName;
            return $this->cachedModel;

        } catch (\Exception $e) {
            Log::error('GeminiService: Error listing models', [
                'error' => $e->getMessage(),
            ]);
            $this->cachedModel = 'gemini-pro';
            return $this->cachedModel;
        }
    }

    /**
     * Generate content using the Gemini API.
     *
     * @param string $prompt The prompt to send to the model.
     * @return string|null The generated text, or null on failure.
     */
    public function generateContent(string $prompt): ?string
    {
        if (!$this->isConfigured()) {
            Log::error('GeminiService: API key not configured');
            return null;
        }

        try {
            $model = $this->getModel();
            $url = self::API_BASE_URL . '/models/' . $model . ':generateContent?key=' . $this->apiKey;

            $response = Http::timeout(120)
                ->post($url, [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => $prompt],
                            ],
                        ],
                    ],
                ]);

            if (!$response->successful()) {
                Log::error('GeminiService: API request failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            $data = $response->json();
            $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

            if (empty($text)) {
                Log::warning('GeminiService: Empty response from API');
                return null;
            }

            return $text;

        } catch (\Exception $e) {
            Log::error('GeminiService: Error generating content', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Generate content and parse as JSON.
     *
     * @param string $prompt The prompt to send to the model.
     * @return array|null The parsed JSON, or null on failure.
     */
    public function generateJson(string $prompt): ?array
    {
        $text = $this->generateContent($prompt);

        if ($text === null) {
            return null;
        }

        // Extract JSON from the response (may be wrapped in markdown code block).
        if (preg_match('/```json\s*(.*?)\s*```/s', $text, $matches)) {
            $text = $matches[1];
        } elseif (preg_match('/```\s*(.*?)\s*```/s', $text, $matches)) {
            $text = $matches[1];
        }

        try {
            $json = json_decode(trim($text), true, 512, JSON_THROW_ON_ERROR);
            return $json;
        } catch (\JsonException $e) {
            Log::warning('GeminiService: Failed to parse JSON response', [
                'error' => $e->getMessage(),
                'text' => substr($text, 0, 500),
            ]);
            return null;
        }
    }
}
