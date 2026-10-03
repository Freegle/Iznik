<?php

namespace App\Services;

use App\Helpers\MailHelper;
use App\Mail\Message\AutoRepostWarning;
use App\Mail\Traits\FeatureFlags;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AutoRepostService
{
    use FeatureFlags;

    public const EMAIL_TYPE = 'AutoRepost';
    /**
     * Only consider messages from the last N days.
     * V1: $mindate = 90 days ago (Message::EXPIRE_TIME).
     */
    public const LOOKBACK_DAYS = 90;

    /**
     * Site-wide repost settings: the value every community used, frozen as a constant.
     */
    public const DEFAULT_REPOSTS = [
        'offer' => 3,
        'wanted' => 7,
        'max' => 5,
        'chaseups' => 5,
    ];

    /**
     * Process auto-reposts for every live post.
     *
     * A post has one moderation state and one repost counter, on messages, so a repost
     * stamps messages.arrival and messages.autoreposts once and the poster is reminded
     * once per cycle.
     *
     * Side effects:
     *   - UPDATE messages SET arrival=NOW(), autoreposts=autoreposts+1
     *   - Log Autoreposted
     *   - INSERT messages_postings
     *   - UPDATE messages.lastautopostwarning for warning emails
     *   - Warning email: "Will Repost: {subject}" with completed/withdraw/promise buttons
     */
    public function process(bool $dryRun = false): array
    {
        $stats = [
            'reposted' => 0,
            'warned' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        // The feature flag controls warning EMAILS only, not the repost itself.
        // Reposts must happen even when warning emails are disabled — otherwise
        // removing AutoRepost from FREEGLE_MAIL_ENABLED_TYPES silently halts
        // all auto-reposting (the root cause of the missed reposts in #9481).
        $warningEmailEnabled = self::isEmailTypeEnabled(self::EMAIL_TYPE);

        if (!$warningEmailEnabled) {
            Log::info('AutoRepost warning emails disabled via FREEGLE_MAIL_ENABLED_TYPES; reposts will still run');
        }

        $mindate = now()->subDays(self::LOOKBACK_DAYS)->format('Y-m-d');

        try {
            $runStats = $this->processAll(self::DEFAULT_REPOSTS, $mindate, $dryRun, $warningEmailEnabled);
            $stats['reposted'] += $runStats['reposted'];
            $stats['warned'] += $runStats['warned'];
            $stats['skipped'] += $runStats['skipped'];
        } catch (\Exception $e) {
            // Transient SMTP failures during container-startup ordering shouldn't
            // escalate to Sentry — log them at warning level instead.
            $level = app(\App\Services\Mail\SmtpFailureClassifier::class)
                ->isTransient($e->getMessage()) ? 'warning' : 'error';
            Log::$level('Error processing auto-repost: ' . $e->getMessage());
            $stats['errors']++;
        }

        return $stats;
    }

    /**
     * Process auto-reposts for every candidate post.
     */
    protected function processAll(array $reposts, string $mindate, bool $dryRun, bool $warningEmailEnabled = true): array
    {
        $stats = ['reposted' => 0, 'warned' => 0, 'skipped' => 0];

        // Approved posts with no outcome, no promise, source=Platform, not deleted,
        // poster not PROHIBITED and not deleted, no deadline or a future deadline.
        $messages = $this->getCandidates($mindate, $reposts);

        $now = time();

        foreach ($messages as $msg) {
            // V1: Mail::ourDomain($message['fromaddr'])
            if (!MailHelper::isOurDomain($msg->fromaddr)) {
                $stats['skipped']++;
                continue;
            }

            if ($msg->autoreposts >= $reposts['max']) {
                $stats['skipped']++;
                continue;
            }

            // Cast the same way applyDueWindow does. The database is asked for candidates
            // using a window built from whole numbers, and this decides what to do with
            // them; if the two rounded a fraction differently, a post could pass the test
            // here that the window had already excluded, and it would never be reposted.
            // Every community stores whole numbers today, so this changes nothing now -
            // it just stops the two halves being able to disagree.
            $interval = $msg->type === Message::TYPE_OFFER
                ? (int) ($reposts['offer'] ?? 3)
                : (int) ($reposts['wanted'] ?? 7);

            // V1: max age check — messages older than interval * (max + 1) days.
            $maxAge = $interval * ((int) ($reposts['max'] ?? 5) + 1);
            if ($msg->hoursago >= $maxAge * 24) {
                $stats['skipped']++;
                continue;
            }

            // V1: user must have been active since the original post.
            // V1: NULL lastaccess → PHP NULL+1=1 → hoursago >= 1 (treated as "active").
            // We use ?? 0 to match: hoursago < 0+1 is only true for sub-hour messages.
            if ($msg->hoursago < ($msg->activehoursago ?? 0) + 1) {
                $stats['skipped']++;
                continue;
            }

            // V1: check for recent replies in chat about this message.
            //
            // This asks chat_messages about one message, and it used to be asked before
            // the two checks above - so every open post paid for it,
            // around 2.4M lookups a day, the overwhelming majority for posts that were
            // then discarded. It decides nothing that those checks do not already
            // decide first, so asking it here instead changes cost, not behaviour.
            if ($this->hasRecentReply($msg->msgid, $interval)) {
                $stats['skipped']++;
                continue;
            }

            // V1: reposts might be turned off.
            if ($interval <= 0 || ($reposts['max'] ?? 5) <= 0) {
                $stats['skipped']++;
                continue;
            }

            // V1: check user hasn't disabled autoreposts.
            $userDisabled = DB::table('users')
                ->where('id', $msg->fromuser)
                ->whereRaw("JSON_EXTRACT(settings, '$.autorepostsdisable') = true")
                ->exists();

            if ($userDisabled) {
                $stats['skipped']++;
                continue;
            }

            $lastwarnago = $msg->lastautopostwarning
                ? ($now - strtotime($msg->lastautopostwarning))
                : null;

            // V1 WARNING: within 24h window before the next repost is due.
            // arrival is reset to NOW() on every repost, so hoursago measures time since
            // the last repost (or original post for autoreposts=0). The interval-based
            // window therefore applies as-is to every cycle — multiplying by
            // (autoreposts + 1) here would push the window further out on each
            // successive repost, breaking the cadence.
            // Warning emails are gated on $warningEmailEnabled; the repost itself is not.
            if ($msg->hoursago <= $interval * 24
                && $msg->hoursago > ($interval - 1) * 24
                && (is_null($lastwarnago) || $lastwarnago > 24 * 60 * 60)
            ) {
                if (!$msg->lastautopostwarning || ($lastwarnago > 24 * 60 * 60)) {
                    if ($dryRun) {
                        Log::info("Dry run: would send repost warning for message #{$msg->msgid}");
                    } elseif ($warningEmailEnabled) {
                        DB::table('messages')
                            ->where('id', $msg->msgid)
                            ->update(['lastautopostwarning' => now()]);

                        // V1: "Will Repost: {subject}" with links to mark completed/withdraw/promise.
                        $user = User::find($msg->fromuser);
                        if ($user && $user->email_preferred) {
                            app(\App\Services\EmailSpoolerService::class)->spool(new AutoRepostWarning(
                                messageId: $msg->msgid,
                                messageSubject: $msg->subject ?? '',
                                messageType: $msg->type,
                                userId: $msg->fromuser,
                                userName: $user->displayname,
                                userEmail: $user->email_preferred,
                            ));
                        }
                    }
                    $stats['warned']++;
                }
            } elseif ($msg->hoursago > $interval * 24) {
                // V1 REPOST: message is past the next repost window.
                if ($dryRun) {
                    Log::info("Dry run: would auto-repost message #{$msg->msgid}");
                } else {
                    $this->repost($msg, $msg->autoreposts + 1, $reposts['max'] ?? 5);
                }
                $stats['reposted']++;
            } else {
                $stats['skipped']++;
            }
        }

        return $stats;
    }

    /**
     * Narrow the candidates to those that could actually be warned about or reposted.
     *
     * When a post is due is arithmetic on its arrival and the repost settings, so the
     * database can do it. It used to return every open post - about 109.5k
     * an hour across the estate - and PHP then discarded the ~98% that were not due yet,
     * having already run a chat lookup for each one.
     *
     * The band that does anything is:
     *
     *   arrival older than (interval - 1) days   - below that, neither branch fires
     *   arrival newer than interval * (max + 1)  - past that, the post has aged out
     *
     * The bounds are expressed against arrival rather than TIMESTAMPDIFF so the arrival
     * index can be used. That also makes the lower bound very slightly WIDER than the
     * PHP test, because TIMESTAMPDIFF truncates to whole hours: a post 71 hours 30
     * minutes old reports 71. The extra rows are simply handed to the same PHP checks
     * as before, so this can only admit more than it should, never fewer - which is the
     * direction that cannot lose a repost. AutoRepostDueWindowTest pins that.
     */
    protected function applyDueWindow($query, array $reposts)
    {
        $max = (int) ($reposts['max'] ?? 5);

        $bands = [];
        foreach ([Message::TYPE_OFFER => 'offer', Message::TYPE_WANTED => 'wanted'] as $type => $key) {
            $interval = (int) ($reposts[$key] ?? ($type === Message::TYPE_OFFER ? 3 : 7));

            // Reposting off for this type: PHP skips every one of them anyway.
            if ($interval <= 0 || $max <= 0) {
                continue;
            }

            $bands[$type] = [
                'earliest' => max(0, ($interval - 1) * 24),
                'latest' => $interval * ($max + 1) * 24,
            ];
        }

        if (empty($bands)) {
            // Nothing can be reposted; return a query that matches nothing
            // rather than scanning for rows PHP would discard one by one.
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function ($outer) use ($bands) {
            foreach ($bands as $type => $band) {
                $outer->orWhere(function ($q) use ($type, $band) {
                    $q->where('messages.type', $type)
                        // keep-raw: an interval expression against NOW() with a bound
                        // parameter; the query builder has no interval helper.
                        ->whereRaw('messages.arrival <= DATE_SUB(NOW(), INTERVAL ? HOUR)', [$band['earliest']])
                        ->whereRaw('messages.arrival > DATE_SUB(NOW(), INTERVAL ? HOUR)', [$band['latest']]);
                });
            }
        });
    }

    protected function getCandidates(string $mindate, ?array $reposts = null)
    {
        $query = DB::table('messages')
            ->join('users', 'messages.fromuser', '=', 'users.id')
            ->leftJoin('messages_outcomes', 'messages.id', '=', 'messages_outcomes.msgid')
            ->leftJoin('messages_promises', 'messages_promises.msgid', '=', 'messages.id')
            ->select(
                'messages.id AS msgid',
                'messages.autoreposts',
                'messages.lastautopostwarning',
                'messages.type',
                'messages.subject',
                'messages.fromaddr',
                'messages.fromuser',
                DB::raw('TIMESTAMPDIFF(HOUR, messages.arrival, NOW()) AS hoursago'),
                DB::raw('TIMESTAMPDIFF(HOUR, users.lastaccess, NOW()) AS activehoursago')
            )
            ->where('messages.arrival', '>', $mindate)
            ->where('messages.collection', Message::COLLECTION_APPROVED)
            ->whereNull('messages_outcomes.msgid')
            ->whereNull('messages_promises.msgid')
            ->whereIn('messages.type', [Message::TYPE_OFFER, Message::TYPE_WANTED])
            ->where('messages.source', Message::SOURCE_PLATFORM)
            ->whereNull('messages.deleted')
            ->where(function ($q) {
                $q->whereNull('users.postingstatus')
                    ->orWhere('users.postingstatus', '!=', 'PROHIBITED');
            })
            ->whereNull('users.deleted')
            ->whereNull('users.banned')
            ->where(function ($q) {
                $q->whereNull('messages.deadline')
                    ->orWhereRaw('messages.deadline > DATE(NOW())');
            });

        if ($reposts !== null) {
            $query = $this->applyDueWindow($query, $reposts);
        }

        return $query->get();
    }

    /**
     * Check for recent chat replies about this message.
     *
     * V1: SELECT MAX(chat_messages.date) AS max FROM chat_messages
     * WHERE chatid IN (SELECT chatid FROM chat_messages WHERE refmsgid = ? AND type != 'ModMail')
     */
    protected function hasRecentReply(int $msgid, int $intervalDays): bool
    {
        $maxDate = DB::table('chat_messages')
            ->whereIn('chatid', function ($q) use ($msgid) {
                $q->select('chatid')
                    ->from('chat_messages')
                    ->where('refmsgid', $msgid)
                    ->where('type', '!=', 'ModMail');
            })
            ->max('date');

        if (!$maxDate) {
            return false;
        }

        // V1 bug: used $interval * 60 * 60 where $interval is in days, so offer(3) checked
        // 3 hours instead of 3 days. We fix this to use the correct day-to-seconds conversion.
        return (time() - strtotime($maxDate)) < $intervalDays * 24 * 60 * 60;
    }

    /**
     * Repost a message.
     *
     * Side effects:
     *   - UPDATE messages SET arrival=NOW(), autoreposts+1
     *   - Log Autoreposted
     *   - INSERT messages_postings
     */
    protected function repost(object $msg, int $newReposts, int $maxReposts): void
    {
        DB::table('messages')
            ->where('id', $msg->msgid)
            ->update([
                'arrival' => now(),
                'autoreposts' => DB::raw('autoreposts + 1'),
            ]);

        DB::table('logs')->insert([
            'timestamp' => now(),
            'type' => 'Message',
            'subtype' => 'Autoreposted',
            'msgid' => $msg->msgid,
            'user' => $msg->fromuser,
            'text' => "$newReposts / $maxReposts",
        ]);

        DB::table('messages_postings')->insert([
            'msgid' => $msg->msgid,
            'repost' => 1,
            'autorepost' => 1,
        ]);

        Log::info("Auto-reposted message #{$msg->msgid} ({$newReposts}/{$maxReposts})");
    }

}
