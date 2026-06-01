<?php

namespace App\Services\Promise;

class PiiNormaliser
{
    /**
     * Normalise PII in text, replacing with placeholder tokens.
     *
     * Replaces:
     * - UK postcodes → <POSTCODE>
     * - Phone numbers (07…, +44…, 11-digit) → <PHONE>
     * - Emails → <EMAIL>
     * - URLs (http/https/trashnothing) → <URL>
     * - Street addresses → <ADDRESS>
     *
     * Preserves emoji and accented characters. Guarantees valid UTF-8 output.
     * Collapses \r\n\t and runs of whitespace to single spaces.
     */
    public function normalise(string $text): string
    {
        // Collapse \r, \n, \t to spaces
        $text = str_replace(["\r", "\n", "\t"], ' ', $text);

        // Collapse multiple spaces to single space
        $text = preg_replace('/\s+/', ' ', $text);

        // Trim leading/trailing whitespace
        $text = trim($text);

        // Replace URLs (must come before other replacements that might contain URLs)
        // Match http://, https://, or trashnothing.com
        $text = preg_replace(
            '/(?:https?:\/\/\S+|trashnothing\.com\S*)/i',
            '<URL>',
            $text
        );

        // Replace emails (basic pattern)
        $text = preg_replace(
            '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/',
            '<EMAIL>',
            $text
        );

        // Replace UK postcodes (simplified pattern: letter(s), number(s), space, number, letter, letter)
        // Covers formats like SW1A 1AA, M1 1AE, B33 8TH, CR2 6XH, etc.
        $text = preg_replace(
            '/\b[A-Z]{1,2}\d[A-Z\d]?\s?\d[A-Z]{2}\b/i',
            '<POSTCODE>',
            $text
        );

        // Replace phone numbers
        // Pattern 1: 07... (10 digits, may have spaces/dashes)
        // Pattern 2: +44 ... (international format)
        // Pattern 3: 11-digit UK numbers (02, 03, etc.)
        $text = preg_replace(
            '/\b(?:0|\+44)\s?7\d{3}(?:\s|\-)?(?:\d{3}|\d{6})\b/',
            '<PHONE>',
            $text
        );
        $text = preg_replace(
            '/\+44\s?\d{1,5}\s?\d{1,5}\s?\d{1,5}/',
            '<PHONE>',
            $text
        );
        $text = preg_replace(
            '/\b(?:0[1-3]|0[4-8]|0[9])\d{1,5}\s?\d{1,5}\s?\d{1,5}\b/',
            '<PHONE>',
            $text
        );

        // Replace street addresses
        // Pattern 1: "Number Street" (e.g., "12 High Street")
        // Pattern 2: "Flat/Apartment N, ..." (e.g., "Flat 3, Oakwood Lane")
        $text = preg_replace(
            '/\b\d{1,4}\s+[A-Z][a-z]+(?:\s+[A-Z][a-z]+)*\b/i',
            '<ADDRESS>',
            $text
        );
        $text = preg_replace(
            '/\b(?:Flat|Apartment|Apt|Suite|Unit|House)\s+\d+\s*,?\s*[A-Z][a-z]+(?:\s+[A-Z][a-z]+)*\b/i',
            '<ADDRESS>',
            $text
        );

        // Guarantee valid UTF-8: drop malformed sequences
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

        return $text;
    }
}
