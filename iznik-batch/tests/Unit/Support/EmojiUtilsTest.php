<?php

namespace Tests\Unit\Support;

use App\Support\EmojiUtils;
use PHPUnit\Framework\TestCase;

class EmojiUtilsTest extends TestCase
{
    public function test_decode_null_returns_null(): void
    {
        $this->assertNull(EmojiUtils::decodeEmojis(null));
    }

    public function test_decode_empty_string_returns_empty_string(): void
    {
        $this->assertSame('', EmojiUtils::decodeEmojis(''));
    }

    public function test_decode_string_without_emojis_returns_unchanged(): void
    {
        $input = 'Hello, this is a test message!';
        $this->assertSame($input, EmojiUtils::decodeEmojis($input));
    }

    public function test_decode_single_emoji(): void
    {
        // \\u1f600\\u should decode to 😀 (double backslash as stored by frontend)
        $input = 'Hello \\\\u1f600\\\\u world';
        $expected = 'Hello 😀 world';
        $this->assertSame($expected, EmojiUtils::decodeEmojis($input));
    }

    public function test_decode_multiple_emojis(): void
    {
        // Multiple emojis in the same string
        $input = '\\\\u1f600\\\\u test \\\\u2764\\\\u';
        $expected = '😀 test ❤';
        $this->assertSame($expected, EmojiUtils::decodeEmojis($input));
    }

    public function test_decode_compound_emoji_with_skin_tone(): void
    {
        // 👍🏻 = 1f44d-1f3fb (thumbs up with light skin tone)
        $input = 'Good job \\\\u1f44d-1f3fb\\\\u';
        $expected = 'Good job 👍🏻';
        $this->assertSame($expected, EmojiUtils::decodeEmojis($input));
    }

    public function test_decode_flag_emoji(): void
    {
        // 🇬🇧 = 1f1ec-1f1e7 (UK flag)
        $input = 'From \\\\u1f1ec-1f1e7\\\\u';
        $expected = 'From 🇬🇧';
        $this->assertSame($expected, EmojiUtils::decodeEmojis($input));
    }

    public function test_decode_heart_with_variation_selector(): void
    {
        // ❤️ = 2764-fe0f (red heart with variation selector)
        $input = 'Love \\\\u2764-fe0f\\\\u';
        $expected = 'Love ❤️';
        $this->assertSame($expected, EmojiUtils::decodeEmojis($input));
    }

    public function test_decode_emoji_at_start_of_string(): void
    {
        $input = '\\\\u1f600\\\\u Hello';
        $expected = '😀 Hello';
        $this->assertSame($expected, EmojiUtils::decodeEmojis($input));
    }

    public function test_decode_emoji_at_end_of_string(): void
    {
        $input = 'Hello \\\\u1f600\\\\u';
        $expected = 'Hello 😀';
        $this->assertSame($expected, EmojiUtils::decodeEmojis($input));
    }

    public function test_decode_emoji_only_string(): void
    {
        $input = '\\\\u1f600\\\\u';
        $expected = '😀';
        $this->assertSame($expected, EmojiUtils::decodeEmojis($input));
    }

    public function test_decode_adjacent_emojis(): void
    {
        $input = '\\\\u1f600\\\\u\\\\u2764\\\\u';
        $expected = '😀❤';
        $this->assertSame($expected, EmojiUtils::decodeEmojis($input));
    }

    public function test_preserves_unicode_text(): void
    {
        // Already-decoded emojis or other Unicode should be preserved
        $input = 'Hello 😀 and café \\\\u2764\\\\u';
        $expected = 'Hello 😀 and café ❤';
        $this->assertSame($expected, EmojiUtils::decodeEmojis($input));
    }
}
