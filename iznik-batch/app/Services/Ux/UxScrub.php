<?php

namespace App\Services\Ux;

/**
 * Takes the obviously personal parts out of free text before it leaves the building for a model:
 * email addresses, phone numbers and other long digit runs, and UK postcodes. It is a net with
 * holes (a name or a street cannot be found by pattern), which is why member text is also sent
 * with no tools and why the idea generator only ever sees themes, never the words.
 */
final class UxScrub
{
    public static function text(string $text, int $max = 0): string
    {
        $text = preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/u', '[email]', $text) ?? $text;
        $text = preg_replace('/\b[A-Z]{1,2}\d[A-Z\d]?\s*\d[A-Z]{2}\b/i', '[postcode]', $text) ?? $text;
        $text = preg_replace_callback('/\+?\d[\d\s().\-]{5,}\d/u', function (array $m): string {
            $digits = preg_match_all('/\d/', $m[0]);
            // A date such as 2026-10-14 has eight digits and is not a phone number.
            if ($digits < 7 || preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($m[0]))) {
                return $m[0];
            }

            return '[number]';
        }, $text) ?? $text;
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return $max > 0 ? mb_substr($text, 0, $max) : $text;
    }
}
