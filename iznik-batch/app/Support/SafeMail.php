<?php

namespace App\Support;

use App\Services\Mail\SmtpFailureClassifier;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Generic wrapper around `Mail::to($email)->send($mailable)` that catches
 * permanent address-rejection failures (non-ASCII local-part, malformed
 * address, 550 etc) and marks the recipient as bouncing instead of letting
 * the exception kill the batch job and escalate to Sentry.
 *
 * Use anywhere a batch job sends mail to user-supplied email addresses.
 * Returns `true` on send, `false` if the address was treated as bouncing,
 * and re-throws anything that isn't classified as a permanent failure (so
 * transient connect errors etc still bubble up and stop the job — those
 * deserve attention).
 */
class SafeMail
{
    /**
     * Send a mailable to a single email address with bounce-on-invalid
     * protection. Uses Mail::to() so the caller doesn't need the mailable's
     * envelope to declare the recipient.
     */
    public static function send(Mailable $mailable, string $email, ?string $name = null): bool
    {
        return self::guard(
            fn () => Mail::to($email, $name)->send($mailable),
            $mailable,
            $email,
        );
    }

    /**
     * Send a mailable that already sets its recipient via its own envelope()
     * (i.e. Mail::send($mailable) style — avoids duplicate recipients that
     * would happen if we also used Mail::to). Pass the recipient email
     * separately so we can still mark it as bouncing on permanent failure.
     */
    public static function sendMailable(Mailable $mailable, string $recipientEmail): bool
    {
        return self::guard(
            fn () => Mail::send($mailable),
            $mailable,
            $recipientEmail,
        );
    }

    private static function guard(callable $send, Mailable $mailable, string $email): bool
    {
        try {
            $send();
            return true;
        } catch (\Throwable $e) {
            $classifier = app(SmtpFailureClassifier::class);

            if ($classifier->isPermanent($e->getMessage())) {
                $classifier->recordPermanentBounce($email, $e->getMessage());

                Log::warning('Skipped permanent-failure recipient and marked as bouncing', [
                    'mailable'  => get_class($mailable),
                    'recipient' => $email,
                    'error'     => $e->getMessage(),
                ]);

                return false;
            }

            // Not a permanent failure — let it propagate so transient/unknown
            // errors still stop the job loudly.
            throw $e;
        }
    }
}
