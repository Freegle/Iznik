<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Ask Gemini which single item a post's wording means, in 1-3 words: "Aluminium walking frame"
 * is a "walking frame", "PUCHING WELCHIAR" a "wheelchair", "Old tins of buttons" "buttons".
 * Pictures are drawn from, and shared by, that item, so the library converges on a vocabulary
 * of items instead of growing by one-off wordings.
 *
 * The prompt and model are the ones the reuse rate and the judged quality were measured with:
 * docs/developers/reference/ai-item-images.md. Change either and re-measure.
 */
class ItemCleanerService
{
    /** Names per request. 100 was measured to come back complete and in order. */
    public const BATCH = 100;

    public const NONE = 'none';

    private const PROMPT = <<<'PROMPT'
These are titles of posts on Freegle, a UK site where people give away and ask for unwanted things.
Each post gets a simple drawing of its item, shared by every post for the same item.
For each title, say which single physical item the drawing should show, in 1 to 3 words:
- a plain, singular, lower-case noun phrase in British English, the way a shop would label it ("walking frame", "car seat", "pressure cooker", "seeds");
- keep every word that makes it a different object from the bare noun: "dog bed" not "bed", "guitar stand" not "stand", "lamp shade", "fire blanket", "weights bench", "satellite box", "pill box", "carpet tiles", "electric guitar", "sofa bed";
- drop brand, colour, size, quantity, condition, material and who it is for ("Ebac dehumidifier" -> "dehumidifier");
- name what is wanted or given, not its container ("old tins of buttons" -> "buttons", "boxes of books" -> "books");
- keep what a part belongs to when the part alone would be drawn as something else ("xbox hand brake" -> "gaming hand brake", "car seat", "bike pump");
- correct spelling ("puching welchiar" -> "wheelchair");
- if several items are listed, give the first main one;
- if there is no drawable item (a question, a service, a location, nonsense), give "none".
Answer with only a JSON array of strings, one per title, in the same order.

Titles:
PROMPT;

    public function isConfigured(): bool
    {
        return config('freegle.ai_images.gemini_api_key', '') !== '';
    }

    /**
     * @param  list<string>  $names
     * @return array<string,string> name => item, or NONE. A name whose request failed is absent,
     *                              so the caller treats it as it always did.
     */
    public function clean(array $names): array
    {
        $names = array_values(array_unique(array_filter($names, fn ($n) => trim($n) !== '')));
        if (! $names || ! $this->isConfigured()) {
            return [];
        }

        $out = [];
        foreach (array_chunk($names, self::BATCH) as $chunk) {
            $items = $this->ask($chunk);
            if ($items === null) {
                continue;
            }
            foreach ($chunk as $i => $name) {
                $item = trim(mb_strtolower((string) $items[$i]));
                if ($item !== '' && mb_strlen($item) <= 100) {
                    $out[$name] = $item;
                }
            }
        }

        return $out;
    }

    /** @return list<string>|null one answer per name, or null when the call or its answer failed */
    private function ask(array $names): ?array
    {
        $lines = [];
        foreach ($names as $i => $name) {
            $lines[] = ($i + 1).'. '.str_replace(["\r", "\n"], ' ', $name);
        }
        $model = config('freegle.ai_images.clean_model');

        try {
            $response = Http::withHeaders(['x-goog-api-key' => config('freegle.ai_images.gemini_api_key')])
                ->timeout(60)
                ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent", [
                    'contents' => [['parts' => [['text' => self::PROMPT."\n".implode("\n", $lines)]]]],
                    'generationConfig' => ['temperature' => 0, 'responseMimeType' => 'application/json'],
                ]);
        } catch (\Throwable $e) {
            Log::warning('ItemCleaner: request failed', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('ItemCleaner: request refused', ['status' => $response->status()]);

            return null;
        }

        $text = collect($response->json('candidates.0.content.parts', []))->pluck('text')->implode('');
        $items = json_decode($text, true);
        if (! is_array($items) || count($items) !== count($names)) {
            // Out of step with the names, so no answer can be trusted to belong to its name.
            Log::warning('ItemCleaner: answer did not match the names', ['names' => count($names), 'answers' => is_array($items) ? count($items) : null]);

            return null;
        }

        return array_values($items);
    }
}
