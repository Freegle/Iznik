<?php

namespace App\Services\Mail;

use App\Models\UserEmail;
use App\Services\Mail\Incoming\BounceService;
use Illuminate\Support\Facades\Log;

/**
 * Classifies SMTP / mailer exceptions and records permanent bounces.
 *
 * Permanent failures (550 etc, malformed local-part, non-ASCII addresses)
 * indicate the recipient address itself is invalid and retrying will
 * never succeed — these should mark the address as bouncing so no future
 * job tries to send to it. Transient failures (connect-refused, timed-out
 * to mail-host:25 at container startup) should NOT be marked as permanent.
 *
 * Extracted from EmailSpoolerService so direct-send paths (mail:mod-notifs,
 * mail:engage, mail:donations:*, etc.) can use the same classification
 * without duplicating the regex list.
 */
class SmtpFailureClassifier
{
    /**
     * Permanent SMTP failures: recipient is invalid, will never succeed.
     * Each pattern is matched case-insensitively where the /i flag is set.
     */
    private const PERMANENT_PATTERNS = [
        // RFC 5321 permanent failure codes (5xx).
        '/\b550\b/',                          // Mailbox unavailable
        '/\b551\b/',                          // User not local
        '/\b552\b/',                          // Exceeded storage allocation
        '/\b553\b/',                          // Mailbox name not allowed
        '/\b554\b/',                          // Transaction failed
        '/\b501\s+5\.1\.3\b/',                // Bad recipient address syntax
        '/\b5\.[01]\.[13]\b/',                // Bad destination / address syntax
        '/\b5\.2\.1\b/',                      // Mailbox disabled
        // Address format errors (caught before SMTP).
        '/non-ASCII characters/i',
        '/Invalid address/i',
        '/Bad recipient address syntax/i',
        // Symfony's Mime\RfcComplianceException: thrown for addresses with
        // mojibake leading characters (e.g. U+200F RTL mark prefixed onto a
        // gmail address). Production hit this on 2026-05-16 16:50 in
        // mail:engage — the address would never deliver, so mark bouncing
        // and skip instead of crashing the per-user loop.
        '/does not comply with addr-spec of RFC 2822/i',
    ];

    /**
     * Transient SMTP failures: connect-refused, timed-out, etc. Worth retrying
     * (and worth logging at warning, not error, to avoid Sentry noise during
     * container-startup ordering issues).
     */
    private const TRANSIENT_PATTERNS = [
        '/Connection refused/i',
        // Symfony's TransportException formats the host as `"%s"`, e.g.
        //   `Connection to "mail-host:25" timed out.`
        // The previous `[^"]+` required at least one non-quote char between
        // `to ` and the host, so the regex didn't match and the exception
        // re-threw — production hit this on 2026-05-15 07:31 and 08:01.
        // Match the quoted host explicitly.
        '/Connection (?:to|with) "[^"]+" timed out/i',
        '/Connection could not be established/i',
        '/stream_socket_client\(\): Unable to connect/i',
        '/Connection reset by peer/i',
        '/has been closed unexpectedly/i',
        '/\b421\b/',                          // Service not available
    ];

    public function isPermanent(string $errorMessage): bool
    {
        foreach (self::PERMANENT_PATTERNS as $pattern) {
            if (preg_match($pattern, $errorMessage)) {
                return true;
            }
        }
        return false;
    }

    public function isTransient(string $errorMessage): bool
    {
        foreach (self::TRANSIENT_PATTERNS as $pattern) {
            if (preg_match($pattern, $errorMessage)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Record a permanent bounce for the recipient via BounceService. Failures
     * to record are caught so they don't cascade into the caller's failure
     * handling.
     */
    public function recordPermanentBounce(
        string $recipientEmail,
        string $errorMessage,
        ?string $traceId = null
    ): void {
        try {
            $userEmail = UserEmail::where('email', $recipientEmail)->first();

            if ($userEmail === null) {
                Log::debug('SMTP bounce recipient not in users_emails', [
                    'email' => $recipientEmail,
                ]);
                return;
            }

            $bounceService = app(BounceService::class);
            $bounceService->recordBounce(
                $userEmail->id,
                'SMTP rejection: ' . $errorMessage,
                true,
            );

            if ($traceId !== null) {
                $bounceService->updateEmailTracking($traceId, $recipientEmail);
            }

            $suspended = $bounceService->checkAndSuspendUser($userEmail->userid);

            Log::info('Recorded SMTP bounce', [
                'email'     => $recipientEmail,
                'user_id'   => $userEmail->userid,
                'suspended' => $suspended,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to record SMTP bounce', [
                'error'     => $e->getMessage(),
                'recipient' => $recipientEmail,
            ]);
        }
    }
}
