<?php

namespace App\Services\Desirability;

/**
 * Canonicalises an OFFER subject into the item-type key used by the desirability
 * model. This is a line-for-line port of the analysis pipeline (which itself
 * extends predesire::clean_title / canonicalise_title / detect_brand from
 * Clement Lee's desirability research) — the artifact table's keys were produced
 * by the same logic, so any behaviour change here must be paired with a rebuild
 * of the artifact. Parity is pinned by tests/fixtures/desirability/golden-titles.json.
 *
 * Lookup tables in resources/desirability/ come from the predesire R package
 * (brands, synonyms, electrical_digital, places) plus wordfreq.json, the corpus
 * word frequencies that stand in for a spelling dictionary when un-pluralising
 * a trailing word (corpus beats hunspell here: it knows "mould" and "bunkbed").
 */
class TitleCanonicalService
{
    private const PROTECT_OPEN = "\x01";

    private const PROTECT_CLOSE = "\x02";

    /** @var array<int, array{alias: string, canonical: string, is_plural: bool, quantity_only: bool, re: string, reEnd: string}> */
    private array $synonyms = [];

    /** @var array<int, array{slug: string, pattern: string, strip: bool}> */
    private array $brands = [];

    /** @var array<int, array{re: string, digital: bool}> */
    private array $electricalDigital = [];

    /** @var array<string, true> */
    private array $placeSet = [];

    private string $placeDashPattern;

    /** @var array<string, int> */
    private array $wordFreq = [];

    private const PC_AREAS_2 = ['AB', 'AL', 'BA', 'BB', 'BD', 'BH', 'BL', 'BN', 'BR', 'BS', 'BT', 'CA', 'CB', 'CF', 'CH', 'CM', 'CO', 'CR', 'CT', 'CV', 'CW', 'DA', 'DD', 'DE', 'DG', 'DH', 'DL', 'DN', 'DT', 'DY', 'EC', 'EH', 'EN', 'EX', 'FK', 'FY', 'GL', 'GU', 'GY', 'HA', 'HD', 'HG', 'HP', 'HR', 'HS', 'HU', 'HX', 'IG', 'IM', 'IP', 'IV', 'JE', 'KA', 'KT', 'KW', 'KY', 'LA', 'LD', 'LE', 'LL', 'LN', 'LS', 'LU', 'ME', 'MK', 'ML', 'NE', 'NG', 'NN', 'NP', 'NR', 'NW', 'OL', 'OX', 'PA', 'PE', 'PH', 'PL', 'PO', 'PR', 'RG', 'RH', 'RM', 'SA', 'SE', 'SG', 'SK', 'SL', 'SM', 'SN', 'SO', 'SP', 'SR', 'SS', 'ST', 'SW', 'SY', 'TA', 'TD', 'TF', 'TN', 'TQ', 'TR', 'TS', 'TW', 'UB', 'WA', 'WC', 'WD', 'WF', 'WN', 'WR', 'WS', 'WV', 'YO', 'ZE'];

    private const PC_AREAS_1 = ['B', 'E', 'G', 'L', 'M', 'N', 'S', 'W'];

    // Places that are also common item words — never stripped as a bare trailing place.
    private const PLACE_HOMONYMS = ['chesterfield', 'sandwich', 'bath', 'derby', 'deal', 'hove', 'leek', 'diss', 'looe', 'mold', 'tring', 'ware', 'wells', 'street'];

    public function __construct()
    {
        $dir = resource_path('desirability');
        $this->loadSynonyms($dir.'/synonyms.csv');
        $this->loadBrands($dir.'/brands.csv');
        $this->loadElectricalDigital($dir.'/electrical_digital.csv');
        $this->loadPlaces($dir.'/places.csv');
        $this->wordFreq = json_decode((string) file_get_contents($dir.'/wordfreq.json'), true) ?: [];
    }

    /**
     * Full pipeline: raw subject -> canonical key + flags, matching the analysis order:
     * cleanV2 -> child-word normalisation -> brand detect (debrand) -> canonicalise -> flag rewrites.
     *
     * @return array{canonical: string, clean: string, brand: ?string, is_ikea: bool, qty: ?int,
     *               is_multiple: bool, is_plural: bool, is_baby: bool, is_kids: bool,
     *               is_heavy: bool, is_vintage: bool, is_electrical: bool, is_digital: bool,
     *               screen_size: ?float}
     */
    public function canonicalise(?string $rawSubject): array
    {
        $v2 = $this->cleanTitleV2($rawSubject);
        $brand = $this->detectBrand($this->normaliseChildWords($v2['title']));
        $canon = $this->canonicaliseClean($brand['debranded']);
        $flags = $this->flagRewrite($canon['canonical'] ?? '');

        return [
            'canonical' => $flags['title'],
            'clean' => $v2['title'],
            'brand' => $brand['brand'],
            'is_ikea' => (bool) $brand['is_ikea'],
            'qty' => $v2['qty'],
            'is_multiple' => $v2['is_multiple'],
            'is_plural' => (bool) $canon['is_plural'],
            'is_baby' => $flags['is_baby'],
            'is_kids' => $flags['is_kids'],
            'is_heavy' => $flags['is_heavy'],
            'is_vintage' => $flags['is_vintage'],
            'is_electrical' => $flags['is_electrical'],
            'is_digital' => $flags['is_digital'],
            'screen_size' => $flags['screen_size'],
        ];
    }

    private static function squish(string $s): string
    {
        return trim((string) preg_replace('~\s+~u', ' ', $s));
    }

    // ---- v1 clean_title port ----

    public function cleanTitleV1(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $s = strtr($raw, ['&#39;' => "'", '&quot;' => '"', '&amp;' => '&', '&gt;' => '>', '&lt;' => '<', '&#92;' => '\\']);
        $decoded = $s;
        if (preg_match('~^.*?(?:offer(?:ed)?|taken)\s*[:\-]?\s*(.*?)\s*(?:\([^()]*\))?\s*(?:\[\d+\s?Attachment(?:s)?\])?\s*$~i', $s, $m)) {
            $s = $m[1];
        } else {
            $s = $decoded;
        }
        $s = (string) preg_replace('~^[;:,.\-\s]+~', '', $s);
        $pc2 = '(?:'.implode('|', self::PC_AREAS_2).')';
        $pc1 = '(?:'.implode('|', self::PC_AREAS_1).')';
        $s = (string) preg_replace('~\s*-?\s*(?:'.$pc2.'|'.$pc1.')[0-9][A-Z0-9]?\s[0-9][A-Z]{2}\s*$~', '', $s);
        $s = (string) preg_replace('~\s*-\s*'.$pc2.'[0-9][A-Z0-9]?\s*$~', '', $s);
        $s = (string) preg_replace($this->placeDashPattern, '', $s);
        if (! strlen(trim($s))) {
            $s = $decoded;
        }
        $s = (string) preg_replace('~(?<!\d)"([^"]*)(?<!\d)"~u', '$1', $s);
        $s = (string) preg_replace('~(?<!\d)"~u', '', $s);
        $s = (string) preg_replace('~[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{1F1E6}-\x{1F1FF}\x{FE0F}\x{200D}]~u', '', $s);
        $s = str_replace('!', '', $s);
        $s = self::squish(mb_strtolower($s));

        return (string) preg_replace('~\.+$~', '', $s);
    }

    // ---- v2 clean (v1 + quantity/condition/status/free/parens/bare-postcode/bare-place) ----

    /** @return array{title: ?string, qty: ?int, is_multiple: bool} */
    public function cleanTitleV2(?string $raw): array
    {
        $s = $this->cleanTitleV1($raw);
        if ($s === null || $s === '') {
            return ['title' => $s, 'qty' => null, 'is_multiple' => false];
        }
        $qty = null;
        $isMultiple = false;

        // parenthetical remnants
        $t = self::squish((string) preg_replace('~\s*\([^()]*\)\s*~u', ' ', $s));
        if (mb_strlen($t) >= 3) {
            $s = $t;
        }

        // status suffixes, repeatedly
        $statusRe = '~\s*[-,(]*\s*(?:still available|available|now taken|pending (?:collection|pickup)|awaiting collection|collection pending|promised(?: to \w+)?|reoffer(?:ed)?|re[- ]?post(?:ed)?|re[- ]?advertised|reduced|update[d]?|sorry no longer available|no longer available|withdrawn|gone|end(?:ing)? soon)\s*[)\.]*\s*$~iu';
        do {
            $prev = $s;
            $s = (string) preg_replace($statusRe, '', $s);
        } while ($s !== $prev && strlen($s));
        if (! strlen($s)) {
            return ['title' => $this->cleanTitleV1($raw), 'qty' => null, 'is_multiple' => false];
        }

        // free-to-collector phrases
        $t = self::squish((string) preg_replace('~\s*[-,(]?\s*\b(?:free to (?:a )?good home|free to good home|free to collect(?:or)?|free for collection|free of charge|foc|freebie|free)\b\)?\s*$~iu', ' ', $s));
        if (mb_strlen($t) >= 3) {
            $s = $t;
        }

        // condition phrases
        $t = self::squish((string) preg_replace([
            '~\s*[-,(]?\s*(?:in\s+)?(?:excellent|very good|good|great|fair|reasonable|perfect|immaculate|pristine|lovely|nice|poor|used|working|clean)\s+(?:used\s+)?condition\b\.?\)?\s*~iu',
            '~\s*[-,(]?\s*\b(?:vgc|gwo|gwc|exc?\s?cond|good cond|fwo|bnib|bnwt|nwt|euc|guc|vguc)\b\.?\)?\s*~iu',
        ], ' ', $s));
        $t = (string) preg_replace('~^\s*(?:brand new|new|as new|nearly new|used|old|unused|unwanted)\s*[-:,]?\s+~iu', '', $t);
        if (mb_strlen($t) >= 3) {
            $s = $t;
        }

        // quantity prefix/suffix
        if (preg_match('~^(?:(\d+)\s*x\s+|x\s*(\d+)\s+|(\d+)\s+of\s+|pair of\s+|(?:a\s+)?set of\s*(\d+)?\s*|(\d+)\s*(?:no\.?|off)\s+)~iu', $s, $mp)) {
            $qty = (int) (($mp[1] ?? '') ?: ($mp[2] ?? '') ?: ($mp[3] ?? '') ?: ($mp[4] ?? '') ?: ($mp[5] ?? '')) ?: null;
            $isMultiple = true;
            $t = self::squish((string) preg_replace('~^(?:(\d+)\s*x\s+|x\s*(\d+)\s+|(\d+)\s+of\s+|pair of\s+|(?:a\s+)?set of\s*(\d+)?\s*|(\d+)\s*(?:no\.?|off)\s+)~iu', '', $s));
            if (mb_strlen($t) >= 3) {
                $s = $t;
            }
        }
        if (preg_match('~\s+(?:x\s*(\d+)|(\d+)\s*x|\(x?\s*\d+\))\s*$~iu', $s, $ms)) {
            $qty = $qty ?: ((int) (($ms[1] ?? '') ?: ($ms[2] ?? '')) ?: null);
            $isMultiple = true;
            $t = self::squish((string) preg_replace('~\s+(?:x\s*(\d+)|(\d+)\s*x|\(x?\s*\d+\))\s*$~iu', '', $s));
            if (mb_strlen($t) >= 3) {
                $s = $t;
            }
        }

        // bare trailing outward postcode (no dash needed)
        $pc2 = '(?:'.implode('|', self::PC_AREAS_2).')';
        $t = (string) preg_replace('~\s+'.$pc2.'[0-9][A-Z0-9]?\s*$~i', '', $s);
        if (mb_strlen($t) >= 3) {
            $s = self::squish($t);
        }

        // bare trailing place name (guarded against item-word homonyms)
        $words = explode(' ', $s);
        if (count($words) >= 2) {
            for ($k = min(3, count($words) - 1); $k >= 1; $k--) {
                $tail = implode(' ', array_slice($words, -$k));
                if (isset($this->placeSet[$tail])) {
                    $s = self::squish(implode(' ', array_slice($words, 0, count($words) - $k)));
                    break;
                }
            }
        }

        $s = self::squish((string) preg_replace('~^[;:,.\-\s]+|[;:,.\-\s]+$~u', '', $s));
        if (! strlen($s)) {
            $s = (string) $this->cleanTitleV1($raw);
        }

        return ['title' => $s, 'qty' => $qty, 'is_multiple' => $isMultiple];
    }

    public function normaliseChildWords(?string $t): ?string
    {
        if ($t === null) {
            return null;
        }

        // JS-parity: ASCII apostrophe only, so "children's" -> "kids" but "children’s"
        // -> "kids’s", which the possessive rule in canonicaliseClean() then tidies.
        return self::squish((string) preg_replace('~\b(?:child(?:ren)?\'?s?|childrens)\b~u', 'kids', $t));
    }

    // ---- brand detection ----

    /** @return array{brand: ?string, is_ikea: ?bool, debranded: ?string} */
    public function detectBrand(?string $text): array
    {
        if ($text === null) {
            return ['brand' => null, 'is_ikea' => null, 'debranded' => null];
        }
        $t = mb_strtolower($text);
        $debranded = $t;
        $brand = null;
        $isIkea = false;
        foreach ($this->brands as $b) {
            if (preg_match($b['pattern'], $debranded)) {
                if ($b['slug'] === 'ikea') {
                    $isIkea = true;
                }
                if ($brand === null) {
                    $brand = $b['slug'];
                }
                if ($b['strip']) {
                    $debranded = (string) preg_replace($b['pattern'], '', $debranded);
                }
            }
        }
        $debranded = self::squish($debranded);
        if (! strlen($debranded)) {
            $debranded = $t;
        }

        return ['brand' => $brand, 'is_ikea' => $isIkea, 'debranded' => $debranded];
    }

    // ---- canonicalise (synonyms + de-pluralisation) ----

    private static function pluraliseRegular(string $w): string
    {
        if (preg_match('~(?:s|x|z|ch|sh)$~', $w)) {
            return $w.'es';
        }
        if (preg_match('~[^aeiou]y$~', $w)) {
            return substr($w, 0, -1).'ies';
        }
        if (str_ends_with($w, 'fe')) {
            return substr($w, 0, -2).'ves';
        }
        if (preg_match('~[^f]f$~', $w)) {
            return substr($w, 0, -1).'ves';
        }

        return $w.'s';
    }

    /** @return array{canonical: ?string, is_plural: ?bool} */
    public function canonicaliseClean(?string $clean): array
    {
        if ($clean === null) {
            return ['canonical' => null, 'is_plural' => null];
        }
        $text = (string) preg_replace('~(\w+)[\'\x{2019}]s\b~u', '$1', $clean);
        $text = (string) preg_replace('~(\w+)[\'\x{2019}](?=\W|$)~u', '$1', $text);
        $text = (string) preg_replace('~\s*&\s*~u', ' and ', $text);
        $text = (string) preg_replace('~(?<=[a-z])\s*/\s*(?=[a-z])~iu', ' ', $text);

        $protected = [];
        $isPlural = false;
        $nextId = 0;

        $synonymPass = function () use (&$text, &$protected, &$isPlural, &$nextId) {
            foreach ($this->synonyms as $syn) {
                if ($syn['is_plural']) {
                    if (preg_match($syn['reEnd'], $text)) {
                        $token = self::PROTECT_OPEN.(++$nextId).self::PROTECT_CLOSE;
                        $protected[$token] = $syn['canonical'];
                        $text = (string) preg_replace($syn['reEnd'], $token, $text, 1);
                        $isPlural = true;
                    }
                    if (preg_match($syn['re'], $text)) {
                        $token = self::PROTECT_OPEN.(++$nextId).self::PROTECT_CLOSE;
                        $protected[$token] = self::pluraliseRegular($syn['canonical']);
                        $text = (string) preg_replace($syn['re'], $token, $text);
                    }
                } elseif (preg_match($syn['re'], $text)) {
                    $token = self::PROTECT_OPEN.(++$nextId).self::PROTECT_CLOSE;
                    $protected[$token] = $syn['canonical'];
                    $text = (string) preg_replace($syn['re'], $token, $text);
                }
            }
        };

        $synonymPass();

        // Trailing de-pluralisation: score candidate stems by corpus frequency, pick the
        // most frequent stem with frequency >= 25 (replaces predesire's hunspell check).
        $cands = [];
        if (preg_match('~\b(\w*(?:s|x|z|ch|sh))es\s*$~u', $text, $m)) {
            $cands[] = ['re' => '~\b\w*(?:s|x|z|ch|sh)es\s*$~u', 'stem' => $m[1]];
        }
        if (preg_match('~\b(\w*[^aeiou\s])ies\s*$~u', $text, $m)) {
            $cands[] = ['re' => '~\b\w*[^aeiou\s]ies\s*$~u', 'stem' => $m[1].'y'];
        }
        if (preg_match('~\b(\w*[^s\s])(?<!ve)s\s*$~u', $text, $m)) {
            $cands[] = ['re' => '~\b\w*[^s\s](?<!ve)s\s*$~u', 'stem' => $m[1]];
        }
        $best = null;
        $bestF = 0;
        foreach ($cands as $c) {
            $f = $this->wordFreq[$c['stem']] ?? 0;
            if ($f > $bestF) {
                $bestF = $f;
                $best = $c;
            }
        }
        if ($best && $bestF >= 25) {
            $text = (string) preg_replace($best['re'], $best['stem'], $text);
            $isPlural = true;
        }

        $synonymPass();

        foreach ($protected as $token => $canonical) {
            $text = str_replace($token, $canonical, $text);
        }

        if (preg_match('~\bx\s?\d+\b|\b\d+\s?x\b~', $text)) {
            foreach ($this->synonyms as $syn) {
                if ($syn['quantity_only'] && str_contains($text, $syn['canonical'])) {
                    $isPlural = true;
                    break;
                }
            }
        }

        return ['canonical' => self::squish($text), 'is_plural' => $isPlural];
    }

    // ---- flag rewrites (post-canonicalise, predesire order) ----

    /**
     * @return array{title: string, is_electrical: bool, is_digital: bool, screen_size: ?float,
     *               is_baby: bool, is_kids: bool, is_heavy: bool, is_vintage: bool}
     */
    public function flagRewrite(?string $title): array
    {
        $out = ['title' => (string) $title, 'is_electrical' => false, 'is_digital' => false, 'screen_size' => null,
            'is_baby' => false, 'is_kids' => false, 'is_heavy' => false, 'is_vintage' => false];
        $t = (string) $title;
        if (! strlen($t)) {
            return $out;
        }
        foreach ($this->electricalDigital as $p) {
            if (preg_match($p['re'], $t)) {
                $out['is_electrical'] = true;
                if ($p['digital']) {
                    $out['is_digital'] = true;
                }
            }
        }
        $screenRe = '~(\d{1,3}(?:\.\d)?)\s*-?\s*(?:inch(?:es)?\b|\'\'|\\\\"|["\x{2033}\x{201D}])~u';
        $deviceRe = '~\b(?:(?:tv|television|telly)\b(?!\s*(?:unit|stand|cabinet|table|bench))|monitor|laptop|notebook|netbook|tablet|screen|display|macbook|ipad|chromebook)\b~i';
        if ($out['is_digital'] && preg_match($screenRe, $t, $sm) && preg_match($deviceRe, $t)) {
            $size = (float) $sm[1];
            if ($size >= 5 && $size <= 100) {
                $out['screen_size'] = $size;
                $t = self::squish((string) preg_replace($screenRe, '$1in', $t, 1));
            }
        }
        $words = explode(' ', $t);
        if (count($words) > 1) {
            if ($words[0] === 'baby') {
                $out['is_baby'] = true;
                $t = implode(' ', array_slice($words, 1));
            } elseif ($words[0] === 'kid' || $words[0] === 'kids') {
                $out['is_kids'] = true;
                $t = implode(' ', array_slice($words, 1));
            }
        }
        $orig = $t;
        if (preg_match('~\bheavy(?:\s+duty)?\b~', $t)) {
            $out['is_heavy'] = true;
            $t = self::squish((string) preg_replace('~\bheavy(?:\s+duty)?\b~', '', $t));
        }
        if (preg_match('~\bvintage\b~', $t)) {
            $out['is_vintage'] = true;
            $t = self::squish((string) preg_replace('~\bvintage\b~', '', $t));
        }
        if (! strlen($t)) {
            $t = strlen($orig) ? $orig : (string) $title;
        }
        $out['title'] = $t;

        return $out;
    }

    // ---- table loading ----

    /** @return array<int, array<string, string>> */
    private function readCsv(string $path): array
    {
        $rows = [];
        $fh = fopen($path, 'r');
        if (! $fh) {
            return $rows;
        }
        $hdr = fgetcsv($fh, 0, ',', '"', '');
        while (($row = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            if ($hdr && count($row) === count($hdr)) {
                $rows[] = array_combine($hdr, $row);
            }
        }
        fclose($fh);

        return $rows;
    }

    private function loadSynonyms(string $path): void
    {
        $rows = $this->readCsv($path);
        usort($rows, fn ($a, $b) => strlen($b['alias']) <=> strlen($a['alias']));
        foreach ($rows as $r) {
            $esc = preg_quote($r['alias'], '~');
            $esc = str_replace(' ', '[\s/]+', $esc);
            $this->synonyms[] = [
                'alias' => $r['alias'],
                'canonical' => $r['canonical'],
                'is_plural' => $r['is_plural'] === 'TRUE',
                'quantity_only' => ($r['quantity_only'] ?? 'FALSE') === 'TRUE',
                're' => '~\b'.$esc.'\b~',
                'reEnd' => '~\b'.$esc.'\b\s*$~',
            ];
        }
    }

    private function loadBrands(string $path): void
    {
        $rows = array_filter($this->readCsv($path), fn ($r) => ($r['pattern'] ?? '') !== '');
        usort($rows, fn ($a, $b) => strlen($b['pattern']) <=> strlen($a['pattern']));
        foreach ($rows as $r) {
            $this->brands[] = [
                'slug' => $r['slug'],
                'pattern' => '~'.$r['pattern'].'~i',
                'strip' => ($r['strip'] ?? 'TRUE') !== 'FALSE',
            ];
        }
    }

    private function loadElectricalDigital(string $path): void
    {
        foreach ($this->readCsv($path) as $r) {
            $this->electricalDigital[] = [
                're' => '~'.$r['pattern'].'~i',
                'digital' => $r['is_digital'] === 'TRUE',
            ];
        }
    }

    private function loadPlaces(string $path): void
    {
        $places = array_map(fn ($r) => mb_strtolower($r['place']), $this->readCsv($path));
        $homonyms = array_flip(self::PLACE_HOMONYMS);
        foreach ($places as $p) {
            if (! isset($homonyms[$p])) {
                $this->placeSet[$p] = true;
            }
        }
        $alts = array_map(fn ($p) => str_replace(' ', '\s+', preg_quote($p, '~')), $places);
        $this->placeDashPattern = '~\s*-\s*(?:'.implode('|', $alts).')\s*$~i';
    }
}
