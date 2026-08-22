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
    private const COURTESY = '/\b(?:please+|pls|plz)\b[!?.]*/i';

    /**
     * Remove courtesy words from an item name: members write "iron please", and an image
     * generator has no way to know that is not part of the item, so it tries to draw it -
     * the WANTED post for "iron please" came out as a smooth white blob (Discourse 9209/98).
     *
     * A name that is nothing BUT a courtesy word is returned unchanged: there is no item to
     * draw either way, and callers read an empty name as "no item at all".
     */
    public static function stripCourtesy(string $name): string
    {
        $cleaned = preg_replace(self::COURTESY, ' ', $name) ?? $name;
        $cleaned = trim(preg_replace('/\s+/', ' ', $cleaned) ?? $cleaned);
        $cleaned = rtrim($cleaned, " \t,;:");

        return $cleaned === '' ? $name : $cleaned;
    }
}
