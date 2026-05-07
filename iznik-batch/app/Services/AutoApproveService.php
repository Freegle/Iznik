<?php

namespace App\Services;

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
        // WHERE collection = 'Pending' AND heldby IS NULL HAVING ago > 48
        //
        // Returns one row per (msgid, groupid). We group by msgid to match V1's pattern:
        // check logs once per message, then process all groups in the inner loop.
        $candidates = DB::table('messages_groups')
            ->join('messages', 'messages.id', '=', 'messages_groups.msgid')
            ->select(
                'messages_groups.msgid',
                'messages_groups.groupid',
                'messages.fromuser',
                'messages.spamtype',
                'messages.subject',
                DB::raw('TIMESTAMPDIFF(HOUR, messages_groups.arrival, NOW()) AS hours_pending')
            )
            ->where('messages_groups.collection', MessageGroup::COLLECTION_PENDING)
            ->whereNull('messages.heldby')
            ->whereRaw('TIMESTAMPDIFF(HOUR, messages_groups.arrival, NOW()) > ?', [self::PENDING_HOURS])
            ->get()
            ->groupBy('msgid');

        foreach ($candidates as $msgid => $groupRows) {
            try {
                // V1: check for recent logs referencing this message ONCE (not per group).
                // This avoids auto-approving messages recently held/unheld, while still
                // allowing multi-group messages to be approved across all groups in one pass.
                $recentLogs = DB::table('logs')
                    ->where('msgid', $msgid)
                    ->where('timestamp', '>', now()->subHours(self::PENDING_HOURS))
                    ->exists();

                if ($recentLogs) {
                    $stats['skipped'] += $groupRows->count();
                    continue;
                }

                foreach ($groupRows as $candidate) {
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
        DB::table('messages_groups')
            ->where('msgid', $candidate->msgid)
            ->where('groupid', $groupid)
            ->where('collection', '!=', MessageGroup::COLLECTION_APPROVED)
            ->update([
                'collection' => MessageGroup::COLLECTION_APPROVED,
                'approvedby' => null,
                'approvedat' => now(),
                'arrival' => now(),
            ]);

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
