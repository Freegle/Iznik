<?php

namespace App\Services\TrashNothing\Ingestion;

use App\Models\BackgroundTask;
use App\Models\MessageOutcome;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Give a TN post that was already closed on TrashNothing when Freegle ingested it the
 * outcome it has there.
 *
 * Some TN posts are not published to /posts/all until a Freegle community approves them,
 * so the API path never sees them and tn:verify-email-coverage ingests them by id from the
 * email TN sent. By then the poster may have closed the post on TN. TN reports a closure
 * by calling PATCH /message/tn/{tnpostid}, which found nothing while Freegle did not hold
 * the post, so the outcome is applied here instead.
 *
 * This duplicates the rules of handleOutcome in iznik-server-go/message/message.go, the
 * handler that PATCH reaches, because batch and the Go API run on separate machines. Keep
 * the two in step: a change to what an outcome does there belongs here too.
 *
 * - A post withdrawn while still Pending on its own community is deleted, not given an
 *   outcome: every Pending row is marked deleted, the message is deleted and its messageid
 *   cleared, and a Deleted log with the text "Withdrawn" is written for each community.
 * - Otherwise the outcome is recorded (Taken for an offer or Received for a wanted that was
 *   satisfied, Withdrawn for one withdrawn), Pending rows elsewhere are retired only when
 *   the post is Approved somewhere, and the message_outcome task logs it.
 * - Either way the post is removed from freebie alerts.
 */
class ClosedPostOutcome
{
    public const COMMENT = 'Closed on TrashNothing before Freegle received it';

    /**
     * @return string what was done: taken, received, withdrawn, withdrawn-deleted, or
     *                skipped (no such message, an outcome TN gave that Freegle has no
     *                equivalent for, or an outcome already recorded)
     */
    public function apply(int $msgId, string $tnOutcome): string
    {
        $type = DB::table('messages')->where('id', $msgId)->value('type');

        $outcome = match ($tnOutcome) {
            'satisfied' => match ($type) {
                'Offer'  => MessageOutcome::OUTCOME_TAKEN,
                'Wanted' => MessageOutcome::OUTCOME_RECEIVED,
                default  => null,
            },
            'withdrawn' => MessageOutcome::OUTCOME_WITHDRAWN,
            default     => null,
        };

        if ($type === null || $outcome === null) {
            return 'skipped';
        }

        $fromUser = (int) DB::table('messages')->where('id', $msgId)->value('fromuser');

        if ($outcome === MessageOutcome::OUTCOME_WITHDRAWN) {
            $pendingHere = DB::table('messages_groups')
                ->where('msgid', $msgId)->where('collection', 'Pending')
                ->where('deleted', 0)->where('rippled_in', 0)
                ->exists();

            if ($pendingHere) {
                $pendingGroups = DB::table('messages_groups')
                    ->where('msgid', $msgId)->where('collection', 'Pending')->where('deleted', 0)
                    ->pluck('groupid');

                DB::table('messages_groups')->where('msgid', $msgId)->where('collection', 'Pending')
                    ->update(['deleted' => 1]);
                DB::table('messages')->where('id', $msgId)
                    ->update(['deleted' => now(), 'messageid' => null]);

                foreach ($pendingGroups as $groupId) {
                    $this->logDeleted($msgId, (int) $groupId, $fromUser, 'Withdrawn');
                }

                $this->queueFreebieRemove($msgId);
                Log::info('TN closed post: withdrawn while pending, deleted', ['msgid' => $msgId]);

                return 'withdrawn-deleted';
            }
        }

        if (DB::table('messages_outcomes')->where('msgid', $msgId)->exists()) {
            return 'skipped';
        }

        DB::table('messages_outcomes_intended')->where('msgid', $msgId)->delete();
        DB::table('messages_outcomes')->insert([
            'msgid'     => $msgId,
            'outcome'   => $outcome,
            'comments'  => self::COMMENT,
            'timestamp' => now(),
        ]);

        if ($outcome !== MessageOutcome::OUTCOME_WITHDRAWN) {
            DB::table('messages_spatial')->where('msgid', $msgId)->update(['successful' => 1]);
        }

        // Pending copies elsewhere go only while the post is Approved somewhere, so a post
        // pending on its single community keeps its row and its outcome.
        $approvedSomewhere = DB::table('messages_groups')
            ->where('msgid', $msgId)->where('collection', 'Approved')->where('deleted', 0)
            ->exists();

        if ($approvedSomewhere) {
            $pendingGroups = DB::table('messages_groups')
                ->where('msgid', $msgId)->where('collection', 'Pending')->where('deleted', 0)
                ->pluck('groupid');

            if ($pendingGroups->isNotEmpty()) {
                DB::table('messages_groups')->where('msgid', $msgId)->where('collection', 'Pending')
                    ->where('deleted', 0)->update(['deleted' => 1]);

                foreach ($pendingGroups as $groupId) {
                    $this->logDeleted($msgId, (int) $groupId, $fromUser, $outcome);
                }
            }
        }

        $this->queueFreebieRemove($msgId);

        BackgroundTask::create([
            'task_type'  => BackgroundTask::TASK_MESSAGE_OUTCOME,
            'data'       => [
                'msgid'     => $msgId,
                'outcome'   => $outcome,
                'happiness' => '',
                'comment'   => self::COMMENT,
                'userid'    => 0,
                'byuser'    => 0,
                'message'   => '',
            ],
            'created_at' => now(),
            'attempts'   => 0,
        ]);

        Log::info('TN closed post: outcome recorded', ['msgid' => $msgId, 'outcome' => $outcome]);

        return strtolower($outcome);
    }

    private function logDeleted(int $msgId, int $groupId, int $fromUser, string $text): void
    {
        DB::table('logs')->insert([
            'timestamp' => now(),
            'type'      => 'Message',
            'subtype'   => 'Deleted',
            'groupid'   => $groupId,
            'user'      => $fromUser ?: null,
            'msgid'     => $msgId,
            'text'      => $text,
        ]);
    }

    private function queueFreebieRemove(int $msgId): void
    {
        BackgroundTask::create([
            'task_type'  => BackgroundTask::TASK_FREEBIE_ALERTS_REMOVE,
            'data'       => ['msgid' => $msgId],
            'created_at' => now(),
            'attempts'   => 0,
        ]);
    }
}
