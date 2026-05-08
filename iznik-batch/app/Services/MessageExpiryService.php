<?php

namespace App\Services;

use App\Mail\Message\DeadlineReached;
use App\Mail\Traits\FeatureFlags;
use App\Models\Message;
use App\Models\MessageGroup;
use App\Models\MessageOutcome;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class MessageExpiryService
{
    use FeatureFlags;

    public const EMAIL_TYPE = 'MessageExpiry';
    /**
     * Default number of days to look back for messages.
     */
    public const EXPIRE_LOOKBACK_DAYS = 90;

    /**
     * Process messages that have reached their deadline.
     */
    public function processDeadlineExpired(bool $dryRun = false): array
    {
        $stats = [
            'processed' => 0,
            'emails_sent' => 0,
            'errors' => 0,
        ];

        if (!self::isEmailTypeEnabled(self::EMAIL_TYPE)) {
            Log::info('MessageExpiry emails disabled via FREEGLE_MAIL_ENABLED_TYPES');
            return $stats;
        }

        $messages = $this->getMessagesWithExpiredDeadline();

        foreach ($messages as $message) {
            try {
                if ($dryRun) {
                    Log::info("Dry run: would expire message #{$message->id}: {$message->subject}");
                    $stats['processed']++;
                    $stats['emails_sent']++;
                    continue;
                }

                $this->markAsExpired($message);
                if ($this->sendDeadlineNotification($message)) {
                    $stats['emails_sent']++;
                }
                $stats['processed']++;
            } catch (\Exception $e) {
                Log::error("Error processing expired message {$message->id}: " . $e->getMessage());
                $stats['errors']++;
            }
        }

        return $stats;
    }

    /**
     * Get messages that have reached their deadline without an outcome.
     */
    protected function getMessagesWithExpiredDeadline(): Collection
    {
        $earliestDate = now()->subDays(self::EXPIRE_LOOKBACK_DAYS);

        return Message::select('messages.*')
            ->join('messages_groups', 'messages_groups.msgid', '=', 'messages.id')
            ->leftJoin('messages_outcomes', 'messages_outcomes.msgid', '=', 'messages.id')
            ->where('messages.arrival', '>=', $earliestDate)
            ->whereNotNull('messages.deadline')
            ->whereRaw('messages.deadline < CURDATE()')
            ->whereNull('messages_outcomes.id')
            ->distinct()
            ->get();
    }

    /**
     * Mark a message as expired.
     *
     * V1 mark() also clears messages_outcomes_intended first — replicate that.
     */
    protected function markAsExpired(Message $message): void
    {
        DB::table('messages_outcomes_intended')->where('msgid', $message->id)->delete();

        MessageOutcome::create([
            'msgid' => $message->id,
            'outcome' => MessageOutcome::OUTCOME_EXPIRED,
            'comments' => 'Reached deadline',
            'timestamp' => now(),
        ]);

        Log::info("Deadline expired for message #{$message->id}: {$message->subject}");
    }

    /**
     * Send a notification email about the deadline.
     *
     * Returns true if email was sent.
     */
    protected function sendDeadlineNotification(Message $message): bool
    {
        $user = $message->fromUser;

        if (!$user || !$user->email_preferred) {
            return false;
        }

        Mail::send(new DeadlineReached($message, $user));
        return true;
    }

    /**
     * Process messages that are expired based on spatial index.
     */
    public function processExpiredFromSpatialIndex(bool $dryRun = false): int
    {
        $count = 0;

        $messages = DB::table('messages_spatial')
            ->where('successful', 0)
            ->pluck('msgid');

        foreach ($messages as $msgid) {
            try {
                $message = Message::find($msgid);
                if ($message) {
                    if ($dryRun) {
                        Log::info("Dry run: would expire spatial message #{$msgid}");
                        $count++;
                        continue;
                    }

                    $this->processMessageExpiry($message);
                    $count++;
                }
            } catch (\Exception $e) {
                Log::error("Error processing spatial index expiry for {$msgid}: " . $e->getMessage());
            }

            if ($count % 100 === 0) {
                Log::info("Processed {$count} spatial index messages");
            }
        }

        return $count;
    }

    /**
     * Process expiry for a single message based on group repost settings.
     */
    protected function processMessageExpiry(Message $message): void
    {
        // Check if message has already been marked as expired or has an outcome.
        $hasOutcome = MessageOutcome::where('msgid', $message->id)->exists();

        if (!$hasOutcome) {
            // Mark as expired.
            MessageOutcome::create([
                'msgid' => $message->id,
                'outcome' => MessageOutcome::OUTCOME_EXPIRED,
                'comments' => 'Auto-expired based on group settings',
                'timestamp' => now(),
            ]);
        }

        // Remove from spatial index.
        DB::table('messages_spatial')
            ->where('msgid', $message->id)
            ->delete();
    }
}
