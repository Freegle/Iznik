<?php

namespace Tests\Unit\Support;

use App\Support\NameSanitiser;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Covers display-name rewrite rules for Discourse thread #9587.
 * Stored name is untouched; non-mods with suspicious names get rewritten
 * on display only.
 */
class NameSanitiserTest extends TestCase
{
    public static function suspiciousNames(): array
    {
        return [
            'freegle support (exact phishing)' => ['iLovefreegle Support'],
            'freegle support team' => ['Freegle Support Team'],
            'lowercase variant' => ['ilovefreegle support team'],
            'freegle admin' => ['Freegle Admin'],
            'trashnothing help' => ['TrashNothing Help'],
            'leet digits' => ['Fr33gle Supp0rt'],
            'spacing obfuscation' => ['i Love freegle Team'],
            'dotted freecycle' => ['free.cycle'],
            'one edit ilovefreegle' => ['Ilovefreegl Support'],
            'bare freegle' => ['Freegle'],
            'freegle postcode' => ['Freegle SK9'],
            'bare admin' => ['Admin'],
            'support team solo' => ['Support Team'],
            'prize winner' => ['Prize Winner'],
            'surname freegle' => ['Susan Freegle'],
            'freegler support (weak brand + authority)' => ['Freegler Support'],
            // Tier A concat match — brand name with no spaces or token boundary
            'ilovefreegle concat no spaces' => ['ilovefreegle'],
            // Leet speak normalised to an exact-only brand word
            'leet freegle full word' => ['Fr33gl3'],
            // Diacritics decomposed away to reveal brand word
            'accented e yields freegle' => ["Fr\u{00EB}egle"],
            // Freshare is TIER_A_EXACT_ONLY: exact token match triggers
            'freshare exact' => ['Freshare'],
            'freshare with authority' => ['Freshare Support'],
            // thefreegle is TIER_A (not exact-only): fuzzy match at distance 0
            'thefreegle exact' => ['TheFreegle'],
            // One Damerau-Levenshtein edit off a non-exact-only brand word
            'one edit thefreegle' => ['TheFregle'],
            // All-Tier-B authority words (Rule 4)
            'all tier b helpdesk' => ['HelpDesk'],
            'all tier b lottery winner' => ['Lottery Winner'],
            'all tier b verified account' => ['Verified Account'],
            'all tier b security alert' => ['Security Alert'],
            'all tier b suspended banned' => ['Suspended Banned'],
            // Weak-brand + authority (Rule 3)
            'freegling with support' => ['Freegling Support'],
            'freeglers with admin' => ['Freeglers Admin'],
            'freegled with team' => ['Freegled Team'],
            'freeglers banned' => ['Freeglers Banned'],
        ];
    }

    #[DataProvider('suspiciousNames')]
    public function test_suspicious_names_are_rewritten_for_non_mods(string $input): void
    {
        $out = NameSanitiser::sanitize($input, isExempt: false);
        $this->assertNotSame($input, $out, "expected '$input' to be rewritten");
        $this->assertNotEmpty($out);
    }

    public static function cleanNames(): array
    {
        return [
            'plain name' => ['Emma Brown'],
            'authority-sounding surname' => ['Emma Support'],
            'freegler alone' => ['Adam Freegler'],
            'eagle (near-miss)' => ['Eagle'],
            'greg (near-miss)' => ['Greg'],
            'empty' => [''],
            'single letter' => ['J'],
            'tn email leak' => ['alice-g3486@user.trashnothing.com'],
            // @ in name bypasses suspicious check entirely
            'arbitrary email address' => ['admin@example.com'],
            'freegle.com domain' => ['user@freegle.com'],
            // Weak-brand words alone (no authority partner) are clean
            'freegler alone no authority' => ['Freegler'],
            'freeglers alone' => ['Freeglers'],
            'freegling alone' => ['Freegling'],
            'freegled alone' => ['Freegled'],
            // Near-miss of TIER_A_EXACT_ONLY: one edit but exact-only rule blocks fuzzy
            'freecycle one edit blocked by exact-only' => ['freecyclx'],
            'freshare one edit blocked by exact-only' => ['fresharx'],
            // Punctuation/symbols-only: tokenises to empty → not suspicious
            'hash symbols only' => ['###'],
            'punctuation mix' => ['!@#$%'],
            // Multi-token with one authority word but also normal tokens (allTierB=false, no brand)
            'normal name with authority token' => ['Admin Johnson'],
            // Numbers alone do not match any brand
            'numbers only' => ['12345'],
            // Long string far from any brand word
            'completely unrelated name' => ['Freedom Eagle'],
        ];
    }

    #[DataProvider('cleanNames')]
    public function test_clean_names_pass_through_unchanged(string $input): void
    {
        $this->assertSame($input, NameSanitiser::sanitize($input, isExempt: false));
    }

    public function test_exempt_users_keep_any_name(): void
    {
        $suspicious = ['iLovefreegle Support', 'Freegle Admin', 'Admin'];
        foreach ($suspicious as $name) {
            $this->assertSame(
                $name,
                NameSanitiser::sanitize($name, isExempt: true),
                "exempt user's '$name' must pass through"
            );
        }
    }

    public function test_damerau_levenshtein_covers_transposition(): void
    {
        $this->assertSame(1, NameSanitiser::damerauLevenshtein('freegle', 'freegel', 2));
        $this->assertSame(1, NameSanitiser::damerauLevenshtein('freegle', 'freeggle', 2));
        $this->assertSame(0, NameSanitiser::damerauLevenshtein('freegle', 'freegle', 2));
    }

    public static function levenshteinCases(): array
    {
        return [
            // Exact matches always return 0
            'identical empty strings' => ['', '', 0, 0],
            'identical single char' => ['a', 'a', 0, 0],
            'identical word' => ['hello', 'hello', 5, 0],
            // Basic single-edit operations
            'one insertion at end' => ['abc', 'abcd', 5, 1],
            'one deletion at end' => ['abcd', 'abc', 5, 1],
            'one substitution' => ['abc', 'axc', 5, 1],
            // Transpositions (Damerau extension over plain Levenshtein)
            'adjacent transposition two chars' => ['ab', 'ba', 5, 1],
            'transposition in middle' => ['abcde', 'abdce', 5, 1],
            // Well-known multi-edit example
            'kitten to sitting' => ['kitten', 'sitting', 10, 3],
            // Early-exit via length difference: abs(la-lb) > maxD → returns maxD+1
            'length diff 2 exceeds maxD 1' => ['a', 'abc', 1, 2],
            'length diff 3 exceeds maxD 0' => ['', 'abc', 0, 1],
            // Length diff equal to maxD is allowed (does NOT trigger early exit)
            'length diff equals maxD proceeds normally' => ['ab', 'abc', 1, 1],
            // maxD=0 exact match still succeeds
            'maxD zero with identical strings' => ['foo', 'foo', 0, 0],
        ];
    }

    #[DataProvider('levenshteinCases')]
    public function test_damerau_levenshtein_table(string $a, string $b, int $maxD, int $expected): void
    {
        $this->assertSame($expected, NameSanitiser::damerauLevenshtein($a, $b, $maxD));
    }

    public function test_damerau_levenshtein_is_symmetric(): void
    {
        $pairs = [
            ['freegle', 'freegel', 2],
            ['kitten', 'sitting', 10],
            ['abc', 'abcd', 5],
            ['', 'hello', 5],
        ];
        foreach ($pairs as [$a, $b, $maxD]) {
            $this->assertSame(
                NameSanitiser::damerauLevenshtein($a, $b, $maxD),
                NameSanitiser::damerauLevenshtein($b, $a, $maxD),
                "damerauLevenshtein('$a','$b') should equal damerauLevenshtein('$b','$a')"
            );
        }
    }

    public function test_rewrite_result_is_always_a_freegler(): void
    {
        $suspicious = ['Freegle Admin', 'Admin', 'iLovefreegle Support'];
        foreach ($suspicious as $name) {
            $this->assertSame('A freegler', NameSanitiser::sanitize($name, isExempt: false));
        }
    }

    public function test_case_insensitive_for_authority_words(): void
    {
        // TIER_B matching is case-insensitive via tokenise lowercasing
        $this->assertSame('A freegler', NameSanitiser::sanitize('SUPPORT TEAM', isExempt: false));
        $this->assertSame('A freegler', NameSanitiser::sanitize('support team', isExempt: false));
        $this->assertSame('A freegler', NameSanitiser::sanitize('Support Team', isExempt: false));
    }

    public function test_leet_speak_normalisation(): void
    {
        // 3→e, 0→o in various brand-word patterns
        $this->assertSame('A freegler', NameSanitiser::sanitize('Fr33gle', isExempt: false));
        $this->assertSame('A freegler', NameSanitiser::sanitize('Fr33gl3', isExempt: false));
        // Supp0rt (0→o) alone is a Tier B word
        $this->assertSame('A freegler', NameSanitiser::sanitize('Supp0rt', isExempt: false));
    }

    public function test_non_ascii_letter_in_name_passes_through(): void
    {
        // Greek capital Alpha (U+0391, 2-byte UTF-8) is a non-combining-mark letter.
        // In normalise() it exercises the multi-byte non-combining letter branch (lines ~158-161)
        // and in tokenise() the equivalent branch (~195-197).
        // 'Αdmin' normalises token 'αdmin' which is not in any Tier, so the name is clean.
        $this->assertSame("Αdmin", NameSanitiser::sanitize("Αdmin", isExempt: false));

        // Combined: Greek letter + authority word. Only 'support' is Tier B; 'αdmin' is not,
        // so allTierB=false and none of the suspicious rules fire.
        $this->assertSame("Αdmin Support", NameSanitiser::sanitize("Αdmin Support", isExempt: false));
    }

    public function test_non_alnum_multibyte_symbol_acts_as_token_separator(): void
    {
        // Bullet U+2022 (3-byte UTF-8, category Po) is not alphanumeric.
        // In tokenise() it hits the multi-byte non-letter/non-number branch (~line 199) and is
        // treated as a word separator. decodeUtf8 exercises the 3-byte path (~lines 311-312)
        // and encodeUtf8 exercises the 3-byte path (~lines 331-332).
        //
        // 'Admin • Moderator' → tokens ['admin', 'moderator'] → all Tier B → suspicious.
        $this->assertSame('A freegler', NameSanitiser::sanitize("Admin \u{2022} Moderator", isExempt: false));

        // 'Emma • Smith' → tokens ['emma', 'smith'] → not suspicious → unchanged.
        $this->assertSame("Emma \u{2022} Smith", NameSanitiser::sanitize("Emma \u{2022} Smith", isExempt: false));
    }

    public function test_emoji_acts_as_token_separator(): void
    {
        // Gift-box emoji U+1F381 (4-byte UTF-8, category So) is not a letter/number.
        // decodeUtf8 exercises the 4-byte codepoint path (~lines 314-318) and encodeUtf8
        // exercises the 4-byte path (~lines 334-337).
        //
        // '🎁 Admin Support' → emoji is separator → tokens ['admin', 'support'] → all Tier B.
        $this->assertSame('A freegler', NameSanitiser::sanitize("🎁 Admin Support", isExempt: false));

        // 'Emma 🎁 Jones' → tokens ['emma', 'jones'] → not suspicious → unchanged.
        $this->assertSame("Emma 🎁 Jones", NameSanitiser::sanitize("Emma 🎁 Jones", isExempt: false));
    }
}
