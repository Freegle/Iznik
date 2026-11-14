<?php

namespace Tests\Unit\Promise;

use App\Services\Promise\KeywordBaseline;
use PHPUnit\Framework\TestCase;

class KeywordBaselineTest extends TestCase
{
    private KeywordBaseline $baseline;

    protected function setUp(): void
    {
        $this->baseline = new KeywordBaseline();
    }

    public function test_positive_examples(): void
    {
        $positiveSpans = [
            "i'll take it",
            "yes please",
            "you can have it",
            "it's yours",
            "see you thursday",
            "[GIVER] yes you can have it [TAKER] thanks",
            "amazing thank you 🙏 it's <PHONE>",
            "brilliant, collecting tomorrow 😀",
            "[GIVER] my address is <ADDRESS> [TAKER] fantastic",
            "[GIVER] i can drop it round, what's your number?",
            "i'll collect thursday",
            "i can collect saturday",
        ];

        foreach ($positiveSpans as $span) {
            $pred = $this->baseline->predict($span);
            $this->assertEquals(1, $pred, "Should predict 1 for: $span");
        }
    }

    public function test_negative_examples(): void
    {
        $negativeSpans = [
            "is this still available?",
            "what is it like?",
            "thanks for the offer",
            "no worries, thanks for letting me know",
            "[TAKER] hi is this still available please?",
            "[GIVER] sorry it's already been taken",
            "[TAKER] thanks but i'm sorted now",
            "do you have any more?",
        ];

        foreach ($negativeSpans as $span) {
            $pred = $this->baseline->predict($span);
            $this->assertEquals(0, $pred, "Should predict 0 for: $span");
        }
    }

    public function test_handles_case_insensitivity(): void
    {
        // "I'LL TAKE IT" should match same as "i'll take it"
        $pred1 = $this->baseline->predict("i'll take it");
        $pred2 = $this->baseline->predict("I'LL TAKE IT");

        $this->assertEquals($pred1, $pred2);
    }

    public function test_handles_punctuation(): void
    {
        // Variations of the same promise signal
        $pred1 = $this->baseline->predict("ill take it");
        $pred2 = $this->baseline->predict("i'll take it");
        $pred3 = $this->baseline->predict("i'll take it!");

        // All should be positive
        $this->assertEquals(1, $pred1);
        $this->assertEquals(1, $pred2);
        $this->assertEquals(1, $pred3);
    }

    public function test_with_pii_tokens(): void
    {
        // Presence of address/postcode/phone is a strong signal
        $pred1 = $this->baseline->predict("my address is <ADDRESS>");
        $pred2 = $this->baseline->predict("collect at <ADDRESS> <POSTCODE>");
        $pred3 = $this->baseline->predict("my number is <PHONE>");

        $this->assertEquals(1, $pred1);
        $this->assertEquals(1, $pred2);
        $this->assertEquals(1, $pred3);
    }

    public function test_with_emoji(): void
    {
        $pred1 = $this->baseline->predict("thank you so much! 😊");
        $pred2 = $this->baseline->predict("yes please 🙏");

        // Emoji alone doesn't make it positive, but combined with promise language does
        $this->assertEquals(1, $pred1);
        $this->assertEquals(1, $pred2);
    }

    public function test_address_without_promise_language_ambiguous(): void
    {
        // An address alone might be informational, not confirmatory
        // But in typical usage it's paired with agreement
        $pred = $this->baseline->predict("<ADDRESS> <POSTCODE>");

        // This is ambiguous; the baseline should catch it as (weak) positive
        // due to the PII being present
        $this->assertEquals(1, $pred);
    }

    public function test_time_day_agreement(): void
    {
        $pred1 = $this->baseline->predict("see you thursday");
        $pred2 = $this->baseline->predict("collect saturday morning");
        $pred3 = $this->baseline->predict("i can come friday at 3");

        $this->assertEquals(1, $pred1);
        $this->assertEquals(1, $pred2);
        $this->assertEquals(1, $pred3);
    }

    public function test_consistency(): void
    {
        // Same span should always predict the same
        $span = "yes you can have it";
        $pred1 = $this->baseline->predict($span);
        $pred2 = $this->baseline->predict($span);

        $this->assertEquals($pred1, $pred2);
    }
}
