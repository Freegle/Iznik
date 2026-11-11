<?php

namespace App\Services\Concierge;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
