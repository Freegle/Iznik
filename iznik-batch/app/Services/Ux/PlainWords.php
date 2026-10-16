<?php

namespace App\Services\Ux;

/**
 * The reading grade of a short text, by the rule .claude/pr-complexity.mjs applies to pull request
 * text: 0.39 * words + 11.8 * syllables per word - 15.59, for one sentence. The syllable count is
 * that file's syllables() function ported line for line: vowel groups, minus one when the word
 * ends in "e" but not in "le", "ee" or "ye" and has more than one group, and never less than one.
 *
 * The count is crude. It reads "every" as three syllables, and a short phrase of long words scores
 * high: "Notifications arrive late" is 17.05 and "Messages arrive late" is 9.18. So the grade of a
 * problem title is advice for the person who checks the titles, never a limit (UxPromptService
 * prints it beside each title). fits() is for the wording that is fixed in code, where a limit is
 * right. docs/developers/reference/ux-member-surveys.md.
 */
final class PlainWords
{
    /** The words of a text: what pr-complexity.mjs counts as a word. */
    private const WORD = "/[A-Za-z][A-Za-z'-]*/";

    /** The reading grade of one sentence; 0 for a text with no words. */
    public static function grade(string $sentence): float
    {
        preg_match_all(self::WORD, $sentence, $matches);
        $words = $matches[0];
        if (! $words) {
            return 0.0;
        }
        $syllables = array_sum(array_map(self::syllables(...), $words));

        return 0.39 * count($words) + 11.8 * ($syllables / count($words)) - 15.59;
    }

    /** Whether the text reads at $maxGrade or lower. */
    public static function fits(string $text, int $maxGrade): bool
    {
        return self::grade($text) <= $maxGrade;
    }

    private static function syllables(string $word): int
    {
        $w = preg_replace('/^[\'"]+|[\'"]+$/', '', strtolower($word)) ?? '';
        if ($w === '') {
            return 0;
        }
        $groups = (int) preg_match_all('/[aeiouy]+/', $w);
        if (str_ends_with($w, 'e') && ! preg_match('/(?:le|ee|ye)$/', $w) && $groups > 1) {
            $groups--;
        }

        return max(1, $groups);
    }
}
