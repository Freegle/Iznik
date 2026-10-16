<?php

namespace Tests\Unit\Services;

use App\Services\Ux\PlainWords;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * PlainWords is a port of the scorer in .claude/pr-complexity.mjs. The grades below were worked out
 * once, by running that file's own syllables() and grade() functions on each text, and are written
 * in here. If the port drifts from the file, these stop agreeing with it.
 */
class PlainWordsTest extends TestCase
{
    /** @return array<string, array{0: string, 1: float}> */
    public static function gradesFromTheScorer(): array
    {
        return [
            'photo uploads' => ['Photo uploads keep failing on my phone', 4.00],
            'a short phrase of long words' => ['Notifications arrive late', 17.05],
            'posts' => ['Posts take too long to be approved', 2.31],
            'emails, with an "every" read as three syllables' => ['I get too many emails from Freegle every day', 6.28],
            'messages' => ['Messages arrive late', 9.18],
            'promising' => ['Promising an item to someone is confusing', 9.05],
            'moderators' => ['Moderators refuse my post with no reason', 7.37],
            'the open question about problems' => ['Did you have a problem the last time you used Freegle?', 3.72],
            'the area survey question' => ['Which part of Freegle works best for you?', 0.80],
            'the second step with its suffix' => ['Which other part most needs improving? (optional)', 7.37],
            'the second step without it' => ['Which other part most needs improving?', 4.45],
            'the longest awareness question' => ['Do you know how to get a message when somebody replies to your post?', 5.88],
        ];
    }

    #[DataProvider('gradesFromTheScorer')]
    public function test_the_grade_is_the_one_the_scorer_gives(string $text, float $expected): void
    {
        $this->assertEqualsWithDelta($expected, PlainWords::grade($text), 0.005);
    }

    public function test_the_four_grades_the_spec_pins(): void
    {
        $this->assertEquals(4.0, round(PlainWords::grade('Photo uploads keep failing on my phone'), 1));
        $this->assertEquals(17.05, round(PlainWords::grade('Notifications arrive late'), 2));
        $this->assertEquals(2.3, round(PlainWords::grade('Posts take too long to be approved'), 1));
        $this->assertEquals(6.3, round(PlainWords::grade('I get too many emails from Freegle every day'), 1));
    }

    public function test_a_silent_final_e_is_not_a_syllable_but_le_ee_and_ye_keep_theirs(): void
    {
        // One word is one sentence of one word: 0.39 + 11.8 * syllables - 15.59.
        $oneWord = fn (string $word) => round((PlainWords::grade($word) + 15.59 - 0.39) / 11.8);

        $this->assertSame(1.0, $oneWord('late'), 'a, e: two groups, the final e is silent');
        $this->assertSame(1.0, $oneWord('the'), 'a single group is never reduced');
        $this->assertSame(2.0, $oneWord('Freegle'), 'ee and e: ends in le, so both count');
        $this->assertSame(1.0, $oneWord('free'), 'ee is one group');
        $this->assertSame(2.0, $oneWord('goodbye'), 'oo and ye: ends in ye, so the final e counts');
        $this->assertSame(3.0, $oneWord('every'), 'the scorer reads e, e, y as three');
        $this->assertSame(1.0, $oneWord("don't"), 'an apostrophe is part of the word');
        $this->assertSame(1.0, $oneWord('why'), 'y is a vowel');
        $this->assertSame(1.0, $oneWord('nth'), 'a word with no vowel is still one syllable');
    }

    public function test_only_letters_apostrophes_and_hyphens_make_a_word(): void
    {
        $this->assertSame(0.0, PlainWords::grade(''));
        $this->assertSame(0.0, PlainWords::grade('12 3? !!'));
        $this->assertEqualsWithDelta(PlainWords::grade('Posts take too long'), PlainWords::grade('Posts, take: too (long) 12'), 1e-9);
        $this->assertEqualsWithDelta(PlainWords::grade('up-to-date'), PlainWords::grade('  up-to-date  '), 1e-9);
    }

    public function test_fits_is_true_at_the_limit_and_false_above_it(): void
    {
        $this->assertTrue(PlainWords::fits('Which part of Freegle works best for you?', 6));
        $this->assertTrue(PlainWords::fits('Do you know how to get a message when somebody replies to your post?', 6));
        $this->assertFalse(PlainWords::fits('Notifications arrive late', 6));
        $this->assertFalse(PlainWords::fits('Which other part most needs improving? (optional)', 6));
        $this->assertTrue(PlainWords::fits('Which other part most needs improving? (optional)', 8));
        $this->assertTrue(PlainWords::fits('', 0), 'no words is grade 0');
    }
}
