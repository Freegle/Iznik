<?php

namespace Tests\Unit\Mail;

use Tests\TestCase;

class FeatureFlagsTest extends TestCase
{
    use \App\Mail\Traits\FeatureFlags;

    private const ALL_EMAIL_TYPES = 'Welcome,ChatNotification,ChatNotificationUser2Mod,ChatNotificationMod2Mod,Digest,UnifiedDigest,DonationThank,DonationAsk';

    public function test_email_type_enabled_when_in_config(): void
    {
        // The phpunit.xml sets FREEGLE_MAIL_ENABLED_TYPES with all email types.
        $this->assertTrue(self::isEmailTypeEnabled('Welcome'));
        $this->assertTrue(self::isEmailTypeEnabled('ChatNotification'));
        $this->assertTrue(self::isEmailTypeEnabled('ChatNotificationUser2Mod'));
        $this->assertTrue(self::isEmailTypeEnabled('ChatNotificationMod2Mod'));
        $this->assertTrue(self::isEmailTypeEnabled('Digest'));
        $this->assertTrue(self::isEmailTypeEnabled('UnifiedDigest'));
        $this->assertTrue(self::isEmailTypeEnabled('DonationThank'));
        $this->assertTrue(self::isEmailTypeEnabled('DonationAsk'));
        $this->assertTrue(self::isEmailTypeEnabled('StoriesNewsletter'));
    }

    public function test_email_type_disabled_when_not_in_config(): void
    {
        // Types not in the config should be disabled.
        $this->assertFalse(self::isEmailTypeEnabled('SomeOtherType'));
        $this->assertFalse(self::isEmailTypeEnabled('Newsletter'));
    }

    public function test_email_type_disabled_when_config_empty(): void
    {
        // Temporarily set config to empty.
        config(['freegle.mail.enabled_types' => '']);

        $this->assertFalse(self::isEmailTypeEnabled('Welcome'));
        $this->assertFalse(self::isEmailTypeEnabled('ChatNotification'));

        // Restore for other tests.
        config(['freegle.mail.enabled_types' => self::ALL_EMAIL_TYPES]);
    }

    public function test_email_type_handles_whitespace(): void
    {
        // Test that whitespace is trimmed.
        config(['freegle.mail.enabled_types' => 'Welcome , ChatNotification']);

        $this->assertTrue(self::isEmailTypeEnabled('Welcome'));
        $this->assertTrue(self::isEmailTypeEnabled('ChatNotification'));

        // Restore.
        config(['freegle.mail.enabled_types' => self::ALL_EMAIL_TYPES]);
    }
}
