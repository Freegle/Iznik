<?php

namespace App\Services;

use App\Helpers\ItemQuality;
use App\Models\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AutoApproveService
{
    /**
     * Messages must be pending for this many hours before auto-approval.
     */
    public const PENDING_HOURS = 48;

    /**
     * Account must be this many hours old before its messages auto-approve. Replaces V1's
     * per-group membership-hours gate (memberships.added): there is one collection per
     * message now (self-moderating-community.md), so "member of this group long enough" has
     * no receiving group to be long enough on. users.added — the account's own creation
     * time — is the natural national-equivalent gate, and is already used the same way for
     * account-age logic elsewhere (ReengageService).
     */
    public const ACCOUNT_HOURS = 48;

    /**
     * Auto-approve pending messages that meet all criteria.
     *
     * Matches V1 autoapprove.php → Message::autoapprove(), collapsed from V1's
     * (msgid, groupid) pairs to one row per message: messages.collection replaced
     * messages_groups, so there is no per-group decision left to make.
     *
     * V1 side effects included:
     *   - notSpam(): records HAM in messages_spamham
     *   - SQL UPDATE messages (collection, approvedby, approvedat)
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
        // One row per message now — messages.collection replaced messages_groups, so there
        // is no group dimension left to group by or loop over.
        //
        // The Spam-on-any-group exclusion V1 needed is gone too: collection is a single
        // value, so filtering collection = Pending already excludes Spam outright.
        //
        // keep-raw: TIMESTAMPDIFF is a MySQL dialect function with no query-builder
        // equivalent; whereRaw is the only way to compare it against a bound parameter.
        $candidates = DB::table('messages')
            ->join('users', 'users.id', '=', 'messages.fromuser')
            ->select(
                'messages.id as msgid',
                'messages.fromuser',
                'messages.spamtype',
                'messages.subject',
                'messages.contentcheck_reasons',
                'users.added as user_added'
            )
            ->where('messages.collection', Message::COLLECTION_PENDING)
            ->whereNull('messages.heldby')
            ->whereNull('messages.deleted')
            ->whereNull('users.deleted')
            ->whereRaw('TIMESTAMPDIFF(HOUR, messages.arrival, NOW()) > ?', [self::PENDING_HOURS])
            // Never auto-approve a post that has already been collected. The take retires
            // the pending row it can see, but a take via a non-Go path (V1 mark()) leaves
            // it. Approving it would re-list a gone item and fire a "newly reached" mail, so
            // skip anything with a Taken/Received outcome.
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('messages_outcomes')
                    ->whereColumn('messages_outcomes.msgid', 'messages.id')
                    ->whereIn('messages_outcomes.outcome', ['Taken', 'Received']);
            })
            ->get();

        foreach ($candidates as $candidate) {
            $msgid = $candidate->msgid;

            try {
                // V1 parity: skip auto-approving a message that was recently held/unheld.
                $recentLogs = DB::table('logs')
                    ->where('msgid', $msgid)
                    ->where('timestamp', '>', now()->subHours(self::PENDING_HOURS))
                    ->exists();

                if ($recentLogs) {
                    $stats['skipped']++;
                    continue;
                }

                if ($this->shouldApprove($candidate)) {
                    if ($dryRun) {
                        Log::info("Dry run: would auto-approve message #{$msgid}");
                        $stats['approved']++;
                    } else {
                        $this->approve($candidate);
                        $stats['approved']++;
                    }
                } else {
                    $stats['skipped']++;
                }

                // A rippling post can be auto-approved on a newly-reached area AFTER its reach
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
     * Check whether a message should be auto-approved.
     *
     * V1 per-group settings (publish/closed/autofunctionoverride) are gone — there is no
     * group left to carry them. The rippled-in fast-track (a short mod-veto window for a
     * copy already vetted on its origin group) is gone too — there is no origin-vs-receiving
     * distinction with a single collection per message; only the reach mail-out that fast
     * track existed to unblock survives, in process() above.
     */
    protected function shouldApprove(object $candidate): bool
    {
        // The concern-keyword hold that used to sit here (a matched keyword was an
        // objection the 48h fallback had to respect) is gone along with concern_keywords
        // itself (ai-judgement.md, 2026-09-27): the judge's own 'takedown' verdict already
        // takes a post down directly via TakedownService before it ever reaches Pending, and
        // a judge 'wait' verdict is deliberately the mild outcome — it goes live after this
        // same delay, not held for a moderator. So nothing here needs to out-wait the 48h
        // fallback any more; only the periodic content check's own flags (MemberModerated,
        // NoLocation, ...) describe the row's situation and never held it either.

        // Low-quality / vague item ("anything", "free stuff", "various items", "things for the
        // garden"): do NOT auto-approve — leave it Pending so a moderator reviews it (they can
        // approve the genuine ones). This is deliberately more aggressive than the client-side
        // compose gate because Pending is reversible; live-data sized at ~3 posts/day across
        // Freegle.
        if (ItemQuality::subjectItemIsVague($candidate->subject ?? null)) {
            return false;
        }

        // V1: $joined = $u->getMembershipAtt($gid, 'added'); $hoursago = round((time() -
        // strtotime($joined)) / 3600). Replaced with hours since the account itself was
        // created (users.added) — see ACCOUNT_HOURS.
        if (!$candidate->user_added) {
            return false;
        }

        $accountHours = (int) round((time() - strtotime($candidate->user_added)) / 3600);

        return $accountHours > self::ACCOUNT_HOURS;
    }

    /**
     * Approve a message.
     *
     * Matches V1 Message::approve() + Message::autoapprove() side effects.
     */
    protected function approve(object $candidate): void
    {
        $msgid = $candidate->msgid;

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
                ['msgid' => $msgid, 'spamham' => 'Ham'],
                ['msgid'],
                ['spamham']
            );
        }

        // V1 approve(): UPDATE messages_groups SET collection='Approved', approvedby=whoAmId(),
        // approvedat=NOW() WHERE msgid=? AND groupid=? AND collection!='Approved'. Single row
        // per message now, no groupid; arrival is left alone, matching
        // ContentCheckService::processUnprocessed()'s own approve path.
        // V1 whoAmId() returns NULL in cron context (no session).
        DB::table('messages')
            ->where('id', $msgid)
            ->where('collection', '!=', Message::COLLECTION_APPROVED)
            ->update([
                'collection' => Message::COLLECTION_APPROVED,
                'approvedby' => null,
                'approvedat' => now(),
            ]);

        // V1 autoapprove() log: type=Message, subtype=Autoapproved.
        DB::table('logs')->insert([
            'timestamp' => now(),
            'type' => 'Message',
            'subtype' => 'Autoapproved',
            'msgid' => $msgid,
            'user' => $candidate->fromuser,
        ]);

        Log::info("Auto-approved message #{$msgid}");
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
