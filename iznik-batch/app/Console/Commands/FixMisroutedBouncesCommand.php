<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fix bounces that were misrouted to chat review instead of being processed as bounces.
 *
 * This happened because handleChatNotificationReply() didn't check isBounce() before
 * creating chat messages. Bounces arriving at notify-{chatId}-{userId}@ addresses
 * were incorrectly treated as chat messages.
 */
class FixMisroutedBouncesCommand extends Command
{
    protected $signature = 'mail:fix-misrouted-bounces
                            {--days=5 : Number of days to look back}
                            {--dry-run : Show what would be done without making changes}
                            {--delete-messages : Delete the misrouted chat messages after processing}';

    protected $description = 'Fix bounces that were incorrectly routed to chat review';

    // Patterns that identify postfix bounce messages
    private const BOUNCE_PATTERNS = [
        'This is the mail system at host',
        "I'm sorry to have to inform you that your message could not",
        'be delivered to one or more recipients',
        'Undelivered Mail Returned to Sender',
        'The mail system',
    ];

    // Patterns to extract the bounced email address
    private const EMAIL_EXTRACT_PATTERNS = [
        // <email@example.com>: host ... said: 550 ...
        '/<([^>]+@[^>]+)>\s*:\s*host\s+\S+.*?said:\s*(\d{3}\s+\d\.\d\.\d.*?)(?:\r?\n|$)/is',
        // <email@example.com>: ... (generic)
        '/<([^>]+@[^>]+)>/i',
    ];

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $dryRun = $this->option('dry-run');
        $deleteMessages = $this->option('delete-messages');

        $this->info("Looking for misrouted bounces from the last {$days} days...");
        $this->info('Checking ALL chat messages (including already reviewed ones)');
        if ($dryRun) {
            $this->warn('DRY RUN - no changes will be made');
        }

        $startDate = now()->subDays($days)->startOfDay();

        // Find chat messages that look like bounces - check ALL messages regardless of review status
        // Bounces may have been approved/skipped by moderators already
        $suspectMessages = DB::table('chat_messages')
            ->where('date', '>=', $startDate)
            ->where(function ($query) {
                // Match messages containing bounce indicators
                $query->where('message', 'like', '%This is the mail system at host%')
                    ->orWhere('message', 'like', '%could not be delivered%')
                    ->orWhere('message', 'like', '%Undelivered Mail Returned to Sender%');
            })
            ->orderBy('date', 'desc')
            ->get(['id', 'chatid', 'userid', 'date', 'message', 'reviewrequired', 'reviewedby']);

        $pendingReview = $suspectMessages->where('reviewrequired', 1)->count();
        $alreadyReviewed = $suspectMessages->where('reviewrequired', 0)->count();

        $this->info("Found {$suspectMessages->count()} potential misrouted bounces");
        $this->line("  Still pending review: {$pendingReview}");
        $this->line("  Already reviewed/approved: {$alreadyReviewed}");

        $processed = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($suspectMessages as $msg) {
            $this->line('');
            $this->info("Processing chat message ID: {$msg->id}");
            $this->line("  Date: {$msg->date}");
            $this->line("  Chat ID: {$msg->chatid}");
            $this->line("  User ID: {$msg->userid}");
            $this->line("  Review required: ".($msg->reviewrequired ? 'Yes' : 'No'));

            // Verify this looks like a bounce
            if (! $this->looksLikeBounce($msg->message)) {
                $this->warn("  Skipping - doesn't match bounce patterns");
                $skipped++;

                continue;
            }

            // Extract the bounced email address
            $bouncedEmail = $this->extractBouncedEmail($msg->message);
            if ($bouncedEmail === null) {
                $this->warn("  Skipping - couldn't extract bounced email address");
                $this->line('  Message preview: '.substr($msg->message, 0, 200));
                $skipped++;

                continue;
            }

            $this->info("  Bounced email: {$bouncedEmail}");

            // Extract diagnostic info
            $diagnostic = $this->extractDiagnostic($msg->message);
            $isPermanent = $this->isPermanentBounce($diagnostic);
            $this->line("  Diagnostic: ".substr($diagnostic, 0, 100));
            $this->line('  Permanent: '.($isPermanent ? 'Yes' : 'No'));

            // Find the user with this email
            $emailRecord = DB::table('users_emails')
                ->where('email', $bouncedEmail)
                ->first();

            if ($emailRecord === null) {
                $this->warn("  Skipping - email not found in users_emails");
                $skipped++;

                continue;
            }

            $this->line("  Found user ID: {$emailRecord->userid}");

            if ($emailRecord->bounced !== null) {
                $this->line("  Email already marked as bounced: {$emailRecord->bounced}");
            }

            if (! $dryRun) {
                try {
                    // Record the bounce
                    DB::table('bounces_emails')->insert([
                        'emailid' => $emailRecord->id,
                        'date' => now(),
                        'reason' => $diagnostic,
                        'permanent' => $isPermanent,
                    ]);

                    // Mark email as bounced (only for permanent bounces)
                    if ($isPermanent && $emailRecord->bounced === null) {
                        DB::table('users_emails')
                            ->where('id', $emailRecord->id)
                            ->update(['bounced' => now()]);
                        $this->info("  Marked email as bounced");
                    }

                    // Check if user should be suspended
                    $this->checkAndSuspendUser($emailRecord->userid);

                    // Optionally delete the chat message
                    if ($deleteMessages) {
                        DB::table('chat_messages')->where('id', $msg->id)->delete();
                        $this->info("  Deleted chat message");
                    } elseif ($msg->reviewrequired) {
                        // Mark as reviewed so it doesn't show in review queue
                        // Use NULL for reviewedby (FK constraint doesn't allow 0)
                        DB::table('chat_messages')
                            ->where('id', $msg->id)
                            ->update(['reviewrequired' => 0, 'reviewedby' => null]);
                        $this->info("  Cleared review flag");
                    }

                    Log::info('Fixed misrouted bounce', [
                        'chat_message_id' => $msg->id,
                        'bounced_email' => $bouncedEmail,
                        'user_id' => $emailRecord->userid,
                        'permanent' => $isPermanent,
                    ]);

                    $processed++;
                } catch (\Exception $e) {
                    $this->error("  Error: {$e->getMessage()}");
                    $errors++;
                }
            } else {
                $this->info('  [DRY RUN] Would record bounce and update email');
                $processed++;
            }
        }

        $this->line('');
        $this->info('Summary:');
        $this->line("  Processed: {$processed}");
        $this->line("  Skipped: {$skipped}");
        $this->line("  Errors: {$errors}");

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function looksLikeBounce(string $message): bool
    {
        $matchCount = 0;
        foreach (self::BOUNCE_PATTERNS as $pattern) {
            if (stripos($message, $pattern) !== false) {
                $matchCount++;
            }
        }

        // Require at least 2 patterns to match to reduce false positives
        return $matchCount >= 2;
    }

    private function extractBouncedEmail(string $message): ?string
    {
        foreach (self::EMAIL_EXTRACT_PATTERNS as $pattern) {
            if (preg_match($pattern, $message, $matches)) {
                return strtolower(trim($matches[1]));
            }
        }

        return null;
    }

    private function extractDiagnostic(string $message): string
    {
        // Try to extract the SMTP error line
        // Pattern: said: 550 5.7.1 Unfortunately, messages from...
        if (preg_match('/said:\s*(.+?)(?:\r?\n|$)/i', $message, $matches)) {
            return trim($matches[1]);
        }

        // Fallback: extract any line with a 5xx error code
        if (preg_match('/\b(5\d{2}\s+\d\.\d\.\d\s+.+?)(?:\r?\n|$)/i', $message, $matches)) {
            return trim($matches[1]);
        }

        return 'Bounce detected from chat message';
    }

    private function isPermanentBounce(string $diagnostic): bool
    {
        // 5.x.x status codes indicate permanent failures
        if (preg_match('/\b5\.\d\.\d\b/', $diagnostic)) {
            return true;
        }

        // Check for 5xx SMTP codes
        if (preg_match('/\b5[0-5]\d\b/', $diagnostic)) {
            return true;
        }

        // Check for known permanent failure phrases
        $permanentPhrases = [
            'User unknown',
            'mailbox unavailable',
            'does not exist',
            'no such user',
            '550 5.1.1',
            '550-5.1.1',
        ];

        foreach ($permanentPhrases as $phrase) {
            if (stripos($diagnostic, $phrase) !== false) {
                return true;
            }
        }

        return false;
    }

    private function checkAndSuspendUser(int $userId): void
    {
        // Get user's preferred email
        $preferredEmail = DB::table('users_emails')
            ->where('userid', $userId)
            ->where('preferred', 1)
            ->first();

        if ($preferredEmail === null) {
            return;
        }

        // Count permanent bounces on preferred email
        $permanentCount = DB::table('bounces_emails')
            ->where('emailid', $preferredEmail->id)
            ->where('permanent', 1)
            ->where('reset', 0)
            ->count();

        // Suspend if 1+ permanent bounces (industry standard)
        if ($permanentCount >= 1) {
            $user = DB::table('users')->where('id', $userId)->first();
            if ($user && ! $user->bouncing) {
                DB::table('users')
                    ->where('id', $userId)
                    ->update(['bouncing' => 1]);

                Log::info('Suspended user mail due to bounces', [
                    'user_id' => $userId,
                    'permanent_bounce_count' => $permanentCount,
                ]);

                $this->info("  Suspended mail for user {$userId}");
            }
        }
    }
}
