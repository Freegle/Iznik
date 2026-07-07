<?php

namespace App\Helpers;

/**
 * V1 parity: Mail::ourDomain() — checks if an email address is on one of
 * our internal domains (users, groups, direct, republisher).
 */
class MailHelper
{
    /**
     * Check if an email address is on one of our domains.
     *
     * V1: Mail::ourDomain() checks OURDOMAINS config which includes:
     * users.ilovefreegle.org, groups.ilovefreegle.org,
     * direct.ilovefreegle.org, republisher.freegle.in
     *
     * Case-insensitive to match V1's use of stripos().
     */
    public static function isOurDomain(?string $email): bool
    {
        if ($email === null || $email === '') {
            return false;
        }

        $domains = config('freegle.mail.internal_domains', ['users.ilovefreegle.org']);
        $emailLower = strtolower($email);

        foreach ($domains as $domain) {
            if (str_ends_with($emailLower, '@' . $domain)) {
                return true;
            }
        }

        return false;
    }
}
