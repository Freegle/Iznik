<?php

namespace App\Services\Concierge;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Turns a raw inbound reply into the structured signal the ConciergeEngine
 * consumes: which catalogue items the sender wants, whether they're declining,
 * and any collection dates. This is the second "AI slot" — the LlmExtractor is
 * the real one; the RuleBasedExtractor is a deterministic fallback and the thing
 * the replay test pins, so the pipeline is testable without a network call.
 *
 * @phpstan-type Extraction array{wants:int[],declined:bool,collectDays:string[]}
 */
interface Extractor
{
    /**
     * @param array<int,array{num:int,name:string}> $items catalogue keyed by number
     * @param array{defaultMonth?:string} $opts  e.g. defaultMonth '2026-07' for bare ordinals
     * @return array{wants:int[],declined:bool,collectDays:string[]}
     */
    public function extract(string $body, string $subject, array $items, array $opts = []): array;
}

/**
 * Deterministic keyword/regex extractor. No AI, no network. Handles the common
 * cases (explicit "#N", named items with specificity so "round meeting table"
 * isn't also counted as "meeting table"/"table", decline phrases, July dates).
 * Good enough as a safe fallback; the LLM does the fuzzy cases better.
 */
class RuleBasedExtractor implements Extractor
{
    public function extract(string $body, string $subject, array $items, array $opts = []): array
    {
        $text = ' ' . strtolower($body) . ' ';
        $wants = [];

        // 1) explicit "#N"
        if (preg_match_all('/#\s*(\d{1,2})/', $text, $m)) {
            foreach ($m[1] as $n) {
                $n = (int) $n;
                if (isset($items[$n])) $wants[$n] = true;
            }
        }

        // 2) named items — longest/most-specific first, consuming the matched span
        //    so a phrase isn't double-counted by a shorter rule.
        $rules = [
            [5, ['round meeting table', 'round table']],
            [2, ['filing cabinet']],
            [6, ['desk return cabinet', 'desk-return', 'desk return']],
            [4, ['bisley']],
            [3, ['corner desk']],
            [8, ['green armchair', 'armchair']],
            [1, ['stacking chair', 'sticking chair', 'wooden chair']],
            [9, ['cupboard']],
            [7, ['meeting table']],   // only what's left after #5 consumed
            [10, ['table']],          // bare "table", after the others
            [1, ['chairs', 'chair']], // bare "chairs" → stacking, last resort
        ];
        foreach ($rules as [$num, $kws]) {
            foreach ($kws as $kw) {
                if (str_contains($text, $kw)) {
                    if (isset($items[$num])) $wants[$num] = true;
                    $text = str_replace($kw, ' ', $text); // consume so shorter rules don't re-match
                }
            }
        }

        ksort($wants);

        return [
            'wants' => array_keys($wants),
            'declined' => (bool) preg_match('/\b(already sorted|no longer (need|require)|don.?t need|not (required|needed)|we have (new|enough)|discontinued|no,? thank you|kindly decline)\b/i', $body),
            'collectDays' => $this->extractDays($body, $opts['defaultMonth'] ?? null),
        ];
    }

    /** Best-effort UK-date parse → ISO strings (assumes 2026). */
    private function extractDays(string $body, ?string $defaultMonth): array
    {
        $mn = ['january' => '01', 'february' => '02', 'march' => '03', 'april' => '04', 'may' => '05', 'june' => '06',
            'july' => '07', 'august' => '08', 'september' => '09', 'october' => '10', 'november' => '11', 'december' => '12'];
        $days = [];
        if (preg_match_all('/(\d{1,2})(?:st|nd|rd|th)?\s+(january|february|march|april|may|june|july|august|september|october|november|december)/i', $body, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $x) {
                $d = str_pad($x[1], 2, '0', STR_PAD_LEFT);
                $days[] = '2026-' . $mn[strtolower($x[2])] . "-$d";
            }
        }
        if ($defaultMonth && preg_match_all('/\b(\d{1,2})(?:st|nd|rd|th)\b/i', $body, $mm)) {
            foreach ($mm[1] as $d) {
                if ((int) $d >= 1 && (int) $d <= 31) $days[] = "$defaultMonth-" . str_pad($d, 2, '0', STR_PAD_LEFT);
            }
        }
        $days = array_values(array_unique($days));
        sort($days);

        return $days;
    }
}

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
