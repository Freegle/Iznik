<?php

namespace App\Mail\Traits;

/**
 * Trait for adding BCC logging to emails.
 *
 * Allows specific email types to be copied to a logging address for
 * debugging and monitoring. Configure via .env:
 *
 * FREEGLE_MAIL_LOG_TYPES=Welcome,ChatNotification
 * FREEGLE_MAIL_LOG_ADDRESS=log@example.com
 */
trait LoggableEmail
{
    /**
     * Check if this email type should be logged and add BCC if so.
     *
     * Call this in your build() method after setting up the email.
     *
     * @param string $emailType The email type name (e.g., 'Welcome', 'ChatNotification')
     */
    protected function applyLogging(string $emailType): static
    {
        $logTypes = config('freegle.mail.log_types', '');
        $logAddress = config('freegle.mail.log_address', '');

        if (empty($logTypes) || empty($logAddress)) {
            return $this;
        }

        // Parse comma-separated list of types to log.
        $typesToLog = array_map('trim', explode(',', $logTypes));

        if (in_array($emailType, $typesToLog, TRUE)) {
            $this->bcc($logAddress);
        }

        return $this;
    }

    /**
     * Check if a specific email type should be logged.
     *
     * @param string $emailType The email type name
     * @return bool
     */
    protected function shouldLog(string $emailType): bool
    {
        $logTypes = config('freegle.mail.log_types', '');
        $logAddress = config('freegle.mail.log_address', '');

        if (empty($logTypes) || empty($logAddress)) {
            return FALSE;
        }

        $typesToLog = array_map('trim', explode(',', $logTypes));
        return in_array($emailType, $typesToLog, TRUE);
    }
}
