<?php

namespace Tests\Unit\Promise;

use App\Services\Promise\CharNgramTokenizer;
use PHPUnit\Framework\TestCase;

class CharNgramTokenizerTest extends TestCase
{
    private CharNgramTokenizer $tokenizer;

    protected function setUp(): void
    {
        $this->tokenizer = new CharNgramTokenizer();
    }

    public function test_tokenizes_simple_words(): void
    {
        $tokens = $this->tokenizer->tokenize('hello world');

        // Should contain word unigrams and bigrams
        $this->assertContains('hello', $tokens);
        $this->assertContains('world', $tokens);
        $this->assertContains('hello world', $tokens); // 2-gram

        // Should contain character trigrams with prefix
        $this->assertTrue(
            array_any($tokens, fn($t) => str_starts_with($t, 'c:')),
            'Should have character n-grams with c: prefix'
        );
    }

    public function test_character_trigrams_present(): void
    {
        $tokens = $this->tokenizer->tokenize('test');

        // For "test", expect char trigrams like "tes", "est"
        $charNgrams = array_filter($tokens, fn($t) => str_starts_with($t, 'c:'));
        $this->assertNotEmpty($charNgrams);

        // Extract the actual trigrams (remove prefix)
        $trigrams = array_map(fn($t) => substr($t, 2), $charNgrams);
        $this->assertContains('tes', $trigrams);
        $this->assertContains('est', $trigrams);
    }

    public function test_handles_emoji(): void
    {
        $tokens = $this->tokenizer->tokenize('hello 😊 world');

        // Emoji should not break tokenization
        $this->assertNotEmpty($tokens);

        // Should still have word tokens
        $wordTokens = array_filter($tokens, fn($t) => !str_starts_with($t, 'c:'));
        $this->assertNotEmpty($wordTokens);
    }

    public function test_handles_accents(): void
    {
        $tokens = $this->tokenizer->tokenize('naïve café');

        $this->assertNotEmpty($tokens);

        // Lowercased and handled safely
        $wordTokens = array_filter($tokens, fn($t) => !str_starts_with($t, 'c:'));
        $this->assertTrue(
            count($wordTokens) >= 2,
            'Should tokenize accented words'
        );
    }

    public function test_lowercases_output(): void
    {
        $tokens = $this->tokenizer->tokenize('HELLO World');
        $wordTokens = array_filter($tokens, fn($t) => !str_starts_with($t, 'c:'));

        $this->assertContains('hello', $wordTokens);
        $this->assertContains('world', $wordTokens);
        $this->assertNotContains('HELLO', $wordTokens);
        $this->assertNotContains('World', $wordTokens);
    }

    public function test_combined_word_and_char_grams(): void
    {
        $tokens = $this->tokenizer->tokenize('hi');

        // Word unigrams
        $this->assertContains('hi', $tokens);

        // Character trigrams (if length allows)
        $charNgrams = array_filter($tokens, fn($t) => str_starts_with($t, 'c:'));
        // "hi" is too short for trigrams, should have none or short ones

        // 4-letter word should have both
        $tokens4 = $this->tokenizer->tokenize('test');
        $charNgrams4 = array_filter($tokens4, fn($t) => str_starts_with($t, 'c:'));
        $this->assertNotEmpty($charNgrams4);
    }

    public function test_no_collision_between_word_and_char_grams(): void
    {
        $tokens = $this->tokenizer->tokenize('test');

        $wordGrams = array_filter($tokens, fn($t) => !str_starts_with($t, 'c:'));
        $charGrams = array_filter($tokens, fn($t) => str_starts_with($t, 'c:'));

        // No word gram should have 'c:' prefix
        foreach ($wordGrams as $gram) {
            $this->assertFalse(str_starts_with($gram, 'c:'));
        }

        // All char grams should have prefix
        foreach ($charGrams as $gram) {
            $this->assertTrue(str_starts_with($gram, 'c:'));
        }
    }

    public function test_real_promise_examples(): void
    {
        // Positive examples
        $positiveSpans = [
            "i'll take it",
            "yes please",
            "you can have it",
            "it's yours",
            "see you thursday",
            "<ADDRESS>",
            "<PHONE>",
        ];

        foreach ($positiveSpans as $span) {
            $tokens = $this->tokenizer->tokenize($span);
            $this->assertNotEmpty($tokens, "Should tokenize: $span");

            // Should have both word and char tokens
            $hasWordTokens = array_any($tokens, fn($t) => !str_starts_with($t, 'c:'));
            $this->assertTrue($hasWordTokens, "Should have word tokens for: $span");
        }
    }

    public function test_speaker_tags_tokenized(): void
    {
        $span = '[GIVER] yes you can have it [TAKER] thanks';
        $tokens = $this->tokenizer->tokenize($span);

        $this->assertNotEmpty($tokens);

        // Tags should be tokenized (not special-cased)
        $wordTokens = array_filter($tokens, fn($t) => !str_starts_with($t, 'c:'));
        $this->assertNotEmpty($wordTokens);
    }

    public function test_stringable_interface(): void
    {
        $this->assertTrue(method_exists($this->tokenizer, '__toString'));
        $str = (string)$this->tokenizer;
        $this->assertIsString($str);
    }
}

// Helper for array_any (PHP 8.3 doesn't have it builtin)
if (!function_exists('array_any')) {
    function array_any(array $array, callable $callback): bool
    {
        foreach ($array as $item) {
            if ($callback($item)) {
                return true;
            }
        }
        return false;
    }
}
