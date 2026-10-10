<?php

namespace App\Services\Promise;

use Rubix\ML\Tokenizers\Tokenizer;

/**
 * Combined word n-gram (1-2) and character n-gram (3-5) tokenizer.
 *
 * Produces both word unigrams/bigrams and character trigrams/4-grams/5-grams,
 * with character n-grams prefixed with 'c:' to avoid collision.
 *
 * Multibyte-safe: uses mb_strtolower, preg_split with /u flag for UTF-8 chars.
 */
class CharNgramTokenizer implements Tokenizer
{
    /**
     * Tokenize text into word n-grams (1-2) and character n-grams (3-5).
     *
     * @param string $text
     * @return list<string>
     */
    public function tokenize(string $text): array
    {
        $text = mb_strtolower($text);

        $tokens = [];

        // Word n-grams (1-2)
        $tokens = array_merge($tokens, $this->wordNGrams($text));

        // Character n-grams (3-5), prefixed with 'c:'
        $tokens = array_merge($tokens, $this->charNGrams($text));

        return array_unique($tokens);
    }

    /**
     * Extract word unigrams and bigrams.
     *
     * @param string $text
     * @return list<string>
     */
    private function wordNGrams(string $text): array
    {
        // Split on whitespace and punctuation, preserving words
        $words = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        $words = array_filter($words, fn($w) => strlen($w) > 0);
        $words = array_values($words); // Re-index after filter

        $tokens = [];

        // Unigrams
        foreach ($words as $word) {
            $tokens[] = $word;
        }

        // Bigrams
        for ($i = 0; $i < count($words) - 1; $i++) {
            $tokens[] = $words[$i] . ' ' . $words[$i + 1];
        }

        return $tokens;
    }

    /**
     * Extract character n-grams (3-5), prefixed with 'c:'.
     *
     * Uses multibyte-safe character splitting via preg_split with /u flag.
     *
     * @param string $text
     * @return list<string>
     */
    private function charNGrams(string $text): array
    {
        // Remove spaces for character-level n-grams
        $text = str_replace(' ', '', $text);

        // Split into individual characters (multibyte-safe)
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);

        $tokens = [];

        // 3-grams, 4-grams, 5-grams
        foreach ([3, 4, 5] as $n) {
            for ($i = 0; $i <= count($chars) - $n; $i++) {
                $gram = implode('', array_slice($chars, $i, $n));
                $tokens[] = 'c:' . $gram;
            }
        }

        return $tokens;
    }

    /**
     * String representation for Stringable interface.
     *
     * @return string
     */
    public function __toString(): string
    {
        return self::class;
    }
}
