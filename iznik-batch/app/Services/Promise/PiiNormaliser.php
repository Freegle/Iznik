<?php

namespace App\Services\Promise;

class PiiNormaliser
{
    /**
     * Street suffixes that are safe to match when the street name is lower case. The
     * ambiguous ones (Drive, Close, Way, Walk, Park, Green, Hill, Row, Place) only match
     * after a capitalised name, so "20 min drive" or "5 minutes walk" stay as text.
     */
    private const SAFE_SUFFIX = 'Street|St|Road|Rd|Lane|Ln|Avenue|Ave|Crescent|Cres|Gardens|Gdns'
        . '|Terrace|Mews|Boulevard|Broadway';

    private const ALL_SUFFIX = 'Street|St|Road|Rd|Lane|Ln|Avenue|Ave|Close|Drive|Dr|Way|Court|Ct'
        . '|Crescent|Cres|Place|Pl|Gardens|Gdns|Terrace|Grove|Walk|Row|Square|Sq|Mews'
        . '|Parade|Rise|Park|Green|Hill|Vale|Croft|Wharf|Quay|Gate|Yard|Boulevard|Broadway';

    /** Words that can follow a greeting without being a name ("Hi there", "Hello again"). */
    private const NOT_A_NAME = 'There|All|Everyone|Everybody|Again|Are|Is|Was|Can|Could|Would|Do|Did|Does'
        . '|Thanks|Thank|Just|Still|Sorry|Yes|No|Please|Ok|Okay|Sure|We|You|It|The|This|That'
        . '|My|Our|Hope|How|Hows|What|When|Where|Why|Who|If|Im|Ive|Id|Its|Have|Has|Will|Not';

    /**
     * Normalise PII in text, replacing with placeholder tokens.
     *
     * Replaces:
     * - Emails → <EMAIL>
     * - URLs (http/https, www., domain with a path, trashnothing) → <URL>
     * - UK postcodes → <POSTCODE>
     * - Phone numbers (07…, +44…, 11-digit) → <PHONE>
     * - Street addresses and what3words triples → <ADDRESS>
     * - A name after a greeting or a title ("Hi Sarah", "Mrs Patel") → <NAME>
     *
     * Names elsewhere in the text are NOT removed: a regex cannot tell a name from a word.
     * The output is still member chat text and belongs on controlled hosts only.
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

        // Full http(s) links first: they can contain an @ or a path that looks like an email.
        $text = preg_replace('/https?:\/\/\S+/i', '<URL>', $text);

        // Emails (basic pattern). Before the bare-domain links below, so a relay address on
        // trashnothing.com is replaced whole and its local part does not survive.
        $text = preg_replace(
            '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/',
            '<EMAIL>',
            $text
        );

        // Links without a scheme: www.… , trashnothing.com… , and a domain followed by a path.
        $text = preg_replace(
            '/(?:\bwww\.\S+|(?<![@.\w])trashnothing\.com\S*|(?<![@.\w])(?:[a-z0-9-]+\.)+(?:com|co\.uk|org\.uk|org|net|uk|io|me)\/\S*)/i',
            '<URL>',
            $text
        );

        // what3words: ///filled.count.soap
        $text = preg_replace('/\/{3}\w+\.\w+\.\w+/u', '<ADDRESS>', $text);

        // Replace UK postcodes (simplified pattern: letter(s), number(s), space, number, letter, letter)
        // Covers formats like SW1A 1AA, M1 1AE, B33 8TH, CR2 6XH, etc.
        $text = preg_replace(
            '/\b[A-Z]{1,2}\d[A-Z\d]?\s?\d[A-Z]{2}\b/i',
            '<POSTCODE>',
            $text
        );

        // Replace phone numbers
        // Pattern 1: UK mobiles, 07xxx then six digits grouped any way ("07123 456 789",
        //            "07700-900-123", "07700 900000").
        // Pattern 2: +44 ... (international format)
        // Pattern 3: 11-digit UK numbers (02, 03, etc.)
        $text = preg_replace(
            '/(?<!\d)(?:0|\+44)\s?7\d{3}[\s-]?\d{3}[\s-]?\d{3}(?!\d)/',
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

        // Replace street addresses.
        // A bare "number + word" (e.g. "6 pm", "3 chairs", "2 days") is NOT an
        // address — that over-matched and injected fake <ADDRESS> tokens (a strong
        // promise signal) into times/quantities. Require a real street suffix.
        // Pattern 1: "12 High Street", "45 Elm Road", "10 Downing Street": capitalised
        //            street name, any suffix, suffix in any case.
        $text = preg_replace(
            '/\b\d{1,4}[a-z]?\s+[A-Z][a-z]+(?:\s+[A-Z][a-z]+){0,2}\s+(?i:' . self::ALL_SUFFIX . ')\b/',
            '<ADDRESS>',
            $text
        );
        // Pattern 2: "12 elm road": lower-case street name, one name word, and only a suffix
        //            that is not also an everyday word.
        $text = preg_replace(
            '/\b\d{1,4}[a-z]?\s+[a-z]+\s+(?i:' . self::SAFE_SUFFIX . ')\b/',
            '<ADDRESS>',
            $text
        );
        // Pattern 3: "Flat 3, Oakwood Lane": sub-dwelling prefix + number + capitalised name
        $text = preg_replace(
            '/\b(?i:Flat|Apartment|Apt|Suite|Unit|House)\s+\d+[a-z]?\s*,?\s*[A-Z][a-z]+(?:\s+[A-Z][a-z]+)*\b/',
            '<ADDRESS>',
            $text
        );

        // A name straight after a greeting or a title. A greeting only masks the next word
        // when it is not an everyday word, so "Hi there" and "Hello again" are kept.
        $text = preg_replace(
            '/\b(Hi|Hello|Hey|Dear)\s+(?!(?:' . self::NOT_A_NAME . ')\b)[A-Z][a-z]+(?:\s+[A-Z][a-z]+)?\b/',
            '$1 <NAME>',
            $text
        );
        $text = preg_replace(
            '/\b(?:Mr|Mrs|Ms|Miss|Mx|Dr)\.?\s+[A-Z][a-z]+\b/',
            '<NAME>',
            $text
        );

        // Guarantee valid UTF-8: drop malformed sequences
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

        return $text;
    }
}
