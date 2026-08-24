<?php

namespace App\Services\Mail\Deferrals;

/**
 * Buckets a relay hostname into the family that actually shares a reputation.
 *
 * Providers block per sending-IP-to-receiving-infrastructure pair, not per
 * recipient domain. When Yahoo stopped taking our mail on 2026-08-15 it took
 * out yahoo.co.uk, yahoo.com, ymail.com, rocketmail.com, aol.com, aol.co.uk,
 * aim.com and sky.com in one go - Sky's mail is Yahoo-hosted, which is not
 * remotely guessable from the domain. All of them relay through
 * *.am0.yahoodns.net, so the relay host is the honest unit of suppression and
 * the recipient domain is not.
 *
 * The grouping is the registrable-ish suffix of the relay host: drop the
 * per-machine label so mta5/mta6/mta7.am0.yahoodns.net collapse into one
 * group. That is deliberately mechanical rather than a hand-maintained list
 * of providers, because the next provider to defer us will not be on any list
 * we wrote today.
 */
class MxGrouper
{
    /**
     * Suffixes that are too broad to be a useful group on their own, so we
     * keep one more label. Without this, everything hosted on a big platform
     * would collapse into a single bucket and one customer's problem would
     * suppress mail to all of them.
     */
    private const KEEP_EXTRA_LABEL = [
        'protection.outlook.com',
        'mail.protection.outlook.com',
        'pphosted.com',
        'ppe-hosted.com',
        'mimecast.com',
        'messagelabs.com',
        'antispamcloud.com',
        'emailsrvr.com',
        'zoho.com',
        'secureserver.net',
    ];

    /**
     * Public suffixes we must never treat as a group, or a single deferral
     * would suppress an entire country's mail.
     */
    private const NEVER_ALONE = [
        'co.uk', 'org.uk', 'me.uk', 'ac.uk', 'gov.uk', 'net.uk', 'sch.uk',
        'com.au', 'net.au', 'org.au', 'co.nz', 'co.za', 'com.br', 'co.jp',
        'com', 'net', 'org', 'edu', 'gov', 'uk', 'de', 'fr', 'nl', 'ie', 'es', 'it',
    ];

    public static function group(string $host): string
    {
        $host = strtolower(trim($host));
        $host = rtrim($host, '.');

        if ($host === '') {
            return '';
        }

        // A bare IP has no family to belong to; it is its own group.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $host;
        }

        $labels = explode('.', $host);
        if (count($labels) <= 2) {
            return $host;
        }

        // Longest match wins. Both 'protection.outlook.com' and
        // 'mail.protection.outlook.com' are listed, and taking the first hit
        // rather than the longest would group every Microsoft 365 tenant
        // together - exactly the collapse the list exists to prevent.
        $best = NULL;
        foreach (self::KEEP_EXTRA_LABEL as $suffix) {
            if (str_ends_with($host, '.' . $suffix)
                && ($best === NULL || strlen($suffix) > strlen($best))) {
                $best = $suffix;
            }
        }

        if ($best !== NULL) {
            // One label beyond the platform suffix: the customer, e.g. the
            // tenant in acme-com.mail.protection.outlook.com.
            $extra = count(explode('.', $best)) + 1;

            return implode('.', array_slice($labels, -min($extra, count($labels))));
        }

        $candidate = implode('.', array_slice($labels, -2));

        // Two labels is usually the family (yahoodns.net, google.com), but not
        // when those two labels are only a public suffix.
        if (in_array($candidate, self::NEVER_ALONE, true) && count($labels) >= 3) {
            return implode('.', array_slice($labels, -3));
        }

        return $candidate;
    }

    /**
     * The relay host Postfix blamed, pulled out of a delay_reason.
     *
     * Postfix writes two shapes. When it got an SMTP answer:
     *   host mta7.am0.yahoodns.net[67.195.228.94] said: 421 4.7.0 [TSS04] ...
     * When it never got that far:
     *   connect to mx1.example.com[198.51.100.5]:25: Connection timed out
     *
     * Returns null when neither shape matches - some delay reasons are purely
     * local ("mail transport unavailable") and blaming a provider for those
     * would suppress the wrong thing.
     */
    public static function fromDelayReason(string $reason): ?string
    {
        if (preg_match('/\bhost\s+([A-Za-z0-9._-]+)\[/', $reason, $m)) {
            return self::group($m[1]);
        }

        if (preg_match('/\bconnect to\s+([A-Za-z0-9._-]+)\[/', $reason, $m)) {
            return self::group($m[1]);
        }

        return null;
    }

    /**
     * A short, member-facing name for a group, e.g. "Yahoo" for
     * am0.yahoodns.net. Moderators and members should read the name of the
     * company that is not accepting our mail, not a piece of DNS.
     */
    public static function providerName(string $group): ?string
    {
        $known = [
            'yahoodns.net' => 'Yahoo',
            'yahoo.com' => 'Yahoo',
            'aol.com' => 'AOL',
            'google.com' => 'Gmail',
            'googlemail.com' => 'Gmail',
            'outlook.com' => 'Microsoft',
            'hotmail.com' => 'Microsoft',
            'protection.outlook.com' => 'Microsoft',
            'icloud.com' => 'Apple',
            'me.com' => 'Apple',
            'apple.com' => 'Apple',
            'btinternet.com' => 'BT',
            'bt.com' => 'BT',
            'virginmedia.com' => 'Virgin Media',
            'sky.com' => 'Sky',
            'talktalk.co.uk' => 'TalkTalk',
            'zoho.com' => 'Zoho',
            'mimecast.com' => 'Mimecast',
            'pphosted.com' => 'Proofpoint',
        ];

        foreach ($known as $suffix => $name) {
            if ($group === $suffix || str_ends_with($group, '.' . $suffix)) {
                return $name;
            }
        }

        return null;
    }
}
