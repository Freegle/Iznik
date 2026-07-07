<?php

namespace App\Mail\Traits;

/**
 * Trait for checking email feature flags.
 *
 * This controls which email types iznik-batch is allowed to send.
 * Configure via .env: FREEGLE_MAIL_ENABLED_TYPES=Welcome,ChatNotification
 */
trait FeatureFlags
{
    /**
     * Check if an email type is enabled for sending.
     *
     * @param string $emailType The email type name (e.g., 'Welcome', 'ChatNotification')
     * @return bool TRUE if enabled, FALSE if not (fail-safe: empty config = disabled)
     */
    protected static function isEmailTypeEnabled(string $emailType): bool
    {
        $enabledTypes = config('freegle.mail.enabled_types', '');

        if (empty($enabledTypes)) {
            return FALSE;
        }

        $types = array_map('trim', explode(',', $enabledTypes));
        return in_array($emailType, $types, TRUE);
    }
}
