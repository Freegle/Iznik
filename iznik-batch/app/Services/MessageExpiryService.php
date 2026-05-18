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
     * Process messages_spatial cleanup. Mirrors V1 cron/messages_expired.php's spatial loop,
     * which iterates every messages_spatial.successful=0 row and calls
     * Message::processExpiry(). That V1 method reads getPublic(), which computes a
     * *virtual* OUTCOME_EXPIRED at runtime when:
     *   - No existing outcome AND
     *   - Group arrival is older than max(maxreposts, maxagetoshow) AND
     *   - No chat reply in the last 6 days AND
     *   - No promise.
     * (Also acts when a real OUTCOME_EXPIRED row already exists.)
     *
     * When the (virtual or real) EXPIRED is present, V1 deletes the spatial row and
     * inserts an OUTCOME_WITHDRAWN "Auto-expired".
     *
     * Earlier this method required a real OUTCOME_EXPIRED row to exist, which broke
     * age-based expiry entirely (~389 prod posts overdue at fix time): the only writer
     * of OUTCOME_EXPIRED is the deadline-based path in processDeadlineExpired().
     */
    public function processExpiredFromSpatialIndex(bool $dryRun = false): int
    {
        $count = 0;

        $msgids = $this->getExpiredCandidates();

        foreach ($msgids as $msgid) {
            try {
                if ($dryRun) {
                    Log::info("Dry run: would auto-withdraw spatial message #{$msgid}");
                    $count++;
                    continue;
                }

                $this->processMessageExpiry((int) $msgid);
                $count++;
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
     * V1-equivalent candidate selection — pushes the virtual-expiry filter into SQL
     * rather than calling getPublic() on every spatial row. Returns msgids whose
     * spatial row should be cleaned up.
     *
     * The formula is symmetric for Offer and Wanted: repost_interval × (max+1).
     * V1 Message::getPublic() applies the same formula to both types; only the
     * parameter values differ (offer interval is shorter than wanted interval).
     *
     * Fallback defaults match Group::defaultSettings (offer=3, wanted=7, max=5).
     * V1 Message::getPublic() used different fallbacks (wanted=14, max=10) that
     * caused groups without stored reposts settings to compute a 154-day WANTED
     * threshold regardless of maxagetoshow — posts hidden from display for months
     * before being auto-expired. Groups that have stored settings are unaffected
     * (JSON_EXTRACT reads their actual values).
     */
    protected function getExpiredCandidates(): \Illuminate\Support\Collection
    {
        $sql = <<<'SQL'
SELECT DISTINCT ms.msgid
FROM messages_spatial ms
JOIN messages m ON m.id = ms.msgid
JOIN messages_groups mg ON mg.msgid = ms.msgid
JOIN `groups` g ON g.id = mg.groupid
LEFT JOIN messages_promises mp ON mp.msgid = ms.msgid
WHERE ms.successful = 0
  AND mp.id IS NULL
  AND (
    EXISTS (
      SELECT 1 FROM messages_outcomes mo
      WHERE mo.msgid = ms.msgid AND mo.outcome = ?
    )
    OR (
      NOT EXISTS (
        SELECT 1 FROM messages_outcomes mo2 WHERE mo2.msgid = ms.msgid
      )
      AND TIMESTAMPDIFF(DAY, mg.arrival, NOW()) > GREATEST(
        COALESCE(JSON_EXTRACT(g.settings, '$.maxagetoshow') + 0, 90),
        CASE m.type
          WHEN 'Offer' THEN COALESCE(JSON_EXTRACT(g.settings, '$.reposts.offer')  + 0, 3)
                          * (COALESCE(JSON_EXTRACT(g.settings, '$.reposts.max') + 0, 5) + 1)
          ELSE          COALESCE(JSON_EXTRACT(g.settings, '$.reposts.wanted') + 0, 7)
                          * (COALESCE(JSON_EXTRACT(g.settings, '$.reposts.max') + 0, 5) + 1)
        END
      )
      AND NOT EXISTS (
        SELECT 1 FROM chat_messages cm
        JOIN chat_messages cm2 ON cm2.chatid = cm.chatid
        WHERE cm.refmsgid = ms.msgid AND cm.type != 'ModMail'
          AND cm2.date > DATE_SUB(NOW(), INTERVAL 6 DAY)
      )
    )
  )
SQL;

        return collect(DB::select($sql, [MessageOutcome::OUTCOME_EXPIRED]))
            ->pluck('msgid');
    }

    /**
     * Delete a spatial row and record OUTCOME_WITHDRAWN "Auto-expired".
     * Mirrors V1 Message::processExpiry() → mark(), which deletes any
     * existing outcomes before inserting. Skipping the delete previously
     * left duplicate rows in messages_outcomes whenever this path ran on
     * a message that already had an Expired row (the deadline-expiry path
     * runs first in the same scheduled job), which then caused the Go
     * outcome handler to return 409 when the owner tried to mark Taken.
     */
    protected function processMessageExpiry(int $msgid): void
    {
        DB::table('messages_outcomes_intended')->where('msgid', $msgid)->delete();
        DB::table('messages_outcomes')->where('msgid', $msgid)->delete();

        DB::table('messages_spatial')
            ->where('msgid', $msgid)
            ->delete();

        MessageOutcome::create([
            'msgid' => $msgid,
            'outcome' => MessageOutcome::OUTCOME_WITHDRAWN,
            'comments' => 'Auto-expired',
            'timestamp' => now(),
        ]);
    }
}
