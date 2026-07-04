<?php

namespace App\Services;

use App\Helpers\ItemQuality;
use App\Models\Group;
use App\Models\MessageGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AutoApproveService
{
    /**
     * Messages must be pending for this many hours before auto-approval.
     */
    public const PENDING_HOURS = 48;

    /**
     * Short mod-veto window for rippling-out rows (messages_groups.rippled_in = 1) that are
     * already Approved on their origin group. They were vetted there, so they auto-approve
     * on nearby groups after this window instead of sitting Pending until a human acts —
     * the membership gate (the poster joins few of the groups their reach touches) and the
     * 48h fallback would otherwise leave every rippled-in post stuck Pending forever.
     */
    public const RIPPLED_IN_PENDING_HOURS = 1;

    /**
     * User must be a member for this many hours before their messages auto-approve.
     */
    public const MEMBERSHIP_HOURS = 48;

    /**
     * Auto-approve pending messages that meet all criteria.
     *
     * Matches V1 autoapprove.php → Message::autoapprove().
     * Processes per (msgid, groupid) pair — multi-group safe.
     *
     * V1 side effects included:
     *   - notSpam(): records HAM in messages_spamham
     *   - SQL UPDATE messages_groups (collection, approvedby, approvedat, arrival)
     *   - Log AUTOAPPROVED entry only (not the redundant APPROVED entry from approve())
     *
     * V1 side effects NOT included (handled elsewhere):
     *   - release(): query already filters heldby IS NULL
     *   - notifyGroupMods(): Go API handles push notifications
     *   - maybeMail(): not called in autoapprove (no subject/body passed)
     *   - addToSpatialIndex(): handled by message_spatial.php cron
     *   - index(): handled by message_unindexed.php cron
     */
    public function process(bool $dryRun = false): array
    {
        $stats = [
            'approved' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        // V1 query: SELECT msgid, groupid, TIMESTAMPDIFF(HOUR, messages_groups.arrival, NOW()) AS ago
        // FROM messages_groups INNER JOIN messages ON messages.id = messages_groups.msgid
        // WHERE collection = 'Pending' AND messages_groups.heldby IS NULL HAVING ago > 48
        //
        // Returns one row per (msgid, groupid). We group by msgid to match V1's pattern:
        // check logs once per message, then process all groups in the inner loop.
        //
        // The deleted filters (messages.deleted IS NULL, messages_groups.deleted = 0)
        // were absent from V1, which caused soft-deleted messages to be auto-approved
        // (mods don't see them in the queue, but the cron picked them up after 48h).
        $candidates = DB::table('messages_groups')
            ->join('messages', 'messages.id', '=', 'messages_groups.msgid')
            ->select(
                'messages_groups.msgid',
                'messages_groups.groupid',
                'messages_groups.rippled_in',
                'messages.fromuser',
                'messages.spamtype',
                'messages.subject',
                DB::raw('TIMESTAMPDIFF(HOUR, messages_groups.arrival, NOW()) AS hours_pending')
            )
            ->where('messages_groups.collection', MessageGroup::COLLECTION_PENDING)
            ->whereNull('messages_groups.heldby')
            ->where('messages_groups.deleted', 0)
            ->whereNull('messages.deleted')
            // Never auto-approve a message that is in the Spam collection on ANY
            // group. Spam-collection messages now surface in the Pending review
            // queue (Discourse #9654) but must be actioned by a human, never
            // auto-sent after the 48h fallback.
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('messages_groups as spam_mg')
                    ->whereColumn('spam_mg.msgid', 'messages_groups.msgid')
                    ->where('spam_mg.collection', MessageGroup::COLLECTION_SPAM)
                    ->where('spam_mg.deleted', 0);
            })
            // Never auto-approve a post that has already been collected. A rippled-in row can
            // still be Pending when the poster marks the item Taken/Received - the take retires the
            // pending rows it can see, but a take via a non-Go path (V1 mark()) leaves them. Approving
            // it would re-list a gone item in a new group and fire a "newly reached" mail, so skip
            // anything with a Taken/Received outcome.
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('messages_outcomes')
                    ->whereColumn('messages_outcomes.msgid', 'messages_groups.msgid')
                    ->whereIn('messages_outcomes.outcome', ['Taken', 'Received']);
            })
            // Respect a moderator-set hold window (set by the Go Pending list fetch,
            // extend-only to NOW()+10m) so a post a mod is actively reviewing is not
            // auto-approved out from under them.
            ->whereRaw(
                '(messages_groups.autoapprove_hold_until IS NULL OR messages_groups.autoapprove_hold_until <= NOW())'
            )
            // Posts held back as a manual quality-check sample stay held for a human:
            // letting the 48h fallback sweep them up would silently drain the sample
            // AutoApproveCleanService set aside, breaking the sample-vs-population
            // error-rate comparison on the moderation stats.
            ->where('messages_groups.quality_sample', 0)
            ->where(function ($q) {
                // Normal posts: the 48h fallback (unchanged).
                $q->where(function ($q2) {
                    $q2->where('messages_groups.rippled_in', 0)
                        ->whereRaw('TIMESTAMPDIFF(HOUR, messages_groups.arrival, NOW()) > ?', [self::PENDING_HOURS]);
                })
                // Rippling-out rows already Approved on their origin group: a short mod-veto
                // window, then auto-approve (membership gate bypassed in shouldApproveOnGroup).
                ->orWhere(function ($q2) {
                    // Configurable mod-veto window (default 1h via the const; 0 = immediate,
                    // used during reach experiments to keep moderation load off receiving
                    // groups). >= so that 0 means "eligible as soon as it arrives".
                    $rippledInHours = (int) config('freegle.ripple.rippled_in_pending_hours', self::RIPPLED_IN_PENDING_HOURS);
                    $q2->where('messages_groups.rippled_in', 1)
                        ->whereRaw('TIMESTAMPDIFF(HOUR, messages_groups.arrival, NOW()) >= ?', [$rippledInHours])
                        ->whereExists(function ($q3) {
                            $q3->select(DB::raw(1))
                                ->from('messages_groups as origin_mg')
                                ->whereColumn('origin_mg.msgid', 'messages_groups.msgid')
                                ->whereColumn('origin_mg.groupid', '!=', 'messages_groups.groupid')
                                ->where('origin_mg.collection', MessageGroup::COLLECTION_APPROVED)
                                ->where('origin_mg.deleted', 0);
                        });
                });
            })
            ->get()
            ->groupBy('msgid');

        foreach ($candidates as $msgid => $groupRows) {
            try {
                // V1 parity: skip auto-approving a message that was recently held/unheld.
                // Lazy-evaluated so the query only runs when there is at least one non-rippled-in
                // candidate that needs the guard. Rippled-in rows bypass this check entirely:
                // the relevant hold on the nearby group is already expressed by the heldby IS NULL
                // filter in the candidates query, and finding an approval log from the ORIGIN group
                // here must not block the short veto window on the receiving group (which is how
                // ~30 posts "disappeared suddenly" — they were stuck Pending for 48h instead of 1h
                // and then batch-approved when the origin-approval log aged out, Discourse 9812/3).
                $recentLogsChecked = false;
                $recentLogs = false;

                foreach ($groupRows as $candidate) {
                    $isRippledIn = (int) ($candidate->rippled_in ?? 0) === 1;

                    if (!$isRippledIn) {
                        if (!$recentLogsChecked) {
                            $recentLogs = DB::table('logs')
                                ->where('msgid', $msgid)
                                ->where('timestamp', '>', now()->subHours(self::PENDING_HOURS))
                                ->exists();
                            $recentLogsChecked = true;
                        }
                        if ($recentLogs) {
                            $stats['skipped']++;
                            continue;
                        }
                    }

                    if ($this->shouldApproveOnGroup($candidate, $candidate->groupid)) {
                        if ($dryRun) {
                            Log::info("Dry run: would auto-approve message #{$candidate->msgid} on group #{$candidate->groupid}");
                            $stats['approved']++;
                        } else {
                            $this->approveOnGroup($candidate, $candidate->groupid);
                            $stats['approved']++;
                        }
                    } else {
                        $stats['skipped']++;
                    }
                }

                // A rippling post can be auto-approved on a newly-reached group AFTER its reach
                // has finished expanding (the ExpandService tick loop only revisits 'expanding'
                // posts), so mail any now-reachable immediate members here too. Idempotent and a
                // no-op for non-rippling posts (the reach gate + ledger in mailNewlyReachedForPost).
                if (!$dryRun) {
                    app(\App\Services\UnifiedDigestService::class)->mailNewlyReachedForPost((int) $msgid);
                }
            } catch (\Exception $e) {
                Log::error("Error auto-approving message #{$msgid}: " . $e->getMessage());
                $stats['errors']++;
            }
        }

        return $stats;
    }

    /**
     * Check whether a message should be auto-approved on a specific group.
     *
     * V1: $g->getSetting('publish', TRUE) && !$g->getSetting('closed', FALSE)
     *     && !$g->getPrivate('autofunctionoverride')
     *     && membership added > 48 hours ago
     */
    protected function shouldApproveOnGroup(object $candidate, int $groupid): bool
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

        // Rippling-out rows were already vetted on their origin group (the orWhere in
        // process() only selects rippled_in rows that are Approved elsewhere). The poster
        // need not be a member of every nearby group their reach touches, so bypass the
        // membership gate — the group publish/closed/override checks above still apply.
        if ((int) ($candidate->rippled_in ?? 0) === 1) {
            return true;
        }

        // Low-quality / vague item ("anything", "free stuff", "various items", "things for the
        // garden"): do NOT auto-approve — leave it Pending so a moderator reviews it (they can
        // approve the genuine ones). This is deliberately more aggressive than the client-side
        // compose gate because Pending is reversible; live-data sized at ~3 posts/day across
        // Freegle. Applied to ORIGIN rows only — a rippled-in copy was already vetted above.
        if (ItemQuality::subjectItemIsVague($candidate->subject ?? null)) {
            return false;
        }

        // V1: $joined = $u->getMembershipAtt($gid, 'added');
        // $hoursago = round((time() - strtotime($joined)) / 3600);
        $membership = DB::table('memberships')
            ->where('userid', $candidate->fromuser)
            ->where('groupid', $groupid)
            ->first();

        if (!$membership || !$membership->added) {
            return false;
        }

        $memberHours = (int) round((time() - strtotime($membership->added)) / 3600);
        if ($memberHours <= self::MEMBERSHIP_HOURS) {
            return false;
        }

        return true;
    }

    /**
     * Approve a message on a specific group.
     *
     * Matches V1 Message::approve() + Message::autoapprove() side effects.
     */
    protected function approveOnGroup(object $candidate, int $groupid): void
    {
        // V1 notSpam(): if spamtype is SubjectUsedForDifferentGroups, whitelist the subject.
        // V1: Spam::notSpamSubject(getPrunedSubject()) → INSERT IGNORE INTO spam_whitelist_subjects
        if ($candidate->spamtype === 'SubjectUsedForDifferentGroups' && $candidate->subject) {
            $prunedSubject = self::getPrunedSubject($candidate->subject);
            DB::table('spam_whitelist_subjects')->insertOrIgnore([
                'subject' => $prunedSubject,
                'comment' => 'Marked as not spam',
            ]);
        }

        // V1 notSpam(): record HAM in messages_spamham if message was marked spam.
        if ($candidate->spamtype) {
            DB::table('messages_spamham')->upsert(
                ['msgid' => $candidate->msgid, 'spamham' => 'Ham'],
                ['msgid'],
                ['spamham']
            );
        }

        // V1 approve(): UPDATE messages_groups SET collection='Approved', approvedby=whoAmId(),
        // approvedat=NOW(), arrival=NOW() WHERE msgid=? AND groupid=? AND collection!='Approved'
        // V1 whoAmId() returns NULL in cron context (no session).
        $updated = DB::table('messages_groups')
            ->where('msgid', $candidate->msgid)
            ->where('groupid', $groupid)
            ->where('collection', '!=', MessageGroup::COLLECTION_APPROVED)
            // Re-check the mod hold at write time: a moderator loading the Pending
            // queue between the candidate query and this UPDATE bumps
            // autoapprove_hold_until, and their guaranteed review window must win.
            ->whereRaw('(autoapprove_hold_until IS NULL OR autoapprove_hold_until <= NOW())')
            ->update([
                'collection' => MessageGroup::COLLECTION_APPROVED,
                'approvedby' => null,
                'approvedat' => now(),
                'arrival' => now(),
            ]);

        if ($updated === 0) {
            // Hold bumped mid-run, or someone else approved it first — either way the
            // approval did not happen here, so no Autoapproved log (the moderation
            // stats count those logs as real auto-approvals).
            return;
        }

        // V1 autoapprove() log: type=Message, subtype=Autoapproved.
        DB::table('logs')->insert([
            'timestamp' => now(),
            'type' => 'Message',
            'subtype' => 'Autoapproved',
            'msgid' => $candidate->msgid,
            'groupid' => $groupid,
            'user' => $candidate->fromuser,
        ]);

        Log::info("Auto-approved message #{$candidate->msgid} on group #{$groupid}");
    }

    /**
     * V1 Message::getPrunedSubject() — strip location (parentheses), group name (brackets),
     * trim, and quoted-printable encode.
     */
    public static function getPrunedSubject(string $subject): string
    {
        // Strip possible location — e.g. "OFFER: Sofa (Southend)" → "OFFER: Sofa "
        if (preg_match('/(.*)\(.*\)/', $subject, $matches)) {
            $subject = $matches[1];
        }

        // Strip possible group name — e.g. "[Essex] OFFER: Sofa" → " OFFER: Sofa"
        if (preg_match('/\[.*\](.*)/', $subject, $matches)) {
            $subject = $matches[1];
        }

        $subject = trim($subject);

        // Remove odd characters (V1 uses quoted_printable_encode).
        $subject = quoted_printable_encode($subject);

        return $subject;
    }
}
