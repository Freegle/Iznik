<?php

namespace App\Services;

use App\Models\BackgroundTask;
use App\Models\Message;
use App\Models\MessageAutomod;
use App\Models\MessageGroup;
use App\Services\Automod\AutomodFactsService;
use App\Services\Automod\AutomodMode;
use App\Services\Automod\AutomodService;
use App\Services\Automod\QualitySample;
use App\Services\Lockdown\LockdownHoldsService;
use App\Services\Lockdown\LockdownService;
use App\Support\EnvSetting;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Auto-approve content-check-clean posts from NULL-posting-status ("auto-moderated")
 * members after a configurable delay (default 20 minutes).
 *
 * Members whose memberships.ourPostingStatus is NULL have never been given an explicit
 * posting status. Historically NULL was treated as MODERATED, so their posts sat in
 * Pending until a moderator acted or the 48-hour AutoApproveService fallback fired.
 *
 * This service reinterprets NULL as "auto-moderated": their posts are held in Pending for
 * a short window, giving moderators and microvolunteers a chance to intervene, and are
 * then released automatically. A configurable percentage is held back as a manual
 * quality-check sample.
 *
 * Posts only become eligible once the automod chart (messages:automod, AutomodService)
 * has reviewed them in approve mode and recorded an approve verdict on messages_automod
 * (plans/active/automod-flowchart.md). The chart decides from the facts as they were when it
 * ran, and the window exists so that something can change after that, so the signals that can
 * arrive inside it are read again at publish (AutomodFactsService::liveVeto), the lockdown
 * switch is honoured like every other way a post goes live by itself, and the write itself
 * carries the whole decision: it lands only on a copy that is still Pending, unheld, not
 * pulled back, past any hold, not in the quality sample, and whose approve is still current
 * (recorded in approve mode, strictly later than the last edit and the last content check,
 * and at least the delay old). A decision goes stale the moment the post is edited after the
 * chart read it, or the content check runs again.
 *
 * Trusted members (DEFAULT/UNMODERATED) are unaffected: contentcheck already approves
 * their clean posts immediately. Explicit MODERATED/PROHIBITED members are unaffected too.
 *
 * Every publish and every skip is written to Loki through LokiService (the batch container's
 * own log file does not reach Loki), with what the decision was and what it covered.
 */
class AutoApproveCleanService
{
    /** The loop name the lockdown presser sees take effect (LockdownService::ACK_LOOPS). */
    private const LOCKDOWN_LOOP = 'auto-approve-clean';

    public function __construct(
        private readonly ?LokiService $loki = null,
        private readonly ?LockdownService $lockdown = null,
        private readonly ?AutomodFactsService $facts = null
    ) {
    }

    /** How long a clean post waits in Pending before it publishes itself: the same for every community. */
    public function delayMinutes(): int
    {
        return EnvSetting::positiveInt(config('freegle.autoapprove.delay_minutes'), 20);
    }

    /** Share of otherwise-clean posts held back for a moderator's verdict: the same for every community. */
    public function qualityCheckPercent(): int
    {
        return QualitySample::percent();
    }

    /**
     * The most posts one run publishes. A fault that makes many posts due at once (a mode
     * change, an outage ending) then publishes them over several minutes, not in one go.
     */
    public function maxPerRun(): int
    {
        return EnvSetting::positiveInt(config('freegle.autoapprove.max_per_run'), 20);
    }

    /**
     * Rollout gate for the clean-path auto-approve.
     *
     * @return int[]|null null  = enabled everywhere (subject to per-group checks);
     *                    []    = fully off (the default; deploying this code is a no-op);
     *                    [ids] = phased trial: enabled only for these groups.
     *
     * A community in the shadow list never publishes whatever this says: AutomodMode is the
     * place that combines the two lists, and AutomodMode::approveModeSql() the SQL form of it.
     */
    public function enabledGroupIds(): ?array
    {
        if (EnvSetting::flag(config('freegle.autoapprove.enabled'))) {
            return null;
        }

        return EnvSetting::groupIds(config('freegle.autoapprove.trial_group_ids'));
    }

    /**
     * Process all eligible pending messages.
     *
     * @return array{approved:int, held_quality:int, skipped:int, errors:int, capped:int}
     */
    public function process(bool $dryRun = false): array
    {
        $stats = ['approved' => 0, 'held_quality' => 0, 'skipped' => 0, 'errors' => 0, 'capped' => 0];

        if ($this->enabledGroupIds() === []) {
            // Feature dark (default): posts follow the pre-existing behaviour: Pending
            // until a moderator acts or the 48h AutoApproveService fallback fires.
            return $stats;
        }

        // While posts are held nothing publishes by itself: ContentCheckService keeps new posts
        // Pending, and a post this cron published behind its back would defeat the press.
        $lockdown = $this->lockdown();
        $lockdown->ack(self::LOCKDOWN_LOOP);
        if ($lockdown->held('posts')) {
            $this->loki()->logEvent('autoapprove', 'skipped', ['reason' => 'lockdown', 'scope' => 'run']);

            return $stats;
        }

        // One site-wide delay for every community (config freegle.autoapprove.delay_minutes).
        // There is deliberately no per-community override: members get the same wait
        // everywhere, and the Go countdown in autoapproveat.go assumes the same figure.
        $candidates = DB::table('messages_groups as mg')
            ->join('messages as m', 'm.id', '=', 'mg.msgid')
            ->join('users as u', 'u.id', '=', 'm.fromuser')
            ->join('memberships as mem', function ($j) {
                $j->on('mem.userid', '=', 'm.fromuser')->on('mem.groupid', '=', 'mg.groupid');
            })
            ->join('groups as g', 'g.id', '=', 'mg.groupid')
            ->select(
                'mg.msgid', 'mg.groupid', 'mg.arrival', 'm.fromuser', 'm.spamtype', 'm.subject',
                DB::raw('m.type as msgtype')
            )
            ->where('mg.collection', MessageGroup::COLLECTION_PENDING)
            ->whereNull('mg.heldby')
            ->whereNull('m.heldby')
            // A copy a moderator pulled back (Back to Pending, or Reject from the Check
            // queue) waits for a moderator's own Approve, which is the only thing that
            // clears the flag: Release alone must not let the clock publish it again.
            ->where('mg.needs_moderator', 0)
            ->whereNull('mg.spamreason')
            ->whereNull('m.spamreason')
            // Never auto-approve a message that is in the Spam collection on ANY
            // group (Discourse #9654). Spam-collection messages surface in the
            // Pending review queue but must be actioned by a human, mirroring the
            // identical guard in AutoApproveService.
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('messages_groups as spam_mg')
                    ->whereColumn('spam_mg.msgid', 'mg.msgid')
                    ->where('spam_mg.collection', MessageGroup::COLLECTION_SPAM)
                    ->where('spam_mg.deleted', 0);
            })
            // A post the lockdown is holding is ContentCheckService's to admit once posts is
            // lifted (admitHeldPost runs the real decision check), not this cron's. A hold
            // already resolved must not stop the row being picked up.
            ->whereNotExists(fn ($q) => $this->openLockdownHold($q, 'mg'))
            ->where('mg.deleted', 0)
            ->whereNull('m.deleted')
            ->whereNull('u.deleted')
            ->whereNull('mem.ourPostingStatus')           // the auto-moderated tier
            ->whereNotNull('mg.contentcheck_checked_at')   // content check has run ...
            // ... and the automod chart has since reviewed it in approve mode and recorded an
            // approve that is still current: not superseded by a later edit or a later content
            // check, and old enough. The write below carries the same test.
            ->whereExists(fn ($q) => $this->currentApprove($q, 'mg'))
            ->where('mg.quality_sample', 0)               // already-sampled rows are excluded entirely
            ->where('mg.rippled_in', 0)                   // rippled-in rows belong to AutoApproveService (carries the Taken/Received + rippled_in_pending_hours + recentLogs-bypass guards)
            // keep-raw: NOW() - INTERVAL keeps the comparison on the database clock, like the hold check below.
            ->whereRaw('mg.arrival <= (NOW() - INTERVAL ? MINUTE)', [$this->delayMinutes()])
            // keep-raw: NOW() keeps the hold comparison on the database clock.
            ->whereRaw(
                '(mg.autoapprove_hold_until IS NULL OR mg.autoapprove_hold_until <= NOW())'
            )
            // Approve-mode communities only: the trial list while the master switch is off,
            // and never a community in the shadow list.
            // keep-raw: a dynamic id list from config, or always/never, which the builder cannot express.
            ->whereRaw(AutomodMode::approveModeSql('mg.groupid'))
            ->orderBy('mg.msgid')
            ->orderBy('mg.groupid')
            // Bound the per-tick batch like the sibling every-minute crons (ripple:expand
            // etc.): a post-outage backlog drains at 500 candidates a minute instead of one
            // huge run outliving its overlap mutex. Oldest msgids first, so the backlog is FIFO.
            ->limit(500)
            ->get();

        $maxPerRun = $this->maxPerRun();

        foreach ($candidates as $i => $row) {
            try {
                // Re-read per post: a press landing partway through a run must stop the next
                // post at once, not wait for the next minute. held() is a memory read within a
                // five-second cache, so this costs nothing beyond the first check.
                $lockdown->ack(self::LOCKDOWN_LOOP);
                if ($lockdown->held('posts')) {
                    $this->skipped($row, 'lockdown');
                    $stats['skipped'] += count($candidates) - $i;

                    break;
                }

                if ($this->isQualitySampled((int) $row->msgid)) {
                    // Normally stamped when the chart recorded its approve (AutomodService);
                    // this catches a sample rate raised since, so the stamp is never missed.
                    if (!$dryRun) {
                        DB::table('messages_groups')
                            ->where('msgid', $row->msgid)
                            ->where('groupid', $row->groupid)
                            ->where('quality_sample', 0)
                            ->update(['quality_sample' => 1]);
                        $stats['held_quality']++;
                    }
                    continue;
                }

                if ($stats['approved'] >= $maxPerRun) {
                    $stats['capped'] = count($candidates) - $i;
                    Log::warning("AutoApproveClean: publish cap of {$maxPerRun} per run reached; {$stats['capped']} due post(s) wait for the next run");
                    $this->loki()->logEvent('autoapprove', 'capped', ['limit' => $maxPerRun, 'waiting' => $stats['capped']]);

                    break;
                }

                if ($dryRun) {
                    $stats['approved']++;
                    Log::info("Dry run: would auto-approve clean message #{$row->msgid} on group #{$row->groupid}");
                    continue;
                }

                if ($this->approve($row)) {
                    $stats['approved']++;
                } else {
                    $stats['skipped']++;
                }
            } catch (\Exception $e) {
                Log::error("AutoApproveClean: error processing message #{$row->msgid}: " . $e->getMessage());
                $stats['errors']++;
            }
        }

        return $stats;
    }

    /**
     * Deterministically hold a site-wide percentage of otherwise-eligible posts in Pending so a
     * moderator spot-checks the auto-approval quality (QualitySample, shared with the chart's
     * decision and the Go countdown).
     */
    protected function isQualitySampled(int $msgid): bool
    {
        return QualitySample::sampled($msgid);
    }

    /**
     * Publish one candidate: re-read what can have changed since the chart decided, then write.
     * Mirrors AutoApproveService::approveOnGroup's side effects (NULL approvedby, reset arrival,
     * Autoapproved log, Ham recording) and additionally queues a freebie-alert task for Offers,
     * matching the immediate contentcheck approval.
     *
     * Candidates are selected once and written one by one, and a moderator, a microvolunteer
     * or the member can act in the gap. A signal that arrived since the chart decided names
     * the skip and withdraws the decision, so messages:automod decides again on the current
     * facts (and holds, with the right reason) instead of a stale approve and its countdown
     * lingering. Anything that changes between that read and the write is caught by the write.
     *
     * @return bool whether the post was published here.
     */
    protected function approve(object $row): bool
    {
        $signal = $this->liveSignal($row);
        if ($signal !== null) {
            // The chart's own facts include these signals, so deciding again will hold it. The
            // spam case is not one of the chart's facts: leave that decision alone, or the
            // chart would approve it again and this would loop.
            $withdrawn = ($signal['withdraw'] ?? true) ? $this->withdrawDecision($row) : false;
            $this->skipped($row, 'danger signal', [
                'signal' => $signal['signal'],
                'detail' => $signal['detail'],
                'decision_withdrawn' => $withdrawn,
            ]);

            return false;
        }

        $decision = DB::table('messages_automod')
            ->where('msgid', $row->msgid)
            ->where('groupid', $row->groupid)
            ->first(['verdict', 'end_node', 'chart_version', 'mode', 'created', 'detail']);

        if (!$this->publishIfStillDue($row)) {
            $this->skipped($row, 'changed before write');
            Log::info("AutoApproveClean: message #{$row->msgid} on group #{$row->groupid} changed before the write (a moderator acted, a hold was bumped, or the decision went stale); not published");

            return false;
        }

        // notSpam parity: whitelist the subject when it was flagged for cross-group reuse.
        if ($row->spamtype === 'SubjectUsedForDifferentGroups' && $row->subject) {
            DB::table('spam_whitelist_subjects')->insertOrIgnore([
                'subject' => AutoApproveService::getPrunedSubject($row->subject),
                'comment' => 'Marked as not spam',
            ]);
        }

        // notSpam parity: record HAM when the message had been marked spam.
        if ($row->spamtype) {
            DB::table('messages_spamham')->upsert(
                ['msgid' => $row->msgid, 'spamham' => 'Ham'],
                ['msgid'],
                ['spamham']
            );
        }

        $minutesWaited = !empty($row->arrival) ? (int) now()->diffInMinutes(Carbon::parse($row->arrival), true) : null;
        $decisionAge = !empty($decision->created) ? (int) now()->diffInSeconds(Carbon::parse($decision->created), true) : null;
        $decisionDetail = !empty($decision->detail) ? json_decode($decision->detail, true) : [];
        $live = DB::table('messages')->where('id', $row->msgid)->first(['subject', 'textbody']);

        Log::info("AutoApproveClean: approved message #{$row->msgid} on group #{$row->groupid} after {$minutesWaited} minutes (decision {$decisionAge}s old)");
        // Enough to answer "why did this go live" from Loki alone: what decided, in which mode,
        // when, and the text it read against the text that is live now (a difference is an edit
        // the chart did not see).
        $this->loki()->logEvent('autoapprove', 'published', [
            'msgid' => (int) $row->msgid,
            'groupid' => (int) $row->groupid,
            'minutes_waited' => $minutesWaited,
            'decision_age_seconds' => $decisionAge,
            'verdict' => $decision->verdict ?? null,
            'end' => $decision->end_node ?? null,
            'chart_version' => $decision->chart_version ?? null,
            'mode' => $decision->mode ?? null,
            'decision_created' => $decision->created ?? null,
            'text_sha' => AutomodService::textSha($live->subject ?? null, $live->textbody ?? null),
            'decision_text_sha' => is_array($decisionDetail) ? ($decisionDetail['text_sha'] ?? null) : null,
        ]);

        return true;
    }

    /**
     * The one atomic write. Lands only on a copy that is still, right now, an unheld Pending row
     * that nobody pulled back, past any hold, outside the quality sample, with no message-level
     * hold, spam reason or deletion, no microvolunteer reject and no open lockdown hold, and
     * whose chart approve is current (see currentApprove). Everything the candidate select
     * checked is checked again here, in the statement itself, so a change landing in the gap
     * between the read and this write stands. Side effects follow only when the row changed.
     *
     * @return bool whether the row changed, i.e. the post is now published.
     */
    protected function publishIfStillDue(object $row): bool
    {
        return DB::transaction(function () use ($row) {
            $updated = DB::table('messages_groups as mg')
                ->where('mg.msgid', $row->msgid)
                ->where('mg.groupid', $row->groupid)
                ->where('mg.collection', MessageGroup::COLLECTION_PENDING)
                ->where('mg.deleted', 0)
                ->where('mg.rippled_in', 0)
                ->whereNull('mg.heldby')
                ->whereNull('mg.spamreason')
                ->where('mg.needs_moderator', 0)
                ->where('mg.quality_sample', 0)
                // keep-raw: NOW() keeps the hold comparison on the database clock.
                ->whereRaw('(mg.autoapprove_hold_until IS NULL OR mg.autoapprove_hold_until <= NOW())')
                ->whereExists(function ($q) {
                    $q->select(DB::raw(1))
                        ->from('messages as wm')
                        ->whereColumn('wm.id', 'mg.msgid')
                        ->whereNull('wm.heldby')
                        ->whereNull('wm.spamreason')
                        ->whereNull('wm.deleted');
                })
                ->whereNotExists(function ($q) {
                    $q->select(DB::raw(1))
                        ->from('microactions as mr')
                        ->whereColumn('mr.msgid', 'mg.msgid')
                        ->where('mr.actiontype', 'CheckMessage')
                        ->where('mr.result', 'Reject');
                })
                ->whereNotExists(fn ($q) => $this->openLockdownHold($q, 'mg'))
                ->whereExists(fn ($q) => $this->currentApprove($q, 'mg'))
                ->update([
                    'collection' => MessageGroup::COLLECTION_APPROVED,
                    'approvedby' => null,            // NULL marks an auto-approval
                    'approvedat' => now(),
                    'arrival'    => now(),           // so the digest picks it up as new
                ]);

            if ($updated === 0) {
                return false;
            }

            DB::table('logs')->insert([
                'timestamp' => now(),
                'type'      => 'Message',
                'subtype'   => 'Autoapproved',
                'msgid'     => $row->msgid,
                'groupid'   => $row->groupid,
                'user'      => $row->fromuser,
                // Which automatic path: the 48h sweep writes none, so the two can be told apart.
                'text'      => 'automod',
            ]);

            if ($row->msgtype === Message::TYPE_OFFER) {
                DB::table('background_tasks')->insert([
                    'task_type' => BackgroundTask::TASK_FREEBIE_ALERTS_ADD,
                    'data'      => json_encode(['msgid' => (int) $row->msgid]),
                ]);
            }

            // Mirror the Go manual-approve path (addApprovedMessageToSpatialIndex): seed
            // messages_spatial now so the post appears in browse/search, and becomes
            // visible to the rippling engine, immediately, instead of waiting up to
            // 5 minutes for the spatial reconciler cron. Re-checks Approved so it is a
            // no-op if anything above did not land.
            // keep-raw: INSERT ... SELECT with ST_GeomFromText and ON DUPLICATE KEY UPDATE; the builder's insertUsing cannot render the upsert clause.
            DB::statement(
                "INSERT INTO messages_spatial (msgid, point, groupid, msgtype, arrival)
                 SELECT m.id, ST_GeomFromText(CONCAT('POINT(', m.lng, ' ', m.lat, ')'), 3857),
                        mg.groupid, m.type, mg.arrival
                 FROM messages m
                 INNER JOIN messages_groups mg ON mg.msgid = m.id
                 LEFT JOIN messages_outcomes mo ON mo.msgid = m.id
                 WHERE m.id = ? AND mg.groupid = ? AND mg.collection = 'Approved'
                   AND mg.deleted = 0 AND m.deleted IS NULL
                   AND m.lat IS NOT NULL AND m.lng IS NOT NULL
                   AND NOT (m.lat = 0 AND m.lng = 0)
                   AND mo.id IS NULL
                 ON DUPLICATE KEY UPDATE point = VALUES(point), msgtype = VALUES(msgtype),
                                         arrival = VALUES(arrival)",
                [$row->msgid, $row->groupid]
            );

            return true;
        });
    }

    /**
     * The chart's current approve for a copy, as one EXISTS shape used both to select candidates
     * and to carry the test into the write: an approve recorded in approve mode (a shadow
     * decision was never a promise), strictly later than the content check it drew its facts
     * from and than the last edit (equal to the second is not later: the chart may have read the
     * text first), and old enough that the wait, counted from the decision as well as from
     * arrival, has run. A verdict that lands late (service down, backlog, a retry) must not
     * publish a post the minute it is written: moderators had no countdown in the meantime. The
     * Go countdown (autoapproveat.go) shows the same max(arrival, decided) + delay.
     *
     * @param string $copy the alias of messages_groups in the outer query
     */
    private function currentApprove(Builder $q, string $copy): Builder
    {
        return $q->select(DB::raw(1))
            ->from('messages_automod as ma')
            ->whereColumn('ma.msgid', "{$copy}.msgid")
            ->whereColumn('ma.groupid', "{$copy}.groupid")
            ->where('ma.verdict', MessageAutomod::VERDICT_APPROVE)
            ->where('ma.mode', MessageAutomod::MODE_APPROVE)
            ->whereColumn('ma.created', '>', "{$copy}.contentcheck_checked_at")
            // keep-raw: COALESCE over a correlated scalar subselect (the post's last edit, which may be NULL) compared to another column isn't expressible with whereColumn/where.
            ->whereRaw("ma.created > COALESCE((SELECT em.editedat FROM messages em WHERE em.id = {$copy}.msgid), ?)", ['1970-01-01 00:00:00'])
            // keep-raw: NOW() - INTERVAL keeps the comparison on the database clock.
            ->whereRaw('ma.created <= (NOW() - INTERVAL ? MINUTE)', [$this->delayMinutes()]);
    }

    /** A lockdown hold on this post that has not been resolved: the lockdown's to admit, not this cron's. */
    private function openLockdownHold(Builder $q, string $copy): Builder
    {
        return $q->select(DB::raw(1))
            ->from('lockdown_holds as lh')
            ->whereColumn('lh.refid', "{$copy}.msgid")
            ->where('lh.kind', LockdownHoldsService::KIND_POST)
            ->whereNull('lh.outcome');
    }

    /**
     * What has changed about the post, the member or the community since the chart decided that
     * should stop it going live: spam on any community's copy, or one of the chart's own member
     * and community signals (a microvolunteer's reject, a moderator's note, a recent moderator
     * action, a spammer mark, a membership review, the community set to moderate everything).
     *
     * @return array{signal: string, detail: ?string, withdraw?: bool}|null
     */
    private function liveSignal(object $row): ?array
    {
        $spam = DB::table('messages_groups')
            ->where('msgid', $row->msgid)
            ->where('collection', MessageGroup::COLLECTION_SPAM)
            ->where('deleted', 0)
            ->exists();
        if ($spam) {
            return ['signal' => 'spam', 'detail' => 'The post is in Spam on a community', 'withdraw' => false];
        }

        return $this->facts()->liveVeto((int) $row->msgid, (int) $row->groupid);
    }

    /**
     * Withdraw a decision a live signal has made stale, so messages:automod reviews the post
     * again on the current facts. Only an approve is withdrawn (never a newer hold written in
     * the meantime); the feedback on it goes with it, as it was about a decision that no longer
     * stands.
     */
    private function withdrawDecision(object $row): bool
    {
        return DB::table('messages_automod')
            ->where('msgid', $row->msgid)
            ->where('groupid', $row->groupid)
            ->where('verdict', MessageAutomod::VERDICT_APPROVE)
            ->delete() > 0;
    }

    /** @param array<string, mixed> $extra */
    private function skipped(object $row, string $reason, array $extra = []): void
    {
        $this->loki()->logEvent('autoapprove', 'skipped', array_merge([
            'msgid' => (int) $row->msgid,
            'groupid' => (int) $row->groupid,
            'reason' => $reason,
        ], $extra));
    }

    private function facts(): AutomodFactsService
    {
        return $this->facts ?? new AutomodFactsService();
    }

    private function lockdown(): LockdownService
    {
        return $this->lockdown ?? app(LockdownService::class);
    }

    private function loki(): LokiService
    {
        return $this->loki ?? app(LokiService::class);
    }
}
