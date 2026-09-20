<?php

namespace App\Services\CommunityNews;

/**
 * Deterministic backstop against mixed-language intros.
 *
 * Welsh-named areas (Caerdydd, Wrecsam, Pen-y-Bont ar Ogwr...) coax the
 * research model into opening an otherwise-English intro with a token Welsh
 * greeting - "Croeso i mid August", "Shwmae, Caernarfon!" - which reads as
 * tokenism, not warmth (12 of 239 live areas on 2026-08-17). The prompt now
 * forbids it, but like the em-dash rule this is enforced in code too so it
 * holds however the model phrases things.
 *
 * Scope is deliberately tight: only sentence-INITIAL greetings are stripped,
 * and only from intros. A greeting word mid-sentence ("we say croeso to the
 * new library") and Welsh NAMES of places or events in items ("Croeso i
 * Gaerdydd festival") are legitimate and untouched.
 */
final class IntroLanguage
{
    /**
     * Greetings the model bolts onto intros, sentence-initially, in Welsh,
     * Scottish Gaelic, Irish, Cornish and Manx. Lower-case; matched
     * case-insensitively on a word boundary.
     */
    private const GREETINGS = [
        // Welsh
        'croeso', 'shwmae', 'shw mae', "s'mae", "su'mae", 'sut mae', 'bore da',
        'prynhawn da', 'noswaith dda', 'helo bawb', 'henffych', 'hwyl fawr',
        'hwyl', 'diolch yn fawr', 'diolch', 'haia',
        // Scottish Gaelic
        'fàilte', 'failte', 'madainn mhath', 'feasgar math',
        // Irish
        'fáilte', 'céad míle fáilte', 'dia dhuit', 'dia daoibh',
        // Cornish and Manx
        'dydh da', 'myttin da', 'moghrey mie', 'fastyr mie',
    ];

    /**
     * The intro with any leading non-English greeting sentence(s) removed.
     * An intro that was nothing but a greeting comes back as ''.
     */
    public static function stripForeignGreeting(string $intro): string
    {
        $alternation = implode('|', array_map(
            fn ($g) => preg_quote($g, '/'),
            self::GREETINGS
        ));
        // From the start (quotes allowed), a greeting on a word boundary, then
        // the rest of that sentence up to and including its end punctuation.
        $pattern = '/^["\'\x{201C}\x{201D}\x{2018}\x{2019}]*\s*(?:' . $alternation . ')\b[^.!?]*[.!?\x{2026}]*\s*/iu';

        $text = trim($intro);
        // Loop: the model sometimes stacks greetings ("Shwmae! Croeso i...").
        for ($i = 0; $i < 5; $i++) {
            $stripped = preg_replace($pattern, '', $text, 1);
            if ($stripped === null || $stripped === $text) {
                break;
            }
            $text = $stripped;
        }

        return trim($text);
    }
}
