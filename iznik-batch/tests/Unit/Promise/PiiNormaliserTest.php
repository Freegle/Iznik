<?php

namespace Tests\Unit\Promise;

use App\Services\Promise\PiiNormaliser;
use PHPUnit\Framework\TestCase;

class PiiNormaliserTest extends TestCase
{
    private PiiNormaliser $normaliser;

    protected function setUp(): void
    {
        $this->normaliser = new PiiNormaliser();
    }

    public function test_normalise_uk_postcode()
    {
        $result = $this->normaliser->normalise('I live in SW1A 1AA');
        $this->assertStringContainsString('<POSTCODE>', $result);
        $this->assertStringNotContainsString('SW1A 1AA', $result);
    }

    public function test_normalise_phone_07_number()
    {
        $result = $this->normaliser->normalise('Call me on 07700 900000');
        $this->assertStringContainsString('<PHONE>', $result);
        $this->assertStringNotContainsString('07700 900000', $result);
    }

    public function test_normalise_phone_plus44()
    {
        $result = $this->normaliser->normalise('Reach me at +44 20 7946 0958');
        $this->assertStringContainsString('<PHONE>', $result);
        $this->assertStringNotContainsString('+44', $result);
    }

    public function test_normalise_phone_11_digit()
    {
        $result = $this->normaliser->normalise('My number is 02070946958 for pickup');
        $this->assertStringContainsString('<PHONE>', $result);
        $this->assertStringNotContainsString('02070946958', $result);
    }

    public function test_normalise_email()
    {
        $result = $this->normaliser->normalise('Email me at test@example.com please');
        $this->assertStringContainsString('<EMAIL>', $result);
        $this->assertStringNotContainsString('test@example.com', $result);
    }

    public function test_normalise_http_url()
    {
        $result = $this->normaliser->normalise('See http://example.com for details');
        $this->assertStringContainsString('<URL>', $result);
        $this->assertStringNotContainsString('http://example.com', $result);
    }

    public function test_normalise_https_url()
    {
        $result = $this->normaliser->normalise('Check https://example.com here');
        $this->assertStringContainsString('<URL>', $result);
        $this->assertStringNotContainsString('https://example.com', $result);
    }

    public function test_normalise_trashnothing_url()
    {
        $result = $this->normaliser->normalise('Posted on trashnothing.com for trade');
        $this->assertStringContainsString('<URL>', $result);
        $this->assertStringNotContainsString('trashnothing.com', $result);
    }

    public function test_normalise_street_address_number_and_name()
    {
        $result = $this->normaliser->normalise('Come to 12 High Street in town');
        $this->assertStringContainsString('<ADDRESS>', $result);
        $this->assertStringNotContainsString('12 High Street', $result);
    }

    public function test_normalise_flat_address()
    {
        $result = $this->normaliser->normalise('Meet me at Flat 3, Oakwood Lane');
        $this->assertStringContainsString('<ADDRESS>', $result);
        $this->assertStringNotContainsString('Flat 3', $result);
    }

    public function test_does_not_redact_times_or_quantities_as_address()
    {
        // A bare "<number> <word>" is NOT an address. The old regex matched any
        // number+word and injected fake <ADDRESS> tokens (a strong promise signal)
        // into times, durations and quantities — corrupting the dataset. Regression guard.
        foreach ([
            'come at 6 pm',
            'in 2 days',
            'give me 10 minutes',
            'I have 3 chairs',
            'around 7 tonight',
            'ready in 5 mins',
            '11 am works for me',
        ] as $input) {
            $this->assertStringNotContainsString(
                '<ADDRESS>',
                $this->normaliser->normalise($input),
                "'$input' must not be redacted as an address"
            );
        }
    }

    public function test_still_redacts_real_street_addresses()
    {
        foreach ([
            '12 High Street in town',
            'my address is 45 Elm Road',
            '10 Downing Street',
            'come to 7 Mill Lane',
        ] as $input) {
            $this->assertStringContainsString(
                '<ADDRESS>',
                $this->normaliser->normalise($input),
                "'$input' should be redacted as an address"
            );
        }
    }

    public function test_trashnothing_email_is_replaced_whole()
    {
        $result = $this->normaliser->normalise('mail jo.bloggs@user.trashnothing.com ok');
        $this->assertStringContainsString('<EMAIL>', $result);
        $this->assertStringNotContainsString('bloggs', $result);
        $this->assertStringNotContainsString('trashnothing', $result);

        $result = $this->normaliser->normalise('or x@mail.trashnothing.com');
        $this->assertStringNotContainsString('x@', $result);
    }

    public function test_phone_with_three_groups_is_fully_redacted()
    {
        foreach (['call 07123 456 789 pls', 'call 07700-900-123 pls', 'call 07700 900000 pls', 'call 07700900000 pls'] as $input) {
            $result = $this->normaliser->normalise($input);
            $this->assertStringContainsString('<PHONE>', $result);
            $this->assertDoesNotMatchRegularExpression('/\d{3}/', $result, "'$input' leaked digits: $result");
        }
    }

    public function test_lowercase_street_address_is_redacted()
    {
        foreach (['I live at 12 elm road', 'flat 3 oakwood lane', 'it is 7 mill lane', 'try 12 elm rd'] as $input) {
            $result = $this->normaliser->normalise($input);
            $this->assertStringContainsString('<ADDRESS>', $result, "'$input' should be redacted");
            $this->assertDoesNotMatchRegularExpression('/\d/', $result, "'$input' leaked a number: $result");
        }
    }

    public function test_lowercase_street_followed_by_a_connecting_word_is_redacted()
    {
        foreach (['come to 12 elm road please', 'it is 7 mill lane, thanks', '12 elm road and ring the bell'] as $input) {
            $result = $this->normaliser->normalise($input);
            $this->assertStringContainsString('<ADDRESS>', $result, "'$input' should be redacted");
            $this->assertDoesNotMatchRegularExpression('/\d/', $result, "'$input' leaked a number: $result");
        }
    }

    public function test_quantity_adjective_and_street_word_is_not_redacted_as_address()
    {
        foreach (['I have 3 old road bikes', 'only 2 fast lane drivers', 'I have 2 spare Gardens chairs', 'got 4 big avenue signs'] as $input) {
            $this->assertStringNotContainsString('<ADDRESS>', $this->normaliser->normalise($input), $input);
        }
    }

    public function test_lowercase_durations_are_not_redacted_as_address()
    {
        foreach (['it is a 20 min drive', 'about 5 minutes walk', '3 chairs in the park', '3 bags st'] as $input) {
            $this->assertStringNotContainsString('<ADDRESS>', $this->normaliser->normalise($input), $input);
        }
    }

    public function test_links_without_a_scheme_are_redacted()
    {
        $result = $this->normaliser->normalise('find me at www.facebook.com/john.smith.123 or example.com/profile/x');
        $this->assertStringNotContainsString('facebook', $result);
        $this->assertStringNotContainsString('smith', $result);
        $this->assertStringNotContainsString('profile', $result);
    }

    public function test_what3words_is_redacted()
    {
        $result = $this->normaliser->normalise('meet at ///filled.count.soap ok');
        $this->assertStringContainsString('<ADDRESS>', $result);
        $this->assertStringNotContainsString('filled', $result);
    }

    public function test_greeting_and_title_names_are_redacted()
    {
        $result = $this->normaliser->normalise('Hi Sarah Jones, thanks. Mrs Patel will collect.');
        $this->assertStringNotContainsString('Sarah', $result);
        $this->assertStringNotContainsString('Jones', $result);
        $this->assertStringNotContainsString('Patel', $result);
        $this->assertStringContainsString('<NAME>', $result);
    }

    public function test_words_after_a_greeting_name_are_kept()
    {
        $this->assertEquals('Hi <NAME> Thanks for the offer', $this->normaliser->normalise('Hi Sarah Thanks for the offer'));
        $this->assertEquals('Hi <NAME> Can you collect', $this->normaliser->normalise('Hi John Can you collect'));
        $this->assertEquals('Mrs <NAME> will collect', $this->normaliser->normalise('Mrs Patel will collect'));
    }

    public function test_greeting_followed_by_a_common_word_is_kept()
    {
        $this->assertEquals('Hi there, are you free?', $this->normaliser->normalise('Hi there, are you free?'));
        $this->assertEquals('Hello again', $this->normaliser->normalise('Hello again'));
    }
    public function test_preserve_emoji()
    {
        $input = 'Great item 🎉 very happy 😊';
        $result = $this->normaliser->normalise($input);
        $this->assertStringContainsString('🎉', $result);
        $this->assertStringContainsString('😊', $result);
    }

    public function test_preserve_accented_characters()
    {
        $input = 'Café résumé naïve';
        $result = $this->normaliser->normalise($input);
        $this->assertStringContainsString('Café', $result);
        $this->assertStringContainsString('résumé', $result);
        $this->assertStringContainsString('naïve', $result);
    }

    public function test_valid_utf8_output()
    {
        $input = 'Test with emoji 🎉 and accents é ñ ü 你好';
        $result = $this->normaliser->normalise($input);
        // Valid UTF-8 should not have false from mb_check_encoding
        $this->assertTrue(mb_check_encoding($result, 'UTF-8'));
    }

    public function test_collapse_carriage_return_newline_tab()
    {
        // Use digit-free words so the address regex ("<number> <Word>") doesn't fire —
        // this test is about \r\n\t collapsing, not PII.
        $result = $this->normaliser->normalise("Line one\r\nLine two\tTabbed");
        $this->assertStringNotContainsString("\r", $result);
        $this->assertStringNotContainsString("\n", $result);
        $this->assertStringNotContainsString("\t", $result);
        $this->assertStringContainsString("Line one Line two Tabbed", $result);
    }

    public function test_collapse_multiple_spaces()
    {
        $result = $this->normaliser->normalise('Text  with   multiple    spaces');
        $this->assertStringContainsString('Text with multiple spaces', $result);
    }

    public function test_multiple_pii_in_one_string()
    {
        $result = $this->normaliser->normalise(
            'Call 07700 900000 or email test@example.com. Address: 12 High Street SW1A 1AA'
        );
        $this->assertStringContainsString('<PHONE>', $result);
        $this->assertStringContainsString('<EMAIL>', $result);
        $this->assertStringContainsString('<ADDRESS>', $result);
        $this->assertStringContainsString('<POSTCODE>', $result);
        $this->assertStringNotContainsString('07700 900000', $result);
        $this->assertStringNotContainsString('test@example.com', $result);
    }

    public function test_empty_string()
    {
        $result = $this->normaliser->normalise('');
        $this->assertEquals('', $result);
    }

    public function test_whitespace_only()
    {
        $result = $this->normaliser->normalise('   ');
        $this->assertStringNotContainsString('   ', $result);
    }
}
