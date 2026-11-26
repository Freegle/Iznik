<?php

namespace Tests\Unit\Support;

use App\Support\AmpEmailSupport;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Tests for AMP email domain support checking.
 */
class AmpEmailSupportTest extends TestCase
{
    public function test_gmail_addresses_are_supported(): void
    {
        $emails = [
            $this->uniqueEmail('user', 'gmail.com'),
            $this->uniqueEmail('USER', 'GMAIL.COM'),
            $this->uniqueEmail('user', 'googlemail.com'),
        ];

        foreach ($emails as $email) {
            $this->assertTrue(
                AmpEmailSupport::isSupported($email),
                "Expected Gmail address '$email' to be supported"
            );
        }

        // Test with + tag.
        $plusTagEmail = $this->uniqueEmail('test.user+tag', 'gmail.com');
        $this->assertTrue(
            AmpEmailSupport::isSupported($plusTagEmail),
            "Expected Gmail address '$plusTagEmail' to be supported"
        );

        // Test with whitespace.
        $whitespaceEmail = '  ' . $this->uniqueEmail('user', 'gmail.com') . '  ';
        $this->assertTrue(
            AmpEmailSupport::isSupported($whitespaceEmail),
            "Expected Gmail address '$whitespaceEmail' to be supported"
        );
    }

    public function test_yahoo_addresses_are_supported(): void
    {
        $emails = [
            $this->uniqueEmail('user', 'yahoo.com'),
            $this->uniqueEmail('user', 'yahoo.co.uk'),
            $this->uniqueEmail('user', 'yahoo.de'),
            $this->uniqueEmail('user', 'yahoo.fr'),
            $this->uniqueEmail('user', 'aol.com'),
            $this->uniqueEmail('user', 'aol.co.uk'),
        ];

        foreach ($emails as $email) {
            $this->assertTrue(
                AmpEmailSupport::isSupported($email),
                "Expected Yahoo address '$email' to be supported"
            );
        }
    }

    public function test_mail_ru_addresses_are_supported(): void
    {
        $emails = [
            $this->uniqueEmail('user', 'mail.ru'),
            $this->uniqueEmail('user', 'inbox.ru'),
            $this->uniqueEmail('user', 'list.ru'),
            $this->uniqueEmail('user', 'bk.ru'),
        ];

        foreach ($emails as $email) {
            $this->assertTrue(
                AmpEmailSupport::isSupported($email),
                "Expected Mail.ru address '$email' to be supported"
            );
        }
    }

    public function test_unsupported_addresses_return_false(): void
    {
        // Microsoft dropped AMP support.
        $microsoftEmails = [
            $this->uniqueEmail('user', 'outlook.com'),
            $this->uniqueEmail('user', 'hotmail.com'),
            $this->uniqueEmail('user', 'live.com'),
        ];

        // Other unsupported providers.
        $otherEmails = [
            $this->uniqueEmail('user', 'icloud.com'),
            $this->uniqueEmail('user', 'protonmail.com'),
            $this->uniqueEmail('user', 'example.com'),
            $this->uniqueEmail('user', 'ilovefreegle.org'),
            $this->uniqueEmail('user', 'company.co.uk'),
        ];

        // ISP emails.
        $ispEmails = [
            $this->uniqueEmail('user', 'btinternet.com'),
            $this->uniqueEmail('user', 'virginmedia.com'),
            $this->uniqueEmail('user', 'sky.com'),
        ];

        $allEmails = array_merge($microsoftEmails, $otherEmails, $ispEmails);

        foreach ($allEmails as $email) {
            $this->assertFalse(
                AmpEmailSupport::isSupported($email),
                "Expected address '$email' to be unsupported"
            );
        }
    }

    #[DataProvider('invalidEmailsProvider')]
    public function test_invalid_emails_return_false(string $email): void
    {
        $this->assertFalse(
            AmpEmailSupport::isSupported($email),
            "Expected invalid email '$email' to return false"
        );
    }

    public static function invalidEmailsProvider(): array
    {
        return [
            [''],
            ['   '],
            ['notanemail'],
            ['@nodomain.com'],
            ['user@'],
            ['user@@double.com'],
        ];
    }

    public function test_extract_domain_returns_correct_domain(): void
    {
        $gmailEmail = $this->uniqueEmail('user', 'gmail.com');
        $yahooEmail = $this->uniqueEmail('test', 'yahoo.co.uk');
        $exampleEmail = $this->uniqueEmail('user', 'EXAMPLE.COM');

        $this->assertEquals('gmail.com', AmpEmailSupport::extractDomain($gmailEmail));
        $this->assertEquals('yahoo.co.uk', AmpEmailSupport::extractDomain($yahooEmail));
        $this->assertEquals('example.com', AmpEmailSupport::extractDomain($exampleEmail));
    }

    public function test_extract_domain_handles_whitespace(): void
    {
        $gmailEmail = $this->uniqueEmail('user', 'gmail.com');
        $this->assertEquals('gmail.com', AmpEmailSupport::extractDomain('  ' . $gmailEmail . '  '));
    }

    public function test_extract_domain_returns_null_for_invalid(): void
    {
        $this->assertNull(AmpEmailSupport::extractDomain(''));
        $this->assertNull(AmpEmailSupport::extractDomain('notanemail'));
        $this->assertNull(AmpEmailSupport::extractDomain('user@'));
    }

    public function test_is_domain_supported_works_directly(): void
    {
        $this->assertTrue(AmpEmailSupport::isDomainSupported('gmail.com'));
        $this->assertTrue(AmpEmailSupport::isDomainSupported('GMAIL.COM'));
        $this->assertFalse(AmpEmailSupport::isDomainSupported('outlook.com'));
        $this->assertFalse(AmpEmailSupport::isDomainSupported('example.com'));
    }

    public function test_get_supported_domains_returns_array(): void
    {
        $domains = AmpEmailSupport::getSupportedDomains();

        $this->assertIsArray($domains);
        $this->assertNotEmpty($domains);
        $this->assertContains('gmail.com', $domains);
        $this->assertContains('yahoo.com', $domains);
    }

    public function test_filter_supported_returns_only_supported_emails(): void
    {
        $gmailEmail = $this->uniqueEmail('user1', 'gmail.com');
        $outlookEmail = $this->uniqueEmail('user2', 'outlook.com');
        $yahooEmail = $this->uniqueEmail('user3', 'yahoo.com');
        $exampleEmail = $this->uniqueEmail('user4', 'example.com');

        $emails = [
            $gmailEmail,
            $outlookEmail,
            $yahooEmail,
            $exampleEmail,
        ];

        $filtered = AmpEmailSupport::filterSupported($emails);

        $this->assertCount(2, $filtered);
        $this->assertContains($gmailEmail, $filtered);
        $this->assertContains($yahooEmail, $filtered);
        $this->assertNotContains($outlookEmail, $filtered);
        $this->assertNotContains($exampleEmail, $filtered);
    }

    public function test_filter_supported_handles_empty_array(): void
    {
        $this->assertEquals([], AmpEmailSupport::filterSupported([]));
    }

    public function test_get_stats_returns_correct_counts(): void
    {
        $gmailEmail = $this->uniqueEmail('user1', 'gmail.com');
        $outlookEmail = $this->uniqueEmail('user2', 'outlook.com');
        $yahooEmail = $this->uniqueEmail('user3', 'yahoo.com');
        $exampleEmail = $this->uniqueEmail('user4', 'example.com');
        $mailruEmail = $this->uniqueEmail('user5', 'mail.ru');

        $emails = [
            $gmailEmail,
            $outlookEmail,
            $yahooEmail,
            $exampleEmail,
            $mailruEmail,
        ];

        $stats = AmpEmailSupport::getStats($emails);

        $this->assertEquals(3, $stats['supported']);
        $this->assertEquals(2, $stats['unsupported']);
        $this->assertEquals(5, $stats['total']);
        $this->assertEquals(60.0, $stats['percentage']);
    }

    public function test_get_stats_handles_empty_array(): void
    {
        $stats = AmpEmailSupport::getStats([]);

        $this->assertEquals(0, $stats['supported']);
        $this->assertEquals(0, $stats['unsupported']);
        $this->assertEquals(0, $stats['total']);
        $this->assertEquals(0.0, $stats['percentage']);
    }

    public function test_get_stats_handles_all_supported(): void
    {
        $gmailEmail = $this->uniqueEmail('user', 'gmail.com');
        $yahooEmail = $this->uniqueEmail('user', 'yahoo.com');
        $emails = [$gmailEmail, $yahooEmail];
        $stats = AmpEmailSupport::getStats($emails);

        $this->assertEquals(100.0, $stats['percentage']);
    }

    public function test_get_stats_handles_none_supported(): void
    {
        $outlookEmail = $this->uniqueEmail('user', 'outlook.com');
        $exampleEmail = $this->uniqueEmail('user', 'example.com');
        $emails = [$outlookEmail, $exampleEmail];
        $stats = AmpEmailSupport::getStats($emails);

        $this->assertEquals(0.0, $stats['percentage']);
    }

    public function test_yandex_addresses_are_supported(): void
    {
        $yandexRuEmail = $this->uniqueEmail('user', 'yandex.ru');
        $yandexComEmail = $this->uniqueEmail('user', 'yandex.com');
        $yaRuEmail = $this->uniqueEmail('user', 'ya.ru');

        $this->assertTrue(AmpEmailSupport::isSupported($yandexRuEmail));
        $this->assertTrue(AmpEmailSupport::isSupported($yandexComEmail));
        $this->assertTrue(AmpEmailSupport::isSupported($yaRuEmail));
    }
}
