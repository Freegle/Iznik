<?php

namespace App\Services\Concierge;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * LLM-backed extractor. Follows the app's existing multi-driver pattern
 * (see EeeVisionService): driver + model from config, JSON output. Falls back to
 * the rule-based extractor if the LLM is unavailable or returns unusable output,
 * so the pipeline degrades safely rather than dropping a reply.
 */
class LlmExtractor implements Extractor
{
    public function __construct(private ?Extractor $fallback = null)
    {
        $this->fallback ??= new RuleBasedExtractor();
    }

    public function extract(string $body, string $subject, array $items, array $opts = []): array
    {
        $driver = config('freegle.concierge.extractor_driver', config('freegle.eee.model', 'ollama'));
        $catalogue = implode("\n", array_map(fn ($it) => "{$it['num']}. {$it['name']}", $items));
        $system = "You extract structured interest from a reply to a free-furniture offer. "
            . "Given the numbered item list and the sender's email, return STRICT JSON only:\n"
            . '{"wants":[item numbers they ask for],"declined":true|false,"collectDays":["YYYY-MM-DD"]}'
            . "\nUse ONLY item numbers from the list. declined=true if they say they don't need anything. "
            . "collectDays = any collection dates they propose (year 2026 if unstated). No prose.";
        $user = "ITEMS:\n$catalogue\n\nEMAIL SUBJECT: $subject\n\nEMAIL BODY:\n$body";

        try {
            $json = $this->callLlm($driver, $system, $user);
            if ($json !== null) {
                $wants = array_values(array_filter(array_map('intval', $json['wants'] ?? []), fn ($n) => isset($items[$n])));
                sort($wants);

                return [
                    'wants' => $wants,
                    'declined' => (bool) ($json['declined'] ?? false),
                    'collectDays' => array_values(array_filter((array) ($json['collectDays'] ?? []), 'is_string')),
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('Concierge LlmExtractor failed, using rule-based fallback', ['error' => $e->getMessage()]);
        }

        return $this->fallback->extract($body, $subject, $items, $opts);
    }

    /** @return array<string,mixed>|null decoded JSON, or null to trigger fallback */
    private function callLlm(string $driver, string $system, string $user): ?array
    {
        if ($driver === 'claude') {
            $resp = Http::timeout(45)->withHeaders([
                'x-api-key' => config('freegle.eee.anthropic_api_key'),
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->post('https://api.anthropic.com/v1/messages', [
                'model' => config('freegle.eee.claude_model', 'claude-sonnet-4-6'),
                'max_tokens' => 400, 'system' => $system,
                'messages' => [['role' => 'user', 'content' => $user]],
            ]);

            return $resp->successful() ? $this->firstJson($resp->json('content.0.text', '')) : null;
        }

        // default: Ollama (local), JSON mode
        $base = rtrim((string) config('freegle.concierge.ollama_url', config('freegle.eee.ollama_url', 'http://host.docker.internal:11434')), '/');
        $model = config('freegle.concierge.ollama_model', config('freegle.eee.ollama_model', 'qwen3:14b'));
        $resp = Http::timeout(60)->post("$base/api/generate", [
            'model' => $model, 'system' => $system, 'prompt' => $user,
            'stream' => false, 'format' => 'json', 'options' => ['temperature' => 0.1],
        ]);

        return $resp->successful() ? $this->firstJson((string) $resp->json('response', '')) : null;
    }

    /** Pull the first JSON object out of a model's text response. */
    private function firstJson(string $text): ?array
    {
        if (preg_match('/\{.*\}/s', $text, $m)) {
            $d = json_decode($m[0], true);

            return is_array($d) ? $d : null;
        }

        return null;
    }
}
