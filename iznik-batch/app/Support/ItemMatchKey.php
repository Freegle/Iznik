<?php

namespace App\Support;

/**
 * The key an AI item picture is shared by: an item ("Knitting needles", "e-bike") in lower case,
 * without punctuation, each word singular. Two items with the same key get one picture.
 *
 * Deliberately light. It runs on the item ItemCleanerService has already picked out of a post's
 * wording, so brand, colour, quantity and "wanted" are gone before it starts. Stripping more
 * here was measured to do harm: dropping single letters turned "e-bike" into "bike" and "L
 * plates" into "plate". docs/developers/reference/ai-item-images.md.
 */
final class ItemMatchKey
{
    /** Nouns that only exist in the plural: singularising them makes a different word. */
    private const PLURAL_ONLY = [
        'glasses', 'scissors', 'trousers', 'jeans', 'clothes', 'pants', 'shorts', 'tights', 'leggings',
        'pyjamas', 'pajamas', 'binoculars', 'tongs', 'pliers', 'tweezers', 'headphones', 'earphones',
        'goggles', 'drawers', 'stairs', 'news', 'series', 'species', 'chess', 'dregs', 'overalls',
        'dungarees', 'knickers', 'boxers', 'sunglasses', 'spectacles', 'secateurs', 'shears', 'lees',
    ];

    private const IRREGULAR = [
        'knives' => 'knife', 'shelves' => 'shelf', 'leaves' => 'leaf', 'loaves' => 'loaf', 'wolves' => 'wolf',
        'halves' => 'half', 'children' => 'child', 'mice' => 'mouse', 'geese' => 'goose', 'feet' => 'foot',
        'teeth' => 'tooth', 'men' => 'man', 'women' => 'woman', 'people' => 'person', 'dice' => 'die',
        'scarves' => 'scarf', 'hooves' => 'hoof',
    ];

    public static function key(string $item): string
    {
        $s = mb_strtolower($item);
        if (class_exists(\Normalizer::class)) {
            $s = \Normalizer::normalize($s, \Normalizer::FORM_KD) ?: $s;
        }
        $s = preg_replace('/\p{Mn}+/u', '', $s) ?? $s;
        $s = str_replace('&', ' and ', $s);
        // A hyphen or apostrophe inside a word joins it: "e-bike", "child's".
        $s = preg_replace("/(\w)[-'\x{2019}](\w)/u", '$1$2', $s) ?? $s;
        $s = trim(preg_replace('/[^a-z0-9]+/', ' ', $s) ?? $s);
        if ($s === '') {
            return '';
        }

        return implode(' ', array_map([self::class, 'singular'], explode(' ', $s)));
    }

    private static function singular(string $w): string
    {
        if (strlen($w) <= 3 || in_array($w, self::PLURAL_ONLY, true)) {
            return $w;
        }
        if (isset(self::IRREGULAR[$w])) {
            return self::IRREGULAR[$w];
        }
        if (strlen($w) > 4 && str_ends_with($w, 'ies')) {
            return substr($w, 0, -3).'y';
        }
        if (preg_match('/(ss|us|is|ous)$/', $w)) {
            return $w;
        }
        if (preg_match('/(ches|shes|xes|sses|zzes)$/', $w)) {
            return substr($w, 0, -2);
        }
        if (str_ends_with($w, 's')) {
            return substr($w, 0, -1);
        }

        return $w;
    }
}
