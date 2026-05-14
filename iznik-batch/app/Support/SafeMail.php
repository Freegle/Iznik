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
            $msg = $e->getMessage();

            if ($classifier->isPermanent($msg)) {
                // Bad address (non-ASCII local-part, 5xx etc) — mark bouncing
                // and skip. Log::warning so it doesn't escalate to Sentry.
                $classifier->recordPermanentBounce($email, $msg);

                Log::warning('Skipped permanent-failure recipient and marked as bouncing', [
                    'mailable'  => get_class($mailable),
                    'recipient' => $email,
                    'error'     => $msg,
                ]);

                return false;
            }

            if ($classifier->isTransient($msg)) {
                // Connection reset / timed-out / closed-unexpectedly — mail-host
                // hiccup, NOT the recipient's fault. Skip this one and let the
                // surrounding loop keep moving so a single SMTP blip doesn't
                // kill (e.g.) a 50k-recipient engage run. Log::warning so it
                // doesn't escalate to Sentry as an error.
                Log::warning('Skipped recipient on transient SMTP failure', [
                    'mailable'  => get_class($mailable),
                    'recipient' => $email,
                    'error'     => $msg,
                ]);

                return false;
            }

            // Unknown class of failure — let it propagate so it gets noticed
            // (Sentry / cron-log stack trace) instead of being silently
            // swallowed.
            throw $e;
        }
    }
}
