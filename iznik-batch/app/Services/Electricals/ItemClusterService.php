<?php

namespace App\Services\Electricals;

use App\Services\Desirability\TitleCanonicalService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Groups item names into the item types a reader would recognise.
 *
 * The catalogue stores what the member typed, so "Beko Fridge Freezer", "Bosch
 * fridge freezer" and "Fridge/Freezer" are three separate rows. Counting those
 * rows makes one common item look like three rare ones and hides its real
 * total: on live there are 5,180 distinct names behind 7,065 electrical posts.
 * Grouping is by canonical title (TitleCanonicalService - brand stripped,
 * synonyms folded, de-pluralised), which is deterministic and cannot invent a
 * merge between two different things.
 *
 * Embedding similarity is deliberately NOT used to merge counts. Measured on
 * live titles it scores "fridge freezer"/"freezer" at 0.93 and "cd player"/"dvd
 * player" at 0.85, both of which are wrong merges, ABOVE "coffee machine"/
 * "tassimo coffee machine" at 0.78, which is a right one. No threshold
 * separates those, so no published count may depend on one. Embeddings are used
 * only by suppressVariantsOfPopular(), and only at near-identity, where they
 * are reliable.
 */
class ItemClusterService
{
    /** @var array<string, array{canonical:string, brand:?string}> */
    private array $canonicalCache = [];

    public function __construct(protected TitleCanonicalService $canonical) {}

    /**
     * Fold rows into one entry per item type.
     *
     * Each row is one (post, group) pairing, so a post that rippled to three
     * groups arrives three times; counts are taken over distinct ids rather
     * than by summing, because the same member and the same group recur across
     * the names being merged and summing would over-count both.
     *
     * @param  iterable<object>  $rows  each with ->name, ->msgid, ->fromuser, ->groupid
     * @return array<string, array{canonical:string, name:string, count:int, users:int, groups:int}>
     */
    public function cluster(iterable $rows): array
    {
        $acc = [];

        foreach ($rows as $row) {
            $name = (string) $row->name;

            if ($name === '') {
                continue;
            }

            $c = $this->canonicaliseCached($name);
            $key = $c['canonical'];

            if (! isset($acc[$key])) {
                $acc[$key] = ['msgids' => [], 'users' => [], 'groups' => [], 'names' => []];
            }

            $acc[$key]['msgids'][(int) $row->msgid] = true;
            $acc[$key]['users'][(int) $row->fromuser] = true;
            $acc[$key]['groups'][(int) $row->groupid] = true;
            $acc[$key]['names'][$name][(int) $row->msgid] = true;
        }

        $out = [];

        foreach ($acc as $key => $a) {
            $out[$key] = [
                'canonical' => $key,
                'name'      => $this->representative($a['names']),
                'count'     => count($a['msgids']),
                'users'     => count($a['users']),
                'groups'    => count($a['groups']),
            ];
        }

        return $out;
    }

    /**
     * Drop qualified variants of common items from a rare-items list.
     *
     * "Table lamp" is not an unusual thing to be given when "lamp" is among the
     * most offered items on the site; it is the same item with a word in front.
     * Either of two independent tests suppresses an entry:
     *
     *  - every word of a much more popular item's name appears in this one
     *    ("wall lamp" contains "lamp"), which states the qualified-variant
     *    relation exactly rather than approximating it;
     *  - the two names embed as near-identical, which catches the re-phrasings
     *    words cannot see: "Breadmaker"/"bread maker" at 0.93, "Lightbulbs"/
     *    "light bulb" at 0.91.
     *
     * The near-identity bar is high on purpose. Below it cosine stops measuring
     * "same item, extra word" and starts measuring "related thing": on live
     * titles "sewing machine"/"washing machine" scores 0.82 and "rice cooker"/
     * "slow cooker" 0.79, and vetoing on those would hide genuinely unusual
     * items. Measured over the live window this suppresses 46 entries and gets
     * every one of them right.
     *
     * Suppression only ever removes a row from a short curiosity list, so a
     * wrong call costs a reader nothing and can never publish a wrong number.
     * When the sidecar is unavailable the word test still runs.
     *
     * @param  array<string, array>  $candidates  clusters that passed the rarity guard
     * @param  array<string, array>  $all         every cluster, to find the popular ones
     * @return array<string, array>
     */
    public function suppressVariantsOfPopular(array $candidates, array $all): array
    {
        $ratio    = (float) config('freegle.electricals.variant_popularity_ratio', 3);
        $minCount = (int) config('freegle.electricals.variant_min_popular_count', 10);

        $popular = array_values(array_filter($all, fn($c) => $c['count'] >= $minCount));

        if (! $popular || ! $candidates) {
            return $candidates;
        }

        $kept = [];
        $needEmbedding = [];

        foreach ($candidates as $key => $cand) {
            $rivals = array_values(array_filter(
                $popular,
                fn($p) => $p['canonical'] !== $key && $p['count'] >= $cand['count'] * $ratio
            ));

            if (! $rivals) {
                $kept[$key] = $cand;
                continue;
            }

            if ($this->containsAnyOf($key, $rivals)) {
                continue;
            }

            $needEmbedding[$key] = $rivals;
            $kept[$key] = $cand;
        }

        return $this->suppressNearIdentical($kept, $needEmbedding);
    }

    /** True when some rival's every word appears in $key, and $key says more. */
    private function containsAnyOf(string $key, array $rivals): bool
    {
        $words = $this->words($key);

        foreach ($rivals as $rival) {
            $rw = $this->words($rival['canonical']);

            if (count($rw) < count($words) && ! array_diff($rw, $words)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Second pass: drop entries whose embedding is near-identical to a rival's.
     *
     * One sidecar call for the whole page - the candidates that survived the
     * word test plus their rivals, a few hundred short strings.
     */
    private function suppressNearIdentical(array $kept, array $needEmbedding): array
    {
        if (! $needEmbedding) {
            return $kept;
        }

        $threshold = (float) config('freegle.electricals.variant_identical_cos', 0.90);

        $texts = array_keys($needEmbedding);

        foreach ($needEmbedding as $rivals) {
            foreach ($rivals as $rival) {
                $texts[] = $rival['canonical'];
            }
        }

        $vectors = $this->embed(array_values(array_unique($texts)));

        if ($vectors === null) {
            return $kept;
        }

        foreach ($needEmbedding as $key => $rivals) {
            if (! isset($vectors[$key])) {
                continue;
            }

            foreach ($rivals as $rival) {
                $rv = $vectors[$rival['canonical']] ?? null;

                if ($rv !== null && $this->cosine($vectors[$key], $rv) >= $threshold) {
                    unset($kept[$key]);
                    break;
                }
            }
        }

        return $kept;
    }

    /**
     * The name to print for a cluster.
     *
     * Prefers a name the brand detector found no brand in, so a cluster of
     * "Bosch dishwasher" and "Dishwasher" prints the plain one even when the
     * branded spelling is commoner; among equals, the most used name wins.
     *
     * @param  array<string, array<int, true>>  $names  name => set of message ids
     */
    private function representative(array $names): string
    {
        $best = '';
        $bestRank = -1;

        foreach ($names as $name => $msgids) {
            $branded = $this->canonicaliseCached($name)['brand'] !== null;
            $rank = count($msgids) + ($branded ? 0 : 1000000);

            if ($rank > $bestRank) {
                $bestRank = $rank;
                $best = $name;
            }
        }

        return $best;
    }

    /** @return array{canonical:string, brand:?string} */
    private function canonicaliseCached(string $name): array
    {
        if (! isset($this->canonicalCache[$name])) {
            $result = $this->canonical->canonicalise($name);
            $canonical = $result['canonical'] ?? null;

            // A title the canonicaliser rejects outright still has to land
            // somewhere; folding case is enough to merge the common case of the
            // same name typed with different capitals.
            if ($canonical === null || $canonical === '') {
                $canonical = mb_strtolower(trim($name));
            }

            $this->canonicalCache[$name] = [
                'canonical' => $this->dropSizeAndPanelWords($canonical),
                'brand'     => $result['brand'] ?? null,
            ];
        }

        return $this->canonicalCache[$name];
    }

    /**
     * How big it is, and what the panel is made of, are not what it is.
     *
     * The shared canonicaliser strips the brand, so "Samsung TV" and "Television" both
     * arrive as "tv". What it leaves is the screen size and the display technology, and
     * those split one item across a dozen buckets: a year of live offers published "Tv"
     * as the commonest electrical at 73 while 287 posts in the same sample were
     * televisions, sitting under "21in tv", "smart tv 32in", "flat screen tv",
     * "50in plasma tv" and so on.
     *
     * Only words that leave the item itself unchanged are dropped, and never the last
     * word, so the head noun always survives: "washing machine" cannot become "machine".
     * A model name such as "bravia tv" is left alone, because telling a model from an
     * item needs a catalogue this does not have.
     */
    private const NEUTRAL_WORDS = [
        // Display technology.
        'plasma', 'lcd', 'led', 'oled', 'crt', 'smart', 'widescreen', 'flat', 'screen',
        // Size and form, where the thing is the same either way.
        'small', 'large', 'big', 'mini', 'compact', 'portable', 'slim', 'tabletop',
        // Colour, which is never the item.
        'colour', 'color', 'black', 'white', 'silver',
    ];

    private function dropSizeAndPanelWords(string $canonical): string
    {
        // "32in", "40 inch", "50\"" and the bare number left when a unit was already
        // stripped, e.g. "21in tv" -> "tv".
        $t = (string) preg_replace('~\b\d+\s*(?:in|ins|inch|inches|")\b~u', ' ', $canonical);

        $words = preg_split('~\s+~u', trim($t), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($words) < 2) {
            return trim($t) === '' ? $canonical : trim($t);
        }

        $last = array_pop($words);
        $kept = array_values(array_filter(
            $words,
            fn($w) => ! in_array($w, self::NEUTRAL_WORDS, true) && ! preg_match('~^\d+$~', $w)
        ));
        $kept[] = $last;

        return implode(' ', $kept);
    }

    /** @return string[] */
    private function words(string $text): array
    {
        return array_values(array_unique(array_filter(
            preg_split('/[^a-z0-9]+/', mb_strtolower($text))
        )));
    }

    /**
     * @param  string[]  $texts
     * @return array<string, float[]>|null  null when the sidecar cannot answer
     */
    private function embed(array $texts): ?array
    {
        $url = rtrim((string) config('freegle.electricals.sidecar_url', ''), '/');

        if ($url === '') {
            return null;
        }

        $out = [];

        foreach (array_chunk($texts, 256) as $chunk) {
            try {
                $response = Http::timeout(30)->post($url.'/embed', ['texts' => array_values($chunk)]);

                if (! $response->successful()) {
                    Log::warning('Electricals: embedding sidecar returned non-OK', [
                        'status' => $response->status(),
                    ]);

                    return null;
                }

                $embeddings = $response->json('embeddings');

                if (! is_array($embeddings) || count($embeddings) !== count($chunk)) {
                    return null;
                }

                foreach (array_values($chunk) as $i => $text) {
                    if (is_array($embeddings[$i])) {
                        $out[$text] = array_map('floatval', $embeddings[$i]);
                    }
                }
            } catch (\Exception $e) {
                Log::warning('Electricals: embedding sidecar unavailable', ['error' => $e->getMessage()]);

                return null;
            }
        }

        return $out;
    }

    /** Both vectors come from the same sidecar call, so they are already unit length. */
    private function cosine(array $a, array $b): float
    {
        if (count($a) !== count($b)) {
            return 0.0;
        }

        $dot = 0.0;

        foreach ($a as $i => $v) {
            $dot += $v * $b[$i];
        }

        return $dot;
    }
}
