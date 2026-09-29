<?php

namespace App\Services;

use Normalizer;

/**
 * Folds styled or obfuscated text to a plain form before concern-keyword matching.
 *
 * Scam mail dodges keyword filters by writing the same letters differently:
 * mathematical-bold alphabets, fullwidth forms, zero-width characters between
 * letters, look-alike letters from other scripts, "dot" spelled out, letters
 * spaced apart. Both the text and the keyword go through this, so a plain ASCII
 * keyword matches every one of those spellings and the keyword list does not
 * have to enumerate them.
 *
 * Steps, in order:
 *
 * 1. NFKC: folds mathematical alphanumerics (bold, italic, script, fraktur,
 *    double-struck, sans, monospace), fullwidth forms, enclosed and circled
 *    letters, superscripts and ligatures to their plain letters.
 * 2. Strip format and invisible characters (\p{Cf}: zero-width space, joiner
 *    and non-joiner, word joiner, byte order mark, soft hyphen, invisible
 *    separator, bidi overrides) and combining marks (\p{Mn}). In ordinary chat
 *    these occur only inside emoji sequences and phone signatures.
 * 3. Fold dot look-alikes to '.': ideographic full stop, middle dots, bullets,
 *    "[.]" and "(.)", and " dot " spelled out between letters.
 * 4. Fold Latin look-alikes from Cyrillic, Greek and the small-capital blocks
 *    to ASCII.
 * 5. Lower-case.
 *
 * skeleton() goes one step further and drops every non-alphanumeric, for the
 * second pass a long block keyword gets against text with its letters spaced
 * or punctuated apart.
 */
final class KeywordTextNormalizer
{
    /**
     * Look-alikes NFKC does not fold. Cyrillic and Greek letters that render the
     * same as a Latin letter, and the small-capital letters (Phonetic Extensions
     * and IPA blocks). Upper-case forms are listed too because folding runs
     * before lower-casing.
     */
    private const CONFUSABLES = [
        // Cyrillic
        "\u{0430}" => 'a', "\u{0410}" => 'A',
        "\u{0435}" => 'e', "\u{0415}" => 'E',
        "\u{043E}" => 'o', "\u{041E}" => 'O',
        "\u{0440}" => 'p', "\u{0420}" => 'P',
        "\u{0441}" => 'c', "\u{0421}" => 'C',
        "\u{0445}" => 'x', "\u{0425}" => 'X',
        "\u{0443}" => 'y', "\u{0423}" => 'Y',
        "\u{0456}" => 'i', "\u{0406}" => 'I',
        "\u{0458}" => 'j', "\u{0408}" => 'J',
        "\u{0455}" => 's', "\u{0405}" => 'S',
        "\u{04BB}" => 'h', "\u{04BA}" => 'H',
        "\u{0501}" => 'd', "\u{0500}" => 'D',
        "\u{051D}" => 'w', "\u{051C}" => 'W',
        // Greek
        "\u{03BF}" => 'o', "\u{039F}" => 'O',
        "\u{03B1}" => 'a', "\u{0391}" => 'A',
        "\u{03BD}" => 'v', "\u{039D}" => 'N',
        "\u{03C1}" => 'p', "\u{03A1}" => 'P',
        "\u{03C4}" => 't', "\u{03A4}" => 'T',
        "\u{03B9}" => 'i', "\u{0399}" => 'I',
        "\u{03BA}" => 'k', "\u{039A}" => 'K',
        // Small capitals
        "\u{1D00}" => 'a', "\u{0299}" => 'b', "\u{1D04}" => 'c', "\u{1D05}" => 'd',
        "\u{1D07}" => 'e', "\u{A730}" => 'f', "\u{0262}" => 'g', "\u{029C}" => 'h',
        "\u{026A}" => 'i', "\u{1D0A}" => 'j', "\u{1D0B}" => 'k', "\u{029F}" => 'l',
        "\u{1D0D}" => 'm', "\u{0274}" => 'n', "\u{1D0F}" => 'o', "\u{1D18}" => 'p',
        "\u{A7AF}" => 'q', "\u{0280}" => 'r', "\u{A731}" => 's', "\u{1D1B}" => 't',
        "\u{1D1C}" => 'u', "\u{1D20}" => 'v', "\u{1D21}" => 'w', "\u{028F}" => 'y',
        "\u{1D22}" => 'z',
    ];

    /** Characters that stand in for '.' in an obfuscated domain. */
    private const DOT_LOOKALIKES = [
        "\u{3002}" => '.', // ideographic full stop
        "\u{2024}" => '.', // one dot leader
        "\u{00B7}" => '.', // middle dot
        "\u{2219}" => '.', // bullet operator
        "\u{2022}" => '.', // bullet
        "\u{30FB}" => '.', // katakana middle dot
        "\u{FF0E}" => '.', // fullwidth full stop
    ];

    public static function normalize(string $text): string
    {
        if ($text === '') {
            return '';
        }

        if (class_exists(Normalizer::class)) {
            $folded = Normalizer::normalize($text, Normalizer::FORM_KC);
            if ($folded !== false) {
                $text = $folded;
            }
        }

        $stripped = preg_replace('/[\p{Cf}\p{Mn}]+/u', '', $text);
        if ($stripped === null) {
            // Invalid UTF-8: keep the text; the caller's byte-wise fallback applies.
            return mb_strtolower($text);
        }
        $text = $stripped;

        $text = strtr($text, self::DOT_LOOKALIKES);
        $text = (string) preg_replace('/(?<=[\p{L}\p{N}])\s*[\[({]\s*\.\s*[\])}]\s*(?=[\p{L}\p{N}])/u', '.', $text);
        $text = (string) preg_replace('/(?<=[\p{L}\p{N}]) +dot +(?=[\p{L}\p{N}])/iu', '.', $text);

        $text = strtr($text, self::CONFUSABLES);

        return mb_strtolower($text);
    }

    /**
     * The normalised text with everything but letters and digits removed.
     */
    public static function skeleton(string $text): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', self::normalize($text));
    }
}
