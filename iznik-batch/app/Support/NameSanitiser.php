<?php

namespace App\Support;

/**
 * Rewrites misleading display names for non-moderator users — see
 * Discourse thread #9587. Storage is untouched; the user receives no
 * signal that their name has been flagged on display.
 *
 * Mirror of iznik-server-go/user/namevalidation.go — keep the two in sync.
 */
class NameSanitiser
{
    /** Brand words. Fuzzy-matched via Damerau-Levenshtein (see fuzzyHitTierA). */
    private const TIER_A = [
        'freegle', 'ilovefreegle', 'thefreegle',
        'trashnothing', 'freecycle', 'freshare',
    ];

    /**
     * Brand words short enough that a one-edit neighbour is a real word we use
     * ("freegle" → "freegler"). Require exact match after normalisation.
     */
    private const TIER_A_EXACT_ONLY = [
        'freegle' => TRUE,
        'freshare' => TRUE,
        'freecycle' => TRUE,
    ];

    /**
     * Derivatives of a brand word that are common English constructions in
     * our community ("freegler"). Only suspicious when combined with an
     * authority word ("Freegler Support").
     */
    private const WEAK_BRAND = [
        'freegler' => TRUE, 'freeglers' => TRUE,
        'freegling' => TRUE, 'freegled' => TRUE,
    ];

    /**
     * Authority / role / phishing words. Used for two rules:
     *  1. all-authority: every token is in here (e.g. "Admin", "Support Team")
     *  2. weak-brand + authority (e.g. "Freegler Support")
     */
    private const TIER_B = [
        'support' => TRUE, 'supportteam' => TRUE, 'team' => TRUE, 'admin' => TRUE,
        'administrator' => TRUE, 'moderator' => TRUE, 'mod' => TRUE, 'staff' => TRUE,
        'official' => TRUE, 'officialteam' => TRUE, 'hq' => TRUE, 'headquarters' => TRUE,
        'centre' => TRUE, 'center' => TRUE, 'customer' => TRUE, 'customerservice' => TRUE,
        'customercare' => TRUE, 'helpdesk' => TRUE, 'help' => TRUE, 'service' => TRUE,
        'services' => TRUE, 'info' => TRUE, 'contact' => TRUE, 'enquiries' => TRUE,
        'security' => TRUE, 'verify' => TRUE, 'verification' => TRUE, 'verified' => TRUE,
        'account' => TRUE, 'accounts' => TRUE, 'billing' => TRUE, 'notification' => TRUE,
        'notifications' => TRUE, 'alert' => TRUE, 'alerts' => TRUE, 'system' => TRUE,
        'systems' => TRUE, 'update' => TRUE, 'updates' => TRUE, 'warning' => TRUE,
        'fraud' => TRUE, 'abuse' => TRUE, 'safety' => TRUE, 'trust' => TRUE,
        'claims' => TRUE, 'refund' => TRUE, 'refunds' => TRUE, 'winner' => TRUE,
        'prize' => TRUE, 'prizes' => TRUE, 'lottery' => TRUE, 'giveaway' => TRUE,
        'reward' => TRUE, 'rewards' => TRUE, 'authority' => TRUE, 'agent' => TRUE,
        'representative' => TRUE, 'rep' => TRUE, 'response' => TRUE, 'responder' => TRUE,
        'policy' => TRUE, 'compliance' => TRUE, 'review' => TRUE, 'reviewer' => TRUE,
        'audit' => TRUE, 'suspension' => TRUE, 'suspended' => TRUE, 'ban' => TRUE,
        'banned' => TRUE,
    ];

    /** Common digit/punct → letter substitutions so "Fr33gle" normalises like "Freegle". */
    private const LEET = [
        '0' => 'o', '1' => 'l', '3' => 'e', '4' => 'a', '5' => 's',
        '7' => 't', '@' => 'a', '$' => 's', '!' => 'i',
    ];

    /**
     * TN-imported fullnames like "alice-g3486@user.trashnothing.com" are an
     * import side-effect, not deliberate impersonation.
     */
    private const TN_EMAIL_SUFFIX = '/-g[0-9]+@user\.trashnothing\.com$/i';

    /**
     * Returns a safe rewrite of $raw for non-exempt users, or $raw unchanged
     * for exempt users and clean names. Rewrites suspicious names to
     * "A freegler" — a neutral placeholder used elsewhere in the codebase.
     */
    public static function sanitize(string $raw, bool $isExempt): string
    {
        if ($isExempt) {
            return $raw;
        }
        if (!self::isSuspicious($raw)) {
            return $raw;
        }
        return 'A freegler';
    }

    /**
     * Damerau-Levenshtein edit distance between $a and $b, early-exiting at
     * $maxD+1 to avoid doing work for distant pairs.
     */
    public static function damerauLevenshtein(string $a, string $b, int $maxD): int
    {
        $la = strlen($a);
        $lb = strlen($b);
        if (abs($la - $lb) > $maxD) {
            return $maxD + 1;
        }
        $prev2 = array_fill(0, $lb + 1, 0);
        $prev = range(0, $lb);
        $cur = array_fill(0, $lb + 1, 0);
        for ($i = 1; $i <= $la; $i++) {
            $cur[0] = $i;
            $minRow = $cur[0];
            for ($j = 1; $j <= $lb; $j++) {
                $cost = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
                $v = min($prev[$j] + 1, $cur[$j - 1] + 1, $prev[$j - 1] + $cost);
                if ($i > 1 && $j > 1 && $a[$i - 1] === $b[$j - 2] && $a[$i - 2] === $b[$j - 1]) {
                    $v = min($v, $prev2[$j - 2] + $cost);
                }
                $cur[$j] = $v;
                if ($v < $minRow) {
                    $minRow = $v;
                }
            }
            if ($minRow > $maxD) {
                return $maxD + 1;
            }
            [$prev2, $prev, $cur] = [$prev, $cur, $prev2];
        }
        return $prev[$lb];
    }

    /**
     * Lower-case, strip diacritics/zero-width, de-leet and drop all
     * non-alphanumeric characters. Used for the "concat" match rule.
     */
    private static function normalise(string $name): string
    {
        $decomposed = self::nfkd($name);
        $out = '';
        $len = strlen($decomposed);
        for ($i = 0; $i < $len;) {
            $byte = $decomposed[$i];
            $ord = ord($byte);
            if ($ord < 0x80) {
                $c = strtolower($byte);
                $c = self::LEET[$c] ?? $c;
                if (ctype_alnum($c)) {
                    $out .= $c;
                }
                $i++;
                continue;
            }
            // Multi-byte UTF-8 sequence: skip combining marks, otherwise
            // keep the codepoint lowercased.
            [$cp, $width] = self::decodeUtf8($decomposed, $i);
            $i += $width;
            if (self::isCombiningMark($cp)) {
                continue;
            }
            $char = mb_strtolower(self::encodeUtf8($cp), 'UTF-8');
            // Non-ASCII letter → only keep if alnum (rare in names; drop otherwise).
            if (mb_strlen($char, 'UTF-8') === 1 && preg_match('/[\p{L}\p{N}]/u', $char)) {
                $out .= $char;
            }
        }
        return $out;
    }

    /**
     * Split $name into lowercase, de-leeted, de-accented tokens. Used for
     * per-token matching.
     */
    private static function tokenise(string $name): array
    {
        $decomposed = self::nfkd($name);
        $buf = '';
        $len = strlen($decomposed);
        for ($i = 0; $i < $len;) {
            $byte = $decomposed[$i];
            $ord = ord($byte);
            if ($ord < 0x80) {
                $c = strtolower($byte);
                $c = self::LEET[$c] ?? $c;
                if (ctype_alnum($c)) {
                    $buf .= $c;
                } else {
                    $buf .= ' ';
                }
                $i++;
                continue;
            }
            [$cp, $width] = self::decodeUtf8($decomposed, $i);
            $i += $width;
            if (self::isCombiningMark($cp)) {
                continue;
            }
            $char = mb_strtolower(self::encodeUtf8($cp), 'UTF-8');
            if (mb_strlen($char, 'UTF-8') === 1 && preg_match('/[\p{L}\p{N}]/u', $char)) {
                $buf .= $char;
            } else {
                $buf .= ' ';
            }
        }
        $tokens = preg_split('/\s+/', trim($buf));
        return array_values(array_filter($tokens, static fn($t) => $t !== ''));
    }

    /**
     * Returns TRUE if $tok matches any Tier A brand word exactly (for short
     * brand words) or within Damerau-Levenshtein distance 2 (for long ones,
     * bounded by length-difference 1 to avoid trivial matches).
     */
    private static function fuzzyHitTierA(string $tok): bool
    {
        $tl = strlen($tok);
        foreach (self::TIER_A as $target) {
            if (isset(self::TIER_A_EXACT_ONLY[$target])) {
                if ($tok === $target) {
                    return TRUE;
                }
                continue;
            }
            $lt = strlen($target);
            if (abs($tl - $lt) > 1) {
                continue;
            }
            if (self::damerauLevenshtein($tok, $target, 2) <= 2) {
                return TRUE;
            }
        }
        return FALSE;
    }

    private static function isSuspicious(string $raw): bool
    {
        if ($raw === '') {
            return FALSE;
        }
        // Ignore email-like and TN-suffix fullnames (import side-effects).
        if (str_contains($raw, '@')) {
            return FALSE;
        }
        if (preg_match(self::TN_EMAIL_SUFFIX, $raw) === 1) {
            return FALSE;
        }

        $tokens = self::tokenise($raw);
        if (empty($tokens)) {
            return FALSE;
        }

        $concat = self::normalise($raw);

        $hasTierB = FALSE;
        $allTierB = TRUE;
        foreach ($tokens as $t) {
            if (isset(self::TIER_B[$t])) {
                $hasTierB = TRUE;
            } else {
                $allTierB = FALSE;
            }
        }

        // Rule 1: Tier A token match.
        foreach ($tokens as $t) {
            if (self::fuzzyHitTierA($t)) {
                return TRUE;
            }
        }
        // Rule 2: Tier A concat match (obfuscation-resistant).
        if (self::fuzzyHitTierA($concat)) {
            return TRUE;
        }
        // Rule 3: weak brand + authority.
        if ($hasTierB) {
            foreach ($tokens as $t) {
                if (isset(self::WEAK_BRAND[$t])) {
                    return TRUE;
                }
            }
        }
        // Rule 4: all tokens are Tier B authority words.
        if ($allTierB) {
            return TRUE;
        }
        return FALSE;
    }

    private static function nfkd(string $s): string
    {
        if (class_exists(\Normalizer::class)) {
            $out = \Normalizer::normalize($s, \Normalizer::FORM_KD);
            if ($out !== FALSE) {
                return $out;
            }
        }
        return $s;
    }

    /**
     * Decode one UTF-8 codepoint starting at byte offset $i. Returns
     * [codepoint, byte-width]. Invalid sequences fall back to [0xFFFD, 1].
     */
    private static function decodeUtf8(string $s, int $i): array
    {
        $b = ord($s[$i]);
        if ($b < 0x80) {
            return [$b, 1];
        }
        if (($b & 0xE0) === 0xC0 && $i + 1 < strlen($s)) {
            return [(($b & 0x1F) << 6) | (ord($s[$i + 1]) & 0x3F), 2];
        }
        if (($b & 0xF0) === 0xE0 && $i + 2 < strlen($s)) {
            return [(($b & 0x0F) << 12) | ((ord($s[$i + 1]) & 0x3F) << 6) | (ord($s[$i + 2]) & 0x3F), 3];
        }
        if (($b & 0xF8) === 0xF0 && $i + 3 < strlen($s)) {
            return [
                (($b & 0x07) << 18) | ((ord($s[$i + 1]) & 0x3F) << 12) | ((ord($s[$i + 2]) & 0x3F) << 6) | (ord($s[$i + 3]) & 0x3F),
                4,
            ];
        }
        return [0xFFFD, 1];
    }

    private static function encodeUtf8(int $cp): string
    {
        if ($cp < 0x80) {
            return chr($cp);
        }
        if ($cp < 0x800) {
            return chr(0xC0 | ($cp >> 6)) . chr(0x80 | ($cp & 0x3F));
        }
        if ($cp < 0x10000) {
            return chr(0xE0 | ($cp >> 12)) . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
        }
        return chr(0xF0 | ($cp >> 18))
            . chr(0x80 | (($cp >> 12) & 0x3F))
            . chr(0x80 | (($cp >> 6) & 0x3F))
            . chr(0x80 | ($cp & 0x3F));
    }

    /** Unicode category Mn (Nonspacing_Mark) — covers combining diacritics and ZWJ. */
    private static function isCombiningMark(int $cp): bool
    {
        $ch = self::encodeUtf8($cp);
        return preg_match('/^\p{Mn}$/u', $ch) === 1;
    }
}
