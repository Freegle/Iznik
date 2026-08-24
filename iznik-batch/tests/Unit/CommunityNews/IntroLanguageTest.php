<?php

namespace Tests\Unit\CommunityNews;

use App\Services\CommunityNews\IntroLanguage;
use PHPUnit\Framework\TestCase;

class IntroLanguageTest extends TestCase
{
    /**
     * The observed production failure mode: a token Welsh greeting bolted onto
     * an otherwise-English intro. The greeting sentence goes; the English part
     * stays.
     */
    public function test_strips_a_leading_welsh_greeting_sentence(): void
    {
        $cases = [
            'Shwmae, Caernarfon! The castle is busier than ever this fortnight.'
                => 'The castle is busier than ever this fortnight.',
            'Bore da, Cwmbran! The schools are still off and the farm is full of falconry.'
                => 'The schools are still off and the farm is full of falconry.',
            'Croeso to your Port Talbot round-up! The seafront is still in full summer swing.'
                => 'The seafront is still in full summer swing.',
            'Croeso i mid August, Wrecsam! The balloons are inflating.'
                => 'The balloons are inflating.',
            'Prynhawn da, Merthyr! Plenty to tempt you out this week.'
                => 'Plenty to tempt you out this week.',
        ];

        foreach ($cases as $in => $want) {
            $this->assertSame($want, IntroLanguage::stripForeignGreeting($in), "for: {$in}");
        }
    }

    public function test_strips_consecutive_greeting_sentences(): void
    {
        $this->assertSame(
            'Here is what your neighbours are up to.',
            IntroLanguage::stripForeignGreeting('Shwmae! Croeso i Wrecsam! Here is what your neighbours are up to.')
        );
    }

    public function test_is_case_insensitive(): void
    {
        $this->assertSame(
            'The market is back.',
            IntroLanguage::stripForeignGreeting('SHWMAE, LLANGEFNI! The market is back.')
        );
    }

    public function test_strips_gaelic_greetings_too(): void
    {
        $this->assertSame(
            'The festival returns to the glen.',
            IntroLanguage::stripForeignGreeting('Fàilte! The festival returns to the glen.')
        );
    }

    /** A greeting with no closing punctuation swallows to the end of the text. */
    public function test_an_intro_that_is_only_a_greeting_comes_back_empty(): void
    {
        $this->assertSame('', IntroLanguage::stripForeignGreeting('Croeso i mid August'));
        $this->assertSame('', IntroLanguage::stripForeignGreeting('Shwmae, Caernarfon!'));
    }

    public function test_plain_english_intros_pass_through_untouched(): void
    {
        $cases = [
            'A gentle armful of free days out and fresh-veg markets to see you through.',
            // A Welsh proper noun mid-sentence is a name, not a greeting.
            'There is a warm welcome waiting at the Eisteddfod this week.',
            // A greeting word that is not sentence-initial stays: only the
            // bolted-on opening is the failure mode this guards against.
            'We say croeso to the newly reopened library.',
            // "Croesofield" contains "croeso" but is not the word itself.
            'Croesofield Church holds its summer fete on Saturday.',
        ];

        foreach ($cases as $intro) {
            $this->assertSame($intro, IntroLanguage::stripForeignGreeting($intro), "for: {$intro}");
        }
    }

    public function test_empty_input_stays_empty(): void
    {
        $this->assertSame('', IntroLanguage::stripForeignGreeting(''));
        $this->assertSame('', IntroLanguage::stripForeignGreeting('   '));
    }
}
