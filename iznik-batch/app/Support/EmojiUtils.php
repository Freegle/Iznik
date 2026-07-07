<?php

namespace App\Support;

/**
 * Utility class for handling emoji encoding/decoding.
 *
 * Emojis are stored in the database in an escaped format (\u{codepoints}\u)
 * to ensure compatibility with older database configurations. This class
 * provides methods to decode them back to actual emoji characters for display.
 */
class EmojiUtils
{
    /**
     * Decode emoji escape sequences to actual emoji characters.
     *
     * The escape format is \u{codepoints}\u where codepoints are hex values
     * separated by hyphens. For example:
     * - \u1f600\u -> 😀
     * - \u1f1ec-1f1e7\u -> 🇬🇧
     *
     * @param string|null $str The string containing emoji escape sequences
     * @return string|null The string with actual emoji characters
     */
    public static function decodeEmojis(?string $str): ?string
    {
        if ($str === null || $str === '') {
            return $str;
        }

        return preg_replace_callback(
            '/\\\\\\\\u(.*?)\\\\\\\\u/',
            function ($matches) {
                $codePoints = explode('-', $matches[1]);
                $emoji = '';
                foreach ($codePoints as $codePoint) {
                    // Convert hex code point to integer and then to Unicode character.
                    $intCodePoint = hexdec($codePoint);
                    if ($intCodePoint > 0) {
                        $emoji .= mb_chr($intCodePoint, 'UTF-8');
                    }
                }
                return $emoji;
            },
            $str
        );
    }
}
