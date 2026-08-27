<?php

namespace App\Support;

/**
 * Tidy a post's item text - the subject between "OFFER:"/"WANTED:" and the trailing
 * "(location)" - before it is used as something other than display text.
 *
 * The Go API has the same rules in iznik-server-go/misc/itemname.go, because the two sides
 * key the same illustration cache: this writes ai_images.name, GetIllustration reads it.
 */
final class ItemName
{
    /**
     * Standalone courtesy words. Word boundaries keep real words that merely begin the same
     * way ("Pleaser" boots); trailing punctuation goes with the word so "Pallets Please!!"
     * leaves no stray "!!".
     */
    private const COURTESY = '/\b(?:please+|pls|plz)\b[!?.,]*/i';

    /**
     * A sign-off at the END of a name: "thanks", "thank you", "thank you in advance". Unlike
     * "please" these are anchored to the end, because they DO occur inside real item names -
     * production has 113 posts for a "thank you card" or "thank you gift", and stripping
     * mid-name would offer those as plain "cards". All 14 production names carrying a thanks
     * trailer have it at the end. The repeat group eats a run of them ("please, thank you").
     *
     * Deliberately absent: "ta" and "tia". Both look like courtesy words and neither is safe -
     * all 20 production names containing "ta" are job titles ("SEN TA", "Teaching Assistant
     * (TA)"), and half the "tia" ones are the Siemens "TIA Portal".
     */
    private const TRAILING_THANKS = '/(?:[\s,;:.!?]*\b(?:thanks?(?:\s+you)?(?:\s+very\s+much)?(?:\s+in\s+advance)?|thankyou|thx)\b)+[\s,;:.!?]*$/i';

    /**
     * "adult" pulls the image generator toward pharmacy/supplement imagery (Discourse topic
     * 9630/60: a WANTED post for "Adult bike" got a medicine bottle), so it is stripped
     * anywhere in the name the way please/pls/plz are.
     *
     * BIAS_QUALIFIER runs first and takes the noun the word governs with it. Removing only
     * the word leaves the qualifier stranded - "mountain bike, adult size" would become
     * "mountain bike, size" and "adults only jigsaw" would become "only jigsaw", both of
     * which read worse to the generator than the original.
     */
    private const BIAS_QUALIFIER = '/\badults?\s+(?:sized?|only)\b[!?.,]*/i';

    private const BIAS_WORD = '/\badults?\b[!?.,]*/i';

    /**
     * Debris left behind once a bias word is lifted out of the middle of a name: a
     * conjunction or preposition with nothing left on one side of it ("Adult and kids" ->
     * "and kids", "A bike for adult" -> "A bike for"), and the empty separator left by
     * "hangers - adult size - will split". Articles are deliberately not stripped, only
     * corrected for agreement, so "An adult cycle" gives "A cycle" rather than "An cycle".
     */
    private const STRANDED_LEAD = '/^[\s,;:.\-]*\b(?:and|or|for|with)\b[\s,;:.\-]*/i';

    private const STRANDED_TRAIL = '/[\s,;:.\-]*\b(?:and|or|for|with|of)\b[\s,;:.\-]*$/i';

    private const EMPTY_SEPARATOR = '/\s*-\s*-\s*/';

    /**
     * Remove courtesy words and other bias words from an item name: members write "iron
     * please", and an image generator has no way to know that is not part of the item, so
     * it tries to draw it - the WANTED post for "iron please" came out as a smooth white
     * blob (Discourse 9209/98).
     *
     * A name that is nothing BUT a courtesy/bias word is returned unchanged: there is no
     * item to draw either way, and callers read an empty name as "no item at all".
     */
    public static function stripCourtesy(string $name): string
    {
        $cleaned = preg_replace(self::TRAILING_THANKS, '', $name) ?? $name;
        $cleaned = preg_replace(self::COURTESY, ' ', $cleaned) ?? $cleaned;
        $biasBefore = $cleaned;
        $cleaned = preg_replace(self::BIAS_QUALIFIER, ' ', $cleaned) ?? $cleaned;
        $cleaned = preg_replace(self::BIAS_WORD, ' ', $cleaned) ?? $cleaned;

        // Only tidy when a bias word actually came out, so names that never contained one
        // keep going through exactly the path they did before.
        if ($cleaned !== $biasBefore) {
            $cleaned = preg_replace(self::EMPTY_SEPARATOR, ' - ', $cleaned) ?? $cleaned;
            $cleaned = preg_replace(self::STRANDED_LEAD, '', $cleaned) ?? $cleaned;
            $cleaned = preg_replace(self::STRANDED_TRAIL, '', $cleaned) ?? $cleaned;
            $cleaned = self::fixArticle($cleaned);
        }

        $cleaned = trim(preg_replace('/\s+/', ' ', $cleaned) ?? $cleaned);
        $cleaned = rtrim($cleaned, " \t,;:-");

        return $cleaned === '' ? $name : $cleaned;
    }

    /**
     * Restore a/an agreement after a bias word is removed from between the article and the
     * noun: "An adult cycle" would otherwise leave "An cycle".
     */
    private static function fixArticle(string $name): string
    {
        return preg_replace_callback(
            '/^(an?)(\s+)([a-z])/i',
            static function (array $m): string {
                $vowel   = in_array(strtolower($m[3]), ['a', 'e', 'i', 'o', 'u'], true);
                $article = $vowel ? 'an' : 'a';
                if ($m[1][0] === strtoupper($m[1][0])) {
                    $article = ucfirst($article);
                }

                return $article . $m[2] . $m[3];
            },
            $name
        ) ?? $name;
    }
}
