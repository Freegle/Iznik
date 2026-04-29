<?php

namespace Tests\Unit\Helpers;

use App\Helpers\MailHelper;
use Tests\TestCase;

/**
 * Tests for MailHelper::isOurDomain() — the V1-parity helper that identifies
 * Freegle-internal email addresses (users, groups, direct, republisher domains).
 *
 * All branching paths are covered: null/empty inputs, each internal domain,
 * external addresses, case-insensitivity, subdomain non-matching, and a
 * config override to verify the method respects runtime configuration.
 */
class MailHelperTest extends TestCase
{
    // -----------------------------------------------------------------
    // Null / empty guard
    // -----------------------------------------------------------------

    public function test_null_returns_false(): void
    {
        $this->assertFalse(MailHelper::isOurDomain(null));
    }

    public function test_empty_string_returns_false(): void
    {
        $this->assertFalse(MailHelper::isOurDomain(''));
    }

    // -----------------------------------------------------------------
    // Each configured internal domain returns true
    // -----------------------------------------------------------------

    public function test_users_domain_returns_true(): void
    {
        $this->assertTrue(MailHelper::isOurDomain('alice@users.ilovefreegle.org'));
    }

    public function test_groups_domain_returns_true(): void
    {
        $this->assertTrue(MailHelper::isOurDomain('freegleSE10@groups.ilovefreegle.org'));
    }

    public function test_direct_domain_returns_true(): void
    {
        $this->assertTrue(MailHelper::isOurDomain('notify-12345@direct.ilovefreegle.org'));
    }

    public function test_republisher_domain_returns_true(): void
    {
        $this->assertTrue(MailHelper::isOurDomain('republish@republisher.freegle.in'));
    }

    // -----------------------------------------------------------------
    // External addresses return false
    // -----------------------------------------------------------------

    public function test_gmail_returns_false(): void
    {
        $this->assertFalse(MailHelper::isOurDomain('someone@gmail.com'));
    }

    public function test_yahoo_returns_false(): void
    {
        $this->assertFalse(MailHelper::isOurDomain('user@yahoo.co.uk'));
    }

    public function test_ilovefreegle_org_apex_returns_false(): void
    {
        // The apex domain itself is not in the internal list — only the subdomains are.
        $this->assertFalse(MailHelper::isOurDomain('support@ilovefreegle.org'));
    }

    // -----------------------------------------------------------------
    // Case-insensitive matching
    // -----------------------------------------------------------------

    public function test_uppercase_domain_matches(): void
    {
        $this->assertTrue(MailHelper::isOurDomain('user@USERS.ILOVEFREEGLE.ORG'));
    }

    public function test_mixed_case_email_matches(): void
    {
        $this->assertTrue(MailHelper::isOurDomain('User.Name@Groups.IloveFreegle.Org'));
    }

    // -----------------------------------------------------------------
    // Subdomain does not match (str_ends_with anchors on '@')
    // -----------------------------------------------------------------

    public function test_subdomain_of_internal_domain_does_not_match(): void
    {
        // sub.users.ilovefreegle.org is NOT in the list; only users.ilovefreegle.org is.
        $this->assertFalse(MailHelper::isOurDomain('foo@sub.users.ilovefreegle.org'));
    }

    // -----------------------------------------------------------------
    // Malformed / edge-case inputs
    // -----------------------------------------------------------------

    public function test_domain_without_at_sign_returns_false(): void
    {
        // Looks like a domain but has no '@'; cannot be an internal address.
        $this->assertFalse(MailHelper::isOurDomain('users.ilovefreegle.org'));
    }

    public function test_local_part_only_returns_false(): void
    {
        $this->assertFalse(MailHelper::isOurDomain('justausername'));
    }

    // -----------------------------------------------------------------
    // Runtime config override
    // -----------------------------------------------------------------

    public function test_custom_config_domain_is_recognised(): void
    {
        config(['freegle.mail.internal_domains' => ['custom.example.com']]);

        $this->assertTrue(MailHelper::isOurDomain('bot@custom.example.com'));
        // The production domains should no longer match once overridden.
        $this->assertFalse(MailHelper::isOurDomain('alice@users.ilovefreegle.org'));
    }

    public function test_empty_config_domain_list_always_returns_false(): void
    {
        config(['freegle.mail.internal_domains' => []]);

        $this->assertFalse(MailHelper::isOurDomain('alice@users.ilovefreegle.org'));
        $this->assertFalse(MailHelper::isOurDomain('anyone@anywhere.com'));
    }
}
