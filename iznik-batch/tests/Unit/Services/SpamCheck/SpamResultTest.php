<?php

namespace Tests\Unit\Services\SpamCheck;

use App\Services\SpamCheck\SpamResult;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SpamResultTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Constructor / property access
    // -------------------------------------------------------------------------

    public function test_constructor_sets_all_properties(): void
    {
        $result = new SpamResult('TestEngine', 3.5, ['SYM_A', 'SYM_B'], true, 'some error');

        $this->assertSame('TestEngine', $result->engine);
        $this->assertSame(3.5, $result->score);
        $this->assertSame(['SYM_A', 'SYM_B'], $result->symbols);
        $this->assertTrue($result->isSpam);
        $this->assertSame('some error', $result->error);
    }

    public function test_constructor_error_defaults_to_null(): void
    {
        $result = new SpamResult('Engine', 0.0, [], false);

        $this->assertNull($result->error);
    }

    // -------------------------------------------------------------------------
    // SpamResult::error()
    // -------------------------------------------------------------------------

    public function test_error_factory_sets_engine_and_message(): void
    {
        $result = SpamResult::error('SpamAssassin', 'Connection refused');

        $this->assertSame('SpamAssassin', $result->engine);
        $this->assertSame('Connection refused', $result->error);
        $this->assertSame(0.0, $result->score);
        $this->assertSame([], $result->symbols);
        $this->assertFalse($result->isSpam);
    }

    public function test_error_factory_rspamd(): void
    {
        $result = SpamResult::error('Rspamd', 'HTTP 503');

        $this->assertSame('Rspamd', $result->engine);
        $this->assertSame('HTTP 503', $result->error);
    }

    // -------------------------------------------------------------------------
    // SpamResult::fromSpamAssassin()
    // -------------------------------------------------------------------------

    #[DataProvider('spamAssassinResponseProvider')]
    public function test_from_spam_assassin_parses_response(
        string $response,
        bool $expectedIsSpam,
        float $expectedScore,
    ): void {
        $result = SpamResult::fromSpamAssassin($response);

        $this->assertSame('SpamAssassin', $result->engine);
        $this->assertSame($expectedIsSpam, $result->isSpam);
        $this->assertSame($expectedScore, $result->score);
        $this->assertNull($result->error);
    }

    public static function spamAssassinResponseProvider(): array
    {
        return [
            'True marker, high score' => [
                "Spam: True ; 15.5 / 5.0\r\n",
                true,
                15.5,
            ],
            'False marker, low score' => [
                "Spam: False ; 2.1 / 5.0\r\n",
                false,
                2.1,
            ],
            'Yes marker' => [
                "Spam: Yes ; 8.0 / 5.0\r\n",
                true,
                8.0,
            ],
            'No marker' => [
                "Spam: No ; 1.0 / 5.0\r\n",
                false,
                1.0,
            ],
            'Case-insensitive TRUE' => [
                "Spam: TRUE ; 12.3 / 5.0\r\n",
                true,
                12.3,
            ],
            'Case-insensitive false' => [
                "Spam: false ; 0.0 / 5.0\r\n",
                false,
                0.0,
            ],
            'Score of zero' => [
                "Spam: False ; 0.0 / 5.0\r\n",
                false,
                0.0,
            ],
        ];
    }

    public function test_from_spam_assassin_empty_response_gives_defaults(): void
    {
        $result = SpamResult::fromSpamAssassin('');

        $this->assertSame('SpamAssassin', $result->engine);
        $this->assertFalse($result->isSpam);
        $this->assertSame(0.0, $result->score);
        $this->assertNull($result->error);
    }

    public function test_from_spam_assassin_malformed_response_gives_defaults(): void
    {
        $result = SpamResult::fromSpamAssassin("Some garbage text without spam header\n");

        $this->assertFalse($result->isSpam);
        $this->assertSame(0.0, $result->score);
    }

    public function test_from_spam_assassin_extracts_symbols(): void
    {
        $response = "Spam: True ; 15.5 / 5.0\r\nSYMS: HTML_MESSAGE URIBL_DBL_BLOCKED_OPTIN\r\n";

        $result = SpamResult::fromSpamAssassin($response);

        // Should extract uppercase tokens of length > 2 that are not True/False etc.
        $this->assertNotEmpty($result->symbols);
        $this->assertContains('HTML_MESSAGE', $result->symbols);
        $this->assertContains('URIBL_DBL_BLOCKED_OPTIN', $result->symbols);
    }

    public function test_from_spam_assassin_filters_reserved_words_from_symbols(): void
    {
        $response = "Spam: True ; 8.0 / 5.0\r\n";

        $result = SpamResult::fromSpamAssassin($response);

        // 'Spam', 'True', 'False', 'Yes', 'No' must not appear as symbols
        $this->assertNotContains('SPAM', $result->symbols);
        $this->assertNotContains('TRUE', $result->symbols);
        $this->assertNotContains('FALSE', $result->symbols);
    }

    public function test_from_spam_assassin_filters_short_tokens(): void
    {
        $response = "Spam: False ; 1.0 / 5.0\r\nAB XY ZZZ LONG_SYMBOL\r\n";

        $result = SpamResult::fromSpamAssassin($response);

        // Tokens <= 2 chars should be filtered out
        $this->assertNotContains('AB', $result->symbols);
        $this->assertNotContains('XY', $result->symbols);
    }

    // -------------------------------------------------------------------------
    // SpamResult::fromRspamd()
    // -------------------------------------------------------------------------

    public function test_from_rspamd_reject_action_is_spam(): void
    {
        $response = [
            'score' => 10.5,
            'action' => 'reject',
            'symbols' => [],
        ];

        $result = SpamResult::fromRspamd($response);

        $this->assertSame('Rspamd', $result->engine);
        $this->assertTrue($result->isSpam);
        $this->assertSame(10.5, $result->score);
        $this->assertNull($result->error);
    }

    #[DataProvider('rspamdNonSpamActionProvider')]
    public function test_from_rspamd_non_reject_action_is_not_spam(string $action): void
    {
        $response = [
            'score' => 3.0,
            'action' => $action,
            'symbols' => [],
        ];

        $result = SpamResult::fromRspamd($response);

        $this->assertFalse($result->isSpam);
    }

    public static function rspamdNonSpamActionProvider(): array
    {
        return [
            ['no action'],
            ['greylist'],
            ['add header'],
            ['soft reject'],
            ['rewrite subject'],
        ];
    }

    public function test_from_rspamd_missing_action_defaults_to_not_spam(): void
    {
        $response = ['score' => 2.0, 'symbols' => []];

        $result = SpamResult::fromRspamd($response);

        $this->assertFalse($result->isSpam);
        $this->assertSame(2.0, $result->score);
    }

    public function test_from_rspamd_missing_score_defaults_to_zero(): void
    {
        $response = ['action' => 'no action', 'symbols' => []];

        $result = SpamResult::fromRspamd($response);

        $this->assertSame(0.0, $result->score);
    }

    public function test_from_rspamd_integer_score_is_returned_as_float(): void
    {
        $response = ['score' => 5, 'action' => 'no action', 'symbols' => []];

        $result = SpamResult::fromRspamd($response);

        $this->assertSame(5.0, $result->score);
        $this->assertIsFloat($result->score);
    }

    public function test_from_rspamd_extracts_symbols_with_scores(): void
    {
        $response = [
            'score' => 5.0,
            'action' => 'reject',
            'symbols' => [
                'BAYES_SPAM' => ['score' => 3.5],
                'HTML_IMAGE_ONLY_04' => ['score' => 1.5],
            ],
        ];

        $result = SpamResult::fromRspamd($response);

        $this->assertContains('BAYES_SPAM(3.5)', $result->symbols);
        $this->assertContains('HTML_IMAGE_ONLY_04(1.5)', $result->symbols);
    }

    public function test_from_rspamd_symbol_without_score_defaults_to_zero(): void
    {
        $response = [
            'score' => 1.0,
            'action' => 'no action',
            'symbols' => [
                'SOME_RULE' => [],
            ],
        ];

        $result = SpamResult::fromRspamd($response);

        $this->assertContains('SOME_RULE(0.0)', $result->symbols);
    }

    public function test_from_rspamd_empty_symbols_array(): void
    {
        $response = [
            'score' => 0.5,
            'action' => 'no action',
            'symbols' => [],
        ];

        $result = SpamResult::fromRspamd($response);

        $this->assertSame([], $result->symbols);
    }

    public function test_from_rspamd_null_symbols_field(): void
    {
        $response = [
            'score' => 0.5,
            'action' => 'no action',
            // No 'symbols' key
        ];

        $result = SpamResult::fromRspamd($response);

        $this->assertSame([], $result->symbols);
    }

    public function test_from_rspamd_non_array_symbols_field_ignored(): void
    {
        $response = [
            'score' => 0.5,
            'action' => 'no action',
            'symbols' => 'unexpected string',
        ];

        $result = SpamResult::fromRspamd($response);

        $this->assertSame([], $result->symbols);
    }

    // -------------------------------------------------------------------------
    // toHeaderValue()
    // -------------------------------------------------------------------------

    public function test_to_header_value_formats_score(): void
    {
        $result = new SpamResult('Rspamd', 7.123, [], false);

        $this->assertSame('7.1', $result->toHeaderValue());
    }

    public function test_to_header_value_formats_zero(): void
    {
        $result = new SpamResult('Rspamd', 0.0, [], false);

        $this->assertSame('0.0', $result->toHeaderValue());
    }

    public function test_to_header_value_rounds_score(): void
    {
        // 9.96 reliably rounds to 10.0 — 9.95 is not safe due to IEEE 754 representation
        $result = new SpamResult('Rspamd', 9.96, [], false);

        $this->assertSame('10.0', $result->toHeaderValue());
    }

    public function test_to_header_value_with_error_returns_error_prefix(): void
    {
        $result = SpamResult::error('SpamAssassin', 'Timeout');

        $this->assertSame('Error: Timeout', $result->toHeaderValue());
    }

    // -------------------------------------------------------------------------
    // symbolsToHeaderValue()
    // -------------------------------------------------------------------------

    public function test_symbols_to_header_value_joins_symbols(): void
    {
        $result = new SpamResult('Rspamd', 5.0, ['A_RULE', 'B_RULE', 'C_RULE'], false);

        $this->assertSame('A_RULE,B_RULE,C_RULE', $result->symbolsToHeaderValue());
    }

    public function test_symbols_to_header_value_empty(): void
    {
        $result = new SpamResult('Rspamd', 0.0, [], false);

        $this->assertSame('', $result->symbolsToHeaderValue());
    }

    public function test_symbols_to_header_value_truncates_at_ten(): void
    {
        $symbols = ['S1', 'S2', 'S3', 'S4', 'S5', 'S6', 'S7', 'S8', 'S9', 'S10', 'S11', 'S12'];
        $result = new SpamResult('Rspamd', 9.0, $symbols, true);

        $header = $result->symbolsToHeaderValue();
        $parts = explode(',', $header);

        $this->assertCount(10, $parts);
        $this->assertSame('S1', $parts[0]);
        $this->assertSame('S10', $parts[9]);
    }

    public function test_symbols_to_header_value_exactly_ten_symbols(): void
    {
        $symbols = ['S1', 'S2', 'S3', 'S4', 'S5', 'S6', 'S7', 'S8', 'S9', 'S10'];
        $result = new SpamResult('Rspamd', 3.0, $symbols, false);

        $header = $result->symbolsToHeaderValue();
        $parts = explode(',', $header);

        $this->assertCount(10, $parts);
    }

    public function test_symbols_to_header_value_with_error_returns_error_string(): void
    {
        $result = SpamResult::error('Rspamd', 'Connection reset');

        $this->assertSame('Error', $result->symbolsToHeaderValue());
    }
}
