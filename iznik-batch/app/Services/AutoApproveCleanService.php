<?php

namespace App\Services;

use App\Models\BackgroundTask;
use App\Models\Group;
use App\Models\Message;
use App\Models\MessageGroup;
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
 * a short window — giving moderators and microvolunteers a chance to intervene — and are
 * then released automatically, UNLESS a danger signal is present. A configurable
 * percentage is held back as a manual quality-check sample.
 *
 * Posts only become eligible AFTER messages:contentcheck has run and found them clean
 * (contentcheck_checked_at set, contentcheck_reasons NULL). Suspect posts keep their
 * contentcheck_reasons and are never auto-approved here — they stay in Pending (or are
 * moved to Spam) exactly as before.
 *
 * Trusted members (DEFAULT/UNMODERATED) are unaffected — contentcheck already approves
 * their clean posts immediately. Explicit MODERATED/PROHIBITED members are unaffected too.
 */
class AutoApproveCleanService
{
    /** Negative moderation log subtypes that veto auto-approval. */
    private const DANGER_MESSAGE_SUBTYPES = ['Rejected', 'Deleted', 'Replied'];
    private const DANGER_USER_SUBTYPES    = ['Mailed', 'Rejected', 'Deleted', 'Suspect', 'ClassifiedSpam'];

    public function defaultDelayMinutes(): int
    {
        return (int) config('freegle.autoapprove.delay_minutes', 20);
    }

    public function defaultQualityCheckPercent(): int
    {
        return (int) config('freegle.autoapprove.quality_check_percent', 0);
    }

    public function dangerLogDays(): int
    {
        return (int) config('freegle.autoapprove.danger_log_days', 90);
    }

    /**
     * Process all eligible pending messages.
     *
     * @return array{approved:int, held_quality:int, vetoed:int, skipped:int, errors:int}
     */
    public function process(bool $dryRun = false): array
    {
        $stats = ['approved' => 0, 'held_quality' => 0, 'vetoed' => 0, 'skipped' => 0, 'errors' => 0];

        // The delay is per-group (settings.autoapprove.delay_minutes) with a site-wide
        // fallback, so it must be resolved in SQL — a single global threshold would ignore
        // group overrides. A 0/absent override means "use the site default".
        $candidates = DB::table('messages_groups as mg')
            ->join('messages as m', 'm.id', '=', 'mg.msgid')
            ->join('users as u', 'u.id', '=', 'm.fromuser')
            ->join('memberships as mem', function ($j) {
                $j->on('mem.userid', '=', 'm.fromuser')->on('mem.groupid', '=', 'mg.groupid');
            })
            ->join('groups as g', 'g.id', '=', 'mg.groupid')
            ->select('mg.msgid', 'mg.groupid', 'm.fromuser', 'm.spamtype', 'm.subject', DB::raw('m.type as msgtype'))
            ->where('mg.collection', MessageGroup::COLLECTION_PENDING)
            ->whereNull('mg.heldby')
            ->whereNull('m.heldby')
            ->whereNull('mg.spamreason')
            ->whereNull('m.spamreason')
            // Never auto-approve a message that is in the Spam collection on ANY
            // group (Discourse #9654). Spam-collection messages surface in the
            // Pending review queue but must be actioned by a human — mirroring
            // the identical guard in AutoApproveService.
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('messages_groups as spam_mg')
                    ->whereColumn('spam_mg.msgid', 'mg.msgid')
                    ->where('spam_mg.collection', MessageGroup::COLLECTION_SPAM)
                    ->where('spam_mg.deleted', 0);
            })
            ->where('mg.deleted', 0)
            ->whereNull('m.deleted')
            ->whereNull('u.deleted')
            ->whereNull('mem.ourPostingStatus')           // the auto-moderated tier
            ->whereNotNull('mg.contentcheck_checked_at')   // content check has run ...
            ->whereNull('mg.contentcheck_reasons')         // ... and the post was clean
            ->where('mg.quality_sample', 0)               // already-sampled rows are excluded entirely
            ->whereRaw(
                "mg.arrival <= (NOW() - INTERVAL COALESCE(NULLIF(CAST(JSON_UNQUOTE(JSON_EXTRACT(g.settings, '$.autoapprove.delay_minutes')) AS UNSIGNED), 0), ?) MINUTE)",
                [$this->defaultDelayMinutes()]
            )
            ->whereRaw(
                '(mg.autoapprove_hold_until IS NULL OR mg.autoapprove_hold_until <= NOW())'
            )
            ->orderBy('mg.msgid')
            ->orderBy('mg.groupid')
            ->get();

        foreach ($candidates as $row) {
            try {
                if (!$this->groupAllowsAutoApprove((int) $row->groupid)) {
                    $stats['skipped']++;
                    continue;
                }

                if ($this->hasDangerSignals((int) $row->msgid, (int) $row->groupid, (int) $row->fromuser)) {
                    $stats['vetoed']++;
                    continue;
                }

                if ($this->isQualitySampled((int) $row->msgid, (int) $row->groupid)) {
                    if (!$dryRun) {
                        // Mark it as a quality-check sample so the moderation-stats
                        // dashboard can compare the mod's verdict on the sample
                        // against the auto-approved population's later error rate.
                        DB::table('messages_groups')
                            ->where('msgid', $row->msgid)
                            ->where('groupid', $row->groupid)
                            ->where('quality_sample', 0)
                            ->update(['quality_sample' => 1]);
                        $stats['held_quality']++;
                    }
                    continue;
                }

                if ($dryRun) {
                    $stats['approved']++;
                    Log::info("Dry run: would auto-approve clean message #{$row->msgid} on group #{$row->groupid}");
                    continue;
                }

                $this->approve($row);
                $stats['approved']++;
            } catch (\Exception $e) {
                Log::error("AutoApproveClean: error processing message #{$row->msgid}: " . $e->getMessage());
                $stats['errors']++;
            }
        }

        return $stats;
    }

    /**
     * The group must be open, published and NOT moderated — a moderated group (or one
     * under the Big Switch) deliberately wants every post reviewed by a human.
     */
    protected function groupAllowsAutoApprove(int $groupid): bool
    {
        $group = Group::find($groupid);
        if (!$group) {
            return false;
        }
        if (!$group->getSetting('publish', true)) {
            return false;
        }
        if ($group->isClosed()) {
            return false;
        }
        if ($group->getAttribute('autofunctionoverride')) {
            return false;
        }
        if ($group->getAttribute('overridemoderation') === 'ModerateAll') {
            return false;
        }
        if (!empty($group->getSetting('moderated', 0))) {
            return false;
        }
        $rules = $group->rules ?? [];
        if (!empty($rules['fullymoderated'])) {
            return false;
        }

        return true;
    }

    /**
     * Any one of these "danger signals" keeps the post in Pending for a moderator to handle.
     */
    protected function hasDangerSignals(int $msgid, int $groupid, int $fromuser): bool
    {
        // A microvolunteer flagged the post as not OK.
        if (DB::table('microactions')
            ->where('msgid', $msgid)
            ->where('actiontype', 'CheckMessage')
            ->where('result', 'Reject')
            ->exists()) {
            return true;
        }

        // A moderator has left a note on this member.
        if (DB::table('users_comments')->where('userid', $fromuser)->exists()) {
            return true;
        }

        // A recent negative moderation action against this member (rejection, deletion,
        // modmail, spam classification) — not a self-initiated action.
        if (DB::table('logs')
            ->where('user', $fromuser)
            ->where('timestamp', '>=', now()->subDays($this->dangerLogDays()))
            ->where(function ($q) {
                $q->whereColumn('byuser', '!=', 'user')->orWhereNull('byuser');
            })
            ->where(function ($q) {
                $q->where(function ($q2) {
                    $q2->where('type', 'Message')->whereIn('subtype', self::DANGER_MESSAGE_SUBTYPES);
                })->orWhere(function ($q2) {
                    $q2->where('type', 'User')->whereIn('subtype', self::DANGER_USER_SUBTYPES);
                });
            })
            ->exists()) {
            return true;
        }

        // A known or suspected spammer.
        if (DB::table('spam_users')
            ->where('userid', $fromuser)
            ->whereIn('collection', ['Spammer', 'PendingAdd'])
            ->exists()) {
            return true;
        }

        // A moderation review is outstanding on this membership.
        if (DB::table('memberships')
            ->where('userid', $fromuser)
            ->where('groupid', $groupid)
            ->whereNotNull('reviewrequestedat')
            ->where(function ($q) {
                $q->whereNull('reviewedat')->orWhereColumn('reviewedat', '<', 'reviewrequestedat');
            })
            ->exists()) {
            return true;
        }

        return false;
    }

    /**
     * Deterministically hold a configurable percentage of otherwise-eligible posts in
     * Pending so a moderator spot-checks the auto-approval quality. Deterministic on msgid
     * so a held message never oscillates between runs.
     */
    protected function isQualitySampled(int $msgid, int $groupid): bool
    {
        $percent = $this->qualityPercentForGroup($groupid);
        if ($percent <= 0) {
            return false;
        }
        if ($percent >= 100) {
            return true;
        }

        return (abs(crc32((string) $msgid)) % 100) < $percent;
    }

    protected function qualityPercentForGroup(int $groupid): int
    {
        $group    = Group::find($groupid);
        $settings = $group?->settings ?? [];
        $val      = $settings['autoapprove']['quality_check_percent'] ?? null;
        if ($val === null || $val === '') {
            return $this->defaultQualityCheckPercent();
        }

        return (int) $val;
    }

    /**
     * Approve a message on a group. Mirrors AutoApproveService::approveOnGroup side effects
     * (NULL approvedby, reset arrival, Autoapproved log, Ham recording) and additionally
     * queues a freebie-alert task for Offers, matching the immediate contentcheck approval.
     */
    protected function approve(object $row): void
    {
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

        DB::transaction(function () use ($row) {
            DB::table('messages_groups')
                ->where('msgid', $row->msgid)
                ->where('groupid', $row->groupid)
                ->where('collection', '!=', MessageGroup::COLLECTION_APPROVED)
                ->update([
                    'collection' => MessageGroup::COLLECTION_APPROVED,
                    'approvedby' => null,            // NULL marks an auto-approval
                    'approvedat' => now(),
                    'arrival'    => now(),           // so the digest picks it up as new
                ]);

            DB::table('logs')->insert([
                'timestamp' => now(),
                'type'      => 'Message',
                'subtype'   => 'Autoapproved',
                'msgid'     => $row->msgid,
                'groupid'   => $row->groupid,
                'user'      => $row->fromuser,
            ]);

            if ($row->msgtype === Message::TYPE_OFFER) {
                DB::table('background_tasks')->insert([
                    'task_type' => BackgroundTask::TASK_FREEBIE_ALERTS_ADD,
                    'data'      => json_encode(['msgid' => (int) $row->msgid]),
                ]);
            }
        });

        Log::info("AutoApproveClean: approved message #{$row->msgid} on group #{$row->groupid}");
    }
}
