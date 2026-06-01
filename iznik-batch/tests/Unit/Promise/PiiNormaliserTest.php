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
        $result = $this->normaliser->normalise("Line 1\r\nLine 2\tTabbed");
        $this->assertStringNotContainsString("\r");
        $this->assertStringNotContainsString("\n");
        $this->assertStringNotContainsString("\t");
        $this->assertStringContainsString("Line 1 Line 2 Tabbed", $result);
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
        $this->assertEqual('', $result);
    }

    public function test_whitespace_only()
    {
        $result = $this->normaliser->normalise('   ');
        $this->assertStringNotContainsString('   ');
    }
}
