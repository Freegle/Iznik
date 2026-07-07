<?php

namespace App\Support;

/**
 * Utility class for checking AMP for Email support.
 *
 * AMP for Email allows interactive content in emails, but is only supported
 * by certain email providers. This class helps determine whether to include
 * AMP content based on the recipient's email domain.
 *
 * @see https://amp.dev/documentation/guides-and-tutorials/learn/email-spec/amp-email-format/
 */
class AmpEmailSupport
{
    /**
     * Domains that support AMP for Email.
     *
     * As of 2025:
     * - Gmail: Full support (gmail.com, googlemail.com)
     * - Yahoo: Full support (yahoo.com and country TLDs)
     * - Mail.ru: Full support
     * - Outlook/Hotmail: Microsoft dropped AMP support in 2023
     */
    protected static array $supportedDomains = [
        // Gmail domains
        'gmail.com',
        'googlemail.com',

        // Yahoo domains (main + country TLDs)
        'yahoo.com',
        'yahoo.co.uk',
        'yahoo.co.in',
        'yahoo.ca',
        'yahoo.com.au',
        'yahoo.de',
        'yahoo.fr',
        'yahoo.es',
        'yahoo.it',
        'yahoo.co.jp',
        'yahoo.com.br',
        'yahoo.com.mx',
        'yahoo.com.ar',
        'yahoo.com.sg',
        'yahoo.com.hk',
        'yahoo.co.id',
        'yahoo.co.nz',
        'yahoo.ie',
        'yahoo.gr',
        'yahoo.pl',
        'yahoo.ro',
        'yahoo.com.ph',
        'yahoo.com.vn',
        'yahoo.com.tw',
        'yahoo.co.th',
        'yahoo.com.my',

        // AOL (owned by Yahoo, uses same platform)
        'aol.com',
        'aol.co.uk',

        // Mail.ru domains
        'mail.ru',
        'inbox.ru',
        'list.ru',
        'bk.ru',

        // Yandex (limited support)
        'yandex.ru',
        'yandex.com',
        'ya.ru',

        // Custom domains hosted on Gmail (Google Workspace)
        'ehibbert.org.uk',
    ];

    /**
     * Check if an email address is from a domain that supports AMP.
     *
     * @param string $email The email address to check
     * @return bool True if the domain supports AMP for Email
     */
    public static function isSupported(string $email): bool
    {
        $domain = self::extractDomain($email);

        if ($domain === null) {
            return false;
        }

        return in_array(strtolower($domain), self::$supportedDomains, true);
    }

    /**
     * Extract the domain from an email address.
     *
     * @param string $email The email address
     * @return string|null The domain, or null if invalid
     */
    public static function extractDomain(string $email): ?string
    {
        $email = trim($email);

        // Basic validation
        if (empty($email) || !str_contains($email, '@')) {
            return null;
        }

        $parts = explode('@', $email);

        if (count($parts) !== 2) {
            return null;
        }

        $domain = strtolower(trim($parts[1]));

        if (empty($domain)) {
            return null;
        }

        return $domain;
    }

    /**
     * Get the list of supported domains.
     *
     * @return array The list of domains that support AMP
     */
    public static function getSupportedDomains(): array
    {
        return self::$supportedDomains;
    }

    /**
     * Check if a domain is in the supported list.
     *
     * @param string $domain The domain to check (without @)
     * @return bool True if supported
     */
    public static function isDomainSupported(string $domain): bool
    {
        return in_array(strtolower(trim($domain)), self::$supportedDomains, true);
    }

    /**
     * Filter a list of email addresses to only those that support AMP.
     *
     * @param array $emails List of email addresses
     * @return array Only the emails from domains that support AMP
     */
    public static function filterSupported(array $emails): array
    {
        return array_filter($emails, fn($email) => self::isSupported($email));
    }

    /**
     * Get statistics about AMP support for a list of emails.
     *
     * @param array $emails List of email addresses
     * @return array{supported: int, unsupported: int, total: int, percentage: float}
     */
    public static function getStats(array $emails): array
    {
        $total = count($emails);
        $supported = count(self::filterSupported($emails));

        return [
            'supported' => $supported,
            'unsupported' => $total - $supported,
            'total' => $total,
            'percentage' => $total > 0 ? round(($supported / $total) * 100, 2) : 0.0,
        ];
    }
}
