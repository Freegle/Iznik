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
     * Standalone words that are not part of the item but bias the AI image generator away
     * from it - "adult" pulls the model toward pharmacy/supplement imagery (Discourse topic
     * 9630/60: a WANTED post for "Adult bike" got a medicine bottle). Stripped anywhere in
     * the name, the same way please/pls/plz are, because it can lead ("Adult bike") or
     * trail ("mountain bike, adult size") the item.
     */
    private const BIAS_WORD = '/\badults?\b[!?.,]*/i';

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
        $cleaned = preg_replace(self::BIAS_WORD, ' ', $cleaned) ?? $cleaned;
        $cleaned = trim(preg_replace('/\s+/', ' ', $cleaned) ?? $cleaned);
        $cleaned = rtrim($cleaned, " \t,;:");

        return $cleaned === '' ? $name : $cleaned;
    }
}
