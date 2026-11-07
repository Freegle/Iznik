<?php

namespace App\Services\AutoSplit;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * One Gemini generateContent call that must answer JSON in a given schema, for the auto-splitter.
 * Retries a refusal that is the service's fault (429, 5xx, no connection) a few times with a
 * growing pause; anything else is answered at once with null, so the caller leaves the post for
 * the next run. The key goes in a header, never in the URL, so it cannot reach a log line.
 */
class GeminiJson
{
    private const URL = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    private const ATTEMPTS = 3;

    /** Tokens used by the last successful call: [in, out], out including thinking. */
    public array $lastUsage = [0, 0];

    /** Seconds slept between attempts, so a test can take the pause out. */
    public int $retryPauseSeconds = 2;

    public function isConfigured(): bool
    {
        return config('freegle.autosplit.gemini_api_key', '') !== '';
    }

    public function model(): string
    {
        return (string) config('freegle.autosplit.model');
    }

    /**
     * @param  list<array<string,mixed>>  $parts  Gemini content parts: ['text' => ...] or ['inline_data' => [...]]
     * @param  array<string,mixed>  $schema  OpenAPI-style response schema
     * @return array<string,mixed>|null the decoded answer, or null when there is none to trust
     */
    public function ask(array $parts, array $schema, int $timeout = 60): ?array
    {
        $this->lastUsage = [0, 0];
        if (! $this->isConfigured()) {
            return null;
        }

        $body = [
            'contents' => [['role' => 'user', 'parts' => $parts]],
            'generationConfig' => [
                'temperature' => 0,
                'responseMimeType' => 'application/json',
                'responseSchema' => $schema,
            ],
        ];

        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            try {
                $response = Http::withHeaders(['x-goog-api-key' => config('freegle.autosplit.gemini_api_key')])
                    ->timeout($timeout)
                    ->post(sprintf(self::URL, $this->model()), $body);
            } catch (\Throwable $e) {
                Log::warning('AutoSplit: Gemini request failed', ['error' => $e->getMessage(), 'attempt' => $attempt]);
                $this->pause($attempt);

                continue;
            }

            if ($response->status() === 429 || $response->serverError()) {
                Log::warning('AutoSplit: Gemini busy', ['status' => $response->status(), 'attempt' => $attempt]);
                $this->pause($attempt);

                continue;
            }

            if (! $response->successful()) {
                Log::warning('AutoSplit: Gemini refused the request', ['status' => $response->status()]);

                return null;
            }

            $text = collect($response->json('candidates.0.content.parts', []))->pluck('text')->implode('');
            $answer = json_decode($text, true);
            if (! is_array($answer)) {
                Log::warning('AutoSplit: Gemini answer was not JSON');

                return null;
            }

            $usage = $response->json('usageMetadata', []);
            $this->lastUsage = [
                (int) ($usage['promptTokenCount'] ?? 0),
                (int) ($usage['candidatesTokenCount'] ?? 0) + (int) ($usage['thoughtsTokenCount'] ?? 0),
            ];

            return $answer;
        }

        return null;
    }

    private function pause(int $attempt): void
    {
        if ($attempt < self::ATTEMPTS && $this->retryPauseSeconds > 0) {
            sleep($this->retryPauseSeconds * $attempt);
        }
    }
}
