<?php

namespace App\Services;

use App\Mail\Message\DeadlineReached;
use App\Mail\Traits\FeatureFlags;
use App\Models\Message;
use App\Models\MessageGroup;
use App\Models\MessageOutcome;
use App\Models\User;
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

    /** How far back the age-based expiry pass looks for live postings, in days. */
    public const AGE_EXPIRY_LOOKBACK_DAYS = 730;

    /** Most posts the age-based expiry pass withdraws in one run (oldest first). */
    public const AGE_EXPIRY_BATCH = 20000;

    /**
     * Full Message models fetched at a time by the deadline pass. These carry the
     * text columns, so the whole candidate set at once exhausts memory.
     */
    public const EXPIRE_CHUNK = 500;

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
    protected function getMessagesWithExpiredDeadline(): \Illuminate\Support\LazyCollection
    {
        $earliestDate = now()->subDays(self::EXPIRE_LOOKBACK_DAYS);

        // Two passes, because one is unaffordable.
        //
        // This used to be a single `SELECT DISTINCT messages.*` joined to
        // messages_groups and messages_outcomes, paginated with lazyById. The
        // `ORDER BY messages.id LIMIT 500` that lazyById adds made the optimiser
        // walk the PRIMARY key from the start of the table rather than range-scan
        // `arrival` - it expects to fill 500 rows quickly and stop. It does not:
        // the matches are all recent, so it traverses the old ids first. Measured
        // live on 2026-09-18 that was 6,301,473 rows examined and 93 seconds, to
        // find a candidate set of 7,712. The DISTINCT, needed only because the
        // messages_groups join multiplies a post by the groups it rippled to,
        // added a temporary table over every column of messages including the
        // text.
        //
        // So: collect the ids first with no LIMIT, which leaves the optimiser no
        // reason to prefer the PRIMARY key (295,588 rows examined, 0.4-1.0s), then
        // fetch the full models by id in chunks. Same streaming, same memory.
        $ids = Message::query()
            ->where('messages.arrival', '>=', $earliestDate)
            ->whereNotNull('messages.deadline')
            ->whereRaw('messages.deadline < CURDATE()')
            // A post with no group is not on the site, so it cannot expire off it.
            // This is a static property of the post, so it belongs in the pass that
            // runs once rather than in the one that runs per chunk.
            ->whereExists(fn ($q) => $q->selectRaw('1')
                ->from('messages_groups')
                ->whereColumn('messages_groups.msgid', 'messages.id'))
            ->orderBy('messages.id')
            ->pluck('messages.id')
            ->all();

        return \Illuminate\Support\LazyCollection::make(function () use ($ids) {
            foreach (array_chunk($ids, self::EXPIRE_CHUNK) as $chunk) {
                // The outcome check is re-run per chunk rather than folded into the
                // pass above. Expiring a post writes an outcome, and a member can
                // mark one TAKEN while this is running; either would make an id
                // collected earlier stale. Re-checking keeps what the keyset
                // pagination used to give, at the cost of one indexed lookup per row.
                $messages = Message::select('messages.*')
                    ->whereIn('messages.id', $chunk)
                    ->whereNotExists(fn ($q) => $q->selectRaw('1')
                        ->from('messages_outcomes')
                        ->whereColumn('messages_outcomes.msgid', 'messages.id'))
                    ->orderBy('messages.id')
                    ->get();

                foreach ($messages as $message) {
                    yield $message;
                }
            }
        });
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

        app(\App\Services\EmailSpoolerService::class)->spool(new DeadlineReached($message, $user));
        return true;
    }

    /**
     * Age-based auto-withdrawal (and spatial cleanup). Mirrors V1 cron/messages_expired.php's
     * spatial loop, which iterated every messages_spatial.successful=0 row and called
     * Message::processExpiry(); candidates now come from the live postings themselves, see
     * getExpiredCandidates(). That V1 method reads getPublic(), which computes a
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
     * rather than calling getPublic() on every post. Returns msgids to auto-withdraw
     * (and whose spatial row, if any, should be cleaned up).
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
     *
     * Expiry is judged across the message's LIVE postings only, and a message
     * expires when EVERY live Approved posting is past its group's threshold —
     * i.e. the most generous group wins, matching what the Go API's
     * computeExpiresat() displays to the poster. Two earlier behaviours here
     * were wrong (2,400+ posts wrongly withdrawn between 13 May and 11 Aug 2026):
     *   - ANY-group semantics: one rippled-in copy on a group with
     *     maxagetoshow=0 (an 18-day threshold) expired the whole message even
     *     though the poster's home group gave it 90 days.
     *   - No deleted/collection filter: copies rippling had already retracted
     *     (messages_groups.deleted=1, arrival frozen at retraction) still
     *     counted, so a dead copy could expire the live post.
     * A message with NO live Approved posting left is not expired here — any
     * spatial row it still has is cleaned up by MessageSpatialService's
     * removeDeleted / removeNonApproved passes instead.
     */
    protected function getExpiredCandidates(): \Illuminate\Support\Collection
    {
        // Candidates are the LIVE postings (messages_groups, Approved, not deleted), not the
        // spatial index. MessageSpatialService keeps only the last RECENT_DAYS (31) of posts,
        // while a group's expiry threshold is routinely longer (90 days by default; a WANTED
        // with the default repost settings is 42), so a post read from the index lost its row
        // before it ever came due and was never withdrawn: tens of thousands of live posts
        // three months to a year old on production (Discourse 9808/806).
        //
        // The scan is bounded to postings that arrived in the last AGE_EXPIRY_LOOKBACK_DAYS
        // (using the arrival index) and returns at most AGE_EXPIRY_BATCH of the oldest each
        // run, so a backlog drains over a few daily runs rather than in one long statement.
        $sql = <<<'SQL'
SELECT m.id AS msgid, MIN(live.arrival) AS first_arrival
FROM messages_groups live
JOIN messages m ON m.id = live.msgid
LEFT JOIN messages_promises mp ON mp.msgid = m.id
WHERE live.collection = 'Approved'
  AND live.deleted = 0
  AND live.arrival > DATE_SUB(NOW(), INTERVAL ? DAY)
  AND m.deleted IS NULL
  AND m.type IN ('Offer', 'Wanted')
  AND mp.id IS NULL
  AND (
    EXISTS (
      SELECT 1 FROM messages_outcomes mo
      WHERE mo.msgid = m.id AND mo.outcome = ?
    )
    OR (
      live.arrival < DATE_SUB(NOW(), INTERVAL 1 DAY)
      AND NOT EXISTS (
        SELECT 1 FROM messages_outcomes mo2 WHERE mo2.msgid = m.id
      )
      AND NOT EXISTS (
        SELECT 1 FROM messages_groups mg
        JOIN `groups` g ON g.id = mg.groupid
        WHERE mg.msgid = m.id
          AND mg.deleted = 0
          AND mg.collection = 'Approved'
          AND TIMESTAMPDIFF(DAY, mg.arrival, NOW()) <= GREATEST(
            COALESCE(JSON_EXTRACT(g.settings, '$.maxagetoshow') + 0, 90),
            CASE m.type
              WHEN 'Offer' THEN COALESCE(JSON_EXTRACT(g.settings, '$.reposts.offer')  + 0, 3)
                              * (COALESCE(JSON_EXTRACT(g.settings, '$.reposts.max') + 0, 5) + 1)
              ELSE          COALESCE(JSON_EXTRACT(g.settings, '$.reposts.wanted') + 0, 7)
                              * (COALESCE(JSON_EXTRACT(g.settings, '$.reposts.max') + 0, 5) + 1)
            END
          )
      )
      AND NOT EXISTS (
        SELECT 1 FROM chat_messages cm
        JOIN chat_messages cm2 ON cm2.chatid = cm.chatid
        WHERE cm.refmsgid = m.id AND cm.type != 'ModMail'
          AND cm2.date > DATE_SUB(NOW(), INTERVAL 6 DAY)
      )
    )
  )
GROUP BY m.id
ORDER BY first_arrival
LIMIT ?
SQL;

        // keep-raw: the per-group threshold is arithmetic over JSON_EXTRACT values inside GREATEST/CASE in a correlated NOT EXISTS, and the ordering is over an aggregate of the outer join; the builder cannot render either without falling back to raw fragments anyway.
        return collect(DB::select($sql, [
            self::AGE_EXPIRY_LOOKBACK_DAYS,
            MessageOutcome::OUTCOME_EXPIRED,
            self::AGE_EXPIRY_BATCH,
        ]))->pluck('msgid');
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
