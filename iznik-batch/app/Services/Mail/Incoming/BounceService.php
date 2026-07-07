<?php

namespace App\Services\Mail\Incoming;

use App\Models\EmailTracking;
use App\Models\User;
use App\Models\UserEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Sentry\Laravel\Facade as Sentry;

/**
 * Service for processing email bounce messages.
 *
 * Handles DSN parsing, bounce classification, recording, and user suspension.
 * Designed for inline processing during email routing (no separate cron job).
 *
 * Suspension thresholds:
 * - 1+ permanent bounces on preferred email (industry standard: immediate)
 * - 50+ total bounces on preferred email
 *
 * TODO: The legacy `bounces` table is obsolete - remove it in a future migration.
 */
class BounceService
{
    // Suspension thresholds
    // Industry standard: hard bounces should suspend immediately (1 occurrence)
    private const PERMANENT_THRESHOLD = 1;

    // Soft bounce threshold: X bounces within Y days indicates persistent problem
    private const SOFT_BOUNCE_THRESHOLD = 5;

    private const SOFT_BOUNCE_DAYS = 14;

    // Legacy total threshold (kept as fallback for very old bounces)
    private const TOTAL_THRESHOLD = 50;

    // Error directory for unparseable bounces
    private const ERROR_DIRECTORY = '/var/lib/freegle/bounces/error';

    // Patterns that indicate a permanent bounce
    private const PERMANENT_PATTERNS = [
        '550 Requested action not taken: mailbox unavailable',
        'Invalid recipient',
        '550 5.1.1',
        '550-5.1.1',
        '550 No Such User Here',
        "dd This user doesn't have",
        '5.0.0',  // Generic permanent failure status
        '5.1.1',  // Bad destination mailbox
        '5.1.2',  // Bad destination system
        '5.2.1',  // Mailbox disabled
        '5.2.2',  // Mailbox full (permanent after retry timeout)
    ];

    // Patterns that indicate we should ignore this bounce (temporary/infrastructure issues)
    private const IGNORE_PATTERNS = [
        'delivery temporarily suspended',
        'Trop de connexions',
        'found on industry URI blacklists',
        'This message has been blocked',
        'is listed',
    ];

    /**
     * Process a bounce message from a parsed email.
     *
     * This is the main entry point for bounce processing. It:
     * 1. Parses the DSN to extract diagnostic code and recipient
     * 2. Looks up the user by VERP address or recipient email
     * 3. Records the bounce in bounces_emails
     * 4. Performs inline suspension check
     *
     * @param  ParsedEmail  $email  The parsed bounce message
     * @return array{success: bool, error?: string, user_id?: int, suspended?: bool}
     */
    public function processBounce(ParsedEmail $email): array
    {
        Log::debug('Processing bounce', [
            'envelope_to' => $email->envelopeTo,
        ]);

        // Parse the DSN
        $dsn = $this->parseDsn($email->rawMessage);

        if ($dsn === null) {
            Log::warning('Failed to parse bounce DSN', [
                'envelope_to' => $email->envelopeTo,
            ]);

            $this->saveUnparseableBounce($email);
            $this->reportUnparseableBounce($email);

            return ['success' => false, 'error' => 'unparseable'];
        }

        $diagnosticCode = $dsn['diagnostic_code'];
        $recipientEmail = $dsn['original_recipient'];
        $traceId = $dsn['trace_id'] ?? null;

        // Check if this should be ignored (temporary/infrastructure issues)
        if ($this->shouldIgnoreBounce($diagnosticCode)) {
            Log::debug('Ignoring temporary bounce', [
                'diagnostic_code' => $diagnosticCode,
            ]);

            return ['success' => true, 'ignored' => true];
        }

        // Try to find the user - first from VERP address, then from recipient email
        $userId = $this->extractUserIdFromVerpAddress($email->envelopeTo);

        // Find the email record
        $userEmail = null;
        if ($userId !== null && $recipientEmail !== null) {
            // VERP address - look up email for this user
            $userEmail = UserEmail::where('userid', $userId)
                ->where('email', $recipientEmail)
                ->first();
        }

        if ($userEmail === null && $recipientEmail !== null) {
            // Fall back to direct email lookup
            $userEmail = UserEmail::where('email', $recipientEmail)->first();
            if ($userEmail !== null) {
                $userId = $userEmail->userid;
            }
        }

        if ($userEmail === null) {
            Log::info('Bounce recipient not found', [
                'recipient' => $recipientEmail,
                'user_id' => $userId,
            ]);

            return ['success' => false, 'error' => 'unknown_recipient'];
        }

        // Classify and record the bounce
        // Primary indicator: DSN Status code (5.x.x = permanent, 4.x.x = temporary)
        // Fallback: diagnostic pattern matching for edge cases
        $isPermanent = $email->isPermanentBounce() || $this->isPermanentBounce($diagnosticCode);
        $this->recordBounce($userEmail->id, $diagnosticCode, $isPermanent);

        // Update email_tracking record if we have a trace ID
        $trackingUpdated = $this->updateEmailTracking($traceId, $recipientEmail);

        // Inline suspension check
        $suspended = $this->checkAndSuspendUser($userEmail->userid);

        Log::info('Processed bounce', [
            'user_id' => $userEmail->userid,
            'email' => $recipientEmail,
            'permanent' => $isPermanent,
            'suspended' => $suspended,
            'trace_id' => $traceId,
            'tracking_updated' => $trackingUpdated,
        ]);

        return [
            'success' => true,
            'user_id' => $userEmail->userid,
            'suspended' => $suspended,
            'tracking_updated' => $trackingUpdated,
        ];
    }

    /**
     * Parse a DSN (Delivery Status Notification) message.
     *
     * Uses cascading extraction strategies:
     * 1. Standard DSN headers (Diagnostic-Code, Original-Recipient)
     * 2. Non-standard headers (X-Failed-Recipients, Final-Recipient)
     * 3. Heuristic body extraction for non-compliant bounces
     *
     * Also extracts X-Freegle-Trace-Id if present in the original message headers,
     * which allows linking the bounce back to the specific email_tracking record.
     *
     * @return array{diagnostic_code: string, original_recipient: ?string, trace_id: ?string}|null
     */
    public function parseDsn(string $rawMessage): ?array
    {
        $diagnosticCode = null;
        $originalRecipient = null;
        $traceId = null;

        // Strategy 1: Standard Diagnostic-Code header
        if (preg_match('/^Diagnostic-Code:\s*(.+)$/im', $rawMessage, $matches)) {
            $diagnosticCode = trim($matches[1]);
        }

        // Strategy 2: DSN Status header (e.g., "Status: 5.0.0" or "Status: 4.4.7")
        if ($diagnosticCode === null) {
            if (preg_match('/^Status:\s*([45]\.\d+\.\d+)/im', $rawMessage, $matches)) {
                // Also try to get error text from body (single line only)
                $errorText = '';
                if (preg_match('/following address\(es\) failed:.*?\n\s*\S+@\S+\n\s*([^\r\n]+)/is', $rawMessage, $textMatches)) {
                    $errorText = ' ' . trim($textMatches[1]);
                }
                $diagnosticCode = trim($matches[1]) . $errorText;
            }
        }

        // Strategy 3: Extract from body for non-standard DSNs
        if ($diagnosticCode === null) {
            // Look for 5xx error codes in body
            if (preg_match('/\b(5\d\d[\s\-][\d\.]+\s+[^\r\n]+)/i', $rawMessage, $matches)) {
                $diagnosticCode = trim($matches[1]);
            } elseif (preg_match('/\b(550[^\r\n]*)/i', $rawMessage, $matches)) {
                $diagnosticCode = trim($matches[1]);
            }
        }

        // Strategy 1: Original-Recipient header
        if (preg_match('/^Original-Recipient:\s*(?:rfc822;)?\s*(.+)$/im', $rawMessage, $matches)) {
            $originalRecipient = trim($matches[1]);
        }

        // Strategy 2: Final-Recipient header
        if ($originalRecipient === null) {
            if (preg_match('/^Final-Recipient:\s*(?:rfc822;)?\s*(.+)$/im', $rawMessage, $matches)) {
                $originalRecipient = trim($matches[1]);
            }
        }

        // Strategy 3: X-Failed-Recipients header
        if ($originalRecipient === null) {
            if (preg_match('/^X-Failed-Recipients:\s*(.+)$/im', $rawMessage, $matches)) {
                $originalRecipient = trim($matches[1]);
            }
        }

        // Strategy 4: Extract email from body text
        if ($originalRecipient === null) {
            // Look for "Delivery to the following recipient failed" pattern
            if (preg_match('/recipient.*failed.*?\n\s*([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/is', $rawMessage, $matches)) {
                $originalRecipient = trim($matches[1]);
            } elseif (preg_match('/\b([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})\b/', $rawMessage, $matches)) {
                // Last resort: any email address in the body
                $originalRecipient = trim($matches[1]);
            }
        }

        // Extract X-Freegle-Trace-Id from original message headers if present.
        // This is included in the DSN as part of the original message headers.
        // The trace ID allows us to link this bounce to the specific email_tracking record.
        if (preg_match('/^X-Freegle-Trace-Id:\s*(.+)$/im', $rawMessage, $matches)) {
            $traceId = trim($matches[1]);
        }

        // Must have at least a diagnostic code to be considered valid
        if ($diagnosticCode === null) {
            return null;
        }

        return [
            'diagnostic_code' => $diagnosticCode,
            'original_recipient' => $originalRecipient,
            'trace_id' => $traceId,
        ];
    }

    /**
     * Check if a bounce should be ignored (temporary/infrastructure issues).
     *
     * These patterns indicate the issue is with mail infrastructure, not the
     * recipient's mailbox. We don't want to penalize users for these.
     */
    public function shouldIgnoreBounce(string $diagnosticCode): bool
    {
        $codeLower = strtolower($diagnosticCode);

        foreach (self::IGNORE_PATTERNS as $pattern) {
            if (stripos($codeLower, strtolower($pattern)) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a bounce is permanent (hard bounce).
     *
     * Permanent bounces indicate the mailbox doesn't exist or is permanently
     * unavailable. These count more heavily toward suspension.
     */
    public function isPermanentBounce(string $diagnosticCode): bool
    {
        $codeLower = strtolower($diagnosticCode);

        foreach (self::PERMANENT_PATTERNS as $pattern) {
            if (stripos($codeLower, strtolower($pattern)) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Record a bounce in the bounces_emails table.
     *
     * @param  int  $emailId  The users_emails.id
     * @param  string  $reason  The diagnostic code/reason
     * @param  bool  $isPermanent  Whether this is a permanent bounce
     */
    public function recordBounce(int $emailId, string $reason, bool $isPermanent): void
    {
        DB::table('bounces_emails')->insert([
            'emailid' => $emailId,
            'reason' => $reason,
            'permanent' => $isPermanent ? 1 : 0,
            'reset' => 0,
            'date' => now(),
        ]);

        // Only update users_emails.bounced timestamp for permanent bounces.
        // This matches prior Laravel behavior and is used for display purposes.
        // Temporary bounces are tracked in bounces_emails but don't mark the
        // email as "bounced" since they may be transient issues.
        if ($isPermanent) {
            DB::table('users_emails')
                ->where('id', $emailId)
                ->update(['bounced' => now()]);
        }

        Log::debug('Recorded bounce', [
            'email_id' => $emailId,
            'permanent' => $isPermanent,
        ]);
    }

    /**
     * Update the email_tracking record to mark a bounce.
     *
     * Uses the trace ID (X-Freegle-Trace-Id) if available to find the exact
     * email_tracking record. Falls back to finding the most recent unbounced
     * email sent to the recipient address.
     *
     * @param  string|null  $traceId  The X-Freegle-Trace-Id from the original email
     * @param  string|null  $recipientEmail  The recipient email address
     * @return bool True if an email_tracking record was updated
     */
    public function updateEmailTracking(?string $traceId, ?string $recipientEmail): bool
    {
        // Strategy 1: Use trace ID for exact match
        if ($traceId !== null) {
            $updated = EmailTracking::where('tracking_id', $traceId)
                ->whereNull('bounced_at')
                ->update(['bounced_at' => now()]);

            if ($updated > 0) {
                Log::debug('Updated email_tracking via trace ID', [
                    'trace_id' => $traceId,
                ]);

                return true;
            }
        }

        // Strategy 2: Fall back to most recent unbounced email to this recipient
        if ($recipientEmail !== null) {
            $tracking = EmailTracking::where('recipient_email', $recipientEmail)
                ->whereNull('bounced_at')
                ->whereNotNull('sent_at')
                ->orderByDesc('sent_at')
                ->first();

            if ($tracking !== null) {
                $tracking->bounced_at = now();
                $tracking->save();

                Log::debug('Updated email_tracking via recipient fallback', [
                    'tracking_id' => $tracking->tracking_id,
                    'recipient_email' => $recipientEmail,
                ]);

                return true;
            }
        }

        Log::debug('No email_tracking record found to update', [
            'trace_id' => $traceId,
            'recipient_email' => $recipientEmail,
        ]);

        return false;
    }

    /**
     * Check if a user should be suspended due to bounces and suspend if so.
     *
     * Suspension thresholds (only for preferred email):
     * - 1+ permanent bounces (immediate - industry standard)
     * - 5+ soft bounces within 14 days (persistent problem)
     * - 50+ total bounces (legacy fallback)
     *
     * @return bool True if user was suspended by this call
     */
    public function checkAndSuspendUser(int $userId): bool
    {
        // Only check users who aren't already suspended
        $user = User::where('id', $userId)
            ->where('bouncing', 0)
            ->first();

        if ($user === null) {
            return false;
        }

        // Get the user's preferred email
        $preferredEmail = UserEmail::where('userid', $userId)
            ->where('preferred', 1)
            ->first();

        if ($preferredEmail === null) {
            // No preferred email - can't check bounces meaningfully
            return false;
        }

        // Count permanent bounces on preferred email (not reset)
        $permanentCount = DB::table('bounces_emails')
            ->where('emailid', $preferredEmail->id)
            ->where('permanent', 1)
            ->where('reset', 0)
            ->count();

        if ($permanentCount >= self::PERMANENT_THRESHOLD) {
            $this->suspendUser($userId);

            return true;
        }

        // Count soft bounces within the time window (persistent soft bounce problem)
        $softBounceCount = DB::table('bounces_emails')
            ->where('emailid', $preferredEmail->id)
            ->where('permanent', 0)
            ->where('reset', 0)
            ->where('date', '>=', now()->subDays(self::SOFT_BOUNCE_DAYS))
            ->count();

        if ($softBounceCount >= self::SOFT_BOUNCE_THRESHOLD) {
            $this->suspendUser($userId);

            return true;
        }

        // Legacy fallback: count all bounces on preferred email (not reset)
        $totalCount = DB::table('bounces_emails')
            ->where('emailid', $preferredEmail->id)
            ->where('reset', 0)
            ->count();

        if ($totalCount >= self::TOTAL_THRESHOLD) {
            $this->suspendUser($userId);

            return true;
        }

        return false;
    }

    /**
     * Bulk suspend all users whose preferred email has accumulated enough bounces.
     *
     * Mirrors the V1 bounce_users.php cron job. Applies the same thresholds as
     * checkAndSuspendUser() but via a single set of bulk queries rather than
     * per-user calls. Only processes users with bouncing=0.
     *
     * @return int Number of users suspended
     */
    public function suspendBouncingUsers(): int
    {
        $suspended = 0;

        // Step 1: permanent bounces (reset=0) >= PERMANENT_THRESHOLD on preferred email
        $permanentCandidates = DB::table('bounces_emails')
            ->join('users_emails', 'users_emails.id', '=', 'bounces_emails.emailid')
            ->join('users', 'users.id', '=', 'users_emails.userid')
            ->where('bounces_emails.permanent', 1)
            ->where('bounces_emails.reset', 0)
            ->where('users_emails.preferred', 1)
            ->where('users.bouncing', 0)
            ->select('users_emails.userid')
            ->groupBy('users_emails.userid')
            ->havingRaw('COUNT(*) >= ?', [self::PERMANENT_THRESHOLD])
            ->pluck('userid');

        foreach ($permanentCandidates as $userId) {
            $this->suspendUser($userId);
            $suspended++;
        }

        // Step 2: soft bounces within window >= SOFT_BOUNCE_THRESHOLD on preferred email
        $softCandidates = DB::table('bounces_emails')
            ->join('users_emails', 'users_emails.id', '=', 'bounces_emails.emailid')
            ->join('users', 'users.id', '=', 'users_emails.userid')
            ->where('bounces_emails.permanent', 0)
            ->where('bounces_emails.reset', 0)
            ->where('bounces_emails.date', '>=', now()->subDays(self::SOFT_BOUNCE_DAYS))
            ->where('users_emails.preferred', 1)
            ->where('users.bouncing', 0)
            ->select('users_emails.userid')
            ->groupBy('users_emails.userid')
            ->havingRaw('COUNT(*) >= ?', [self::SOFT_BOUNCE_THRESHOLD])
            ->pluck('userid');

        foreach ($softCandidates as $userId) {
            $this->suspendUser($userId);
            $suspended++;
        }

        // Step 3: total bounces (any, reset=0) >= TOTAL_THRESHOLD on preferred email
        $totalCandidates = DB::table('bounces_emails')
            ->join('users_emails', 'users_emails.id', '=', 'bounces_emails.emailid')
            ->join('users', 'users.id', '=', 'users_emails.userid')
            ->where('bounces_emails.reset', 0)
            ->where('users_emails.preferred', 1)
            ->where('users.bouncing', 0)
            ->select('users_emails.userid')
            ->groupBy('users_emails.userid')
            ->havingRaw('COUNT(*) >= ?', [self::TOTAL_THRESHOLD])
            ->pluck('userid');

        foreach ($totalCandidates as $userId) {
            $this->suspendUser($userId);
            $suspended++;
        }

        Log::info('Bulk bounce suspension complete', ['suspended' => $suspended]);

        return $suspended;
    }

    /**
     * Suspend a user's email delivery.
     */
    private function suspendUser(int $userId): void
    {
        DB::table('users')
            ->where('id', $userId)
            ->update(['bouncing' => 1]);

        Log::info('Suspended user mail due to bounces', [
            'user_id' => $userId,
        ]);
    }

    /**
     * Extract user ID from a VERP bounce address.
     *
     * VERP format: bounce-{userid}-{timestamp}@users.ilovefreegle.org
     *
     * @return int|null The user ID, or null if not a valid VERP address
     */
    public function extractUserIdFromVerpAddress(string $address): ?int
    {
        if (preg_match('/^bounce-(\d+)-/', $address, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Save an unparseable bounce to the error directory for later analysis.
     */
    private function saveUnparseableBounce(ParsedEmail $email): void
    {
        if (! is_dir(self::ERROR_DIRECTORY)) {
            @mkdir(self::ERROR_DIRECTORY, 0755, true);
        }

        if (is_dir(self::ERROR_DIRECTORY)) {
            $filename = self::ERROR_DIRECTORY.'/'.date('Y-m-d_His').'_'.uniqid().'.eml';
            @file_put_contents($filename, $email->rawMessage);

            Log::debug('Saved unparseable bounce', [
                'filename' => $filename,
            ]);
        }
    }

    /**
     * Report an unparseable bounce to Sentry for monitoring.
     */
    private function reportUnparseableBounce(ParsedEmail $email): void
    {
        try {
            \Sentry\withScope(function (\Sentry\State\Scope $scope) use ($email): void {
                $scope->setLevel(\Sentry\Severity::warning());
                $scope->setContext('bounce', [
                    'envelope_to' => $email->envelopeTo,
                    'raw_length' => strlen($email->rawMessage),
                    // Include first 500 chars for debugging
                    'raw_preview' => substr($email->rawMessage, 0, 500),
                ]);
                \Sentry\captureMessage('Unparseable bounce DSN');
            });
        } catch (\Throwable $e) {
            // Don't let Sentry failures break bounce processing
            Log::warning('Failed to report unparseable bounce to Sentry', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
