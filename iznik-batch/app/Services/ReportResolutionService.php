<?php

namespace App\Services;

use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\Message;
use App\Models\Microaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Resolves member reports against a post. Mirrors the quorum Go already
 * enforces when a report is recorded (RecordReportVerdict in
 * iznik-server-go/microvolunteering/microvolunteering.go): two member
 * reports, or one moderator report, is quorum. Run on a schedule
 * (routes/console.php, reports:resolve, every minute) rather than inline
 * with the report, so a burst of reports only ever resolves once and a
 * report made while this is mid-run is picked up on the next tick.
 *
 * Idempotent like its Go counterpart: a message already taken down is
 * left alone, so re-running never notifies twice.
 */
class ReportResolutionService
{
    public const APPROVAL_QUORUM = 2;

    public function __construct(private readonly TakedownService $takedown)
    {
    }

    /**
     * Resolve every message with enough outstanding reports to meet quorum.
     * Returns the ids of the messages taken down this run.
     */
    public function resolvePending(): array
    {
        $msgids = Microaction::query()
            ->where('actiontype', 'CheckMessage')
            ->where('result', 'Reject')
            ->whereNotNull('comments')
            ->where(function ($q) {
                $q->whereNull('msgcategory')->orWhere('msgcategory', 'ShouldntBeHere');
            })
            ->distinct()
            ->pluck('msgid');

        $resolved = [];

        foreach ($msgids as $msgid) {
            if ($this->resolveOne((int) $msgid)) {
                $resolved[] = (int) $msgid;
            }
        }

        return $resolved;
    }

    private function resolveOne(int $msgid): bool
    {
        $message = Message::find($msgid);

        if (! $message || $message->deleted !== null) {
            // Nothing to do, or already resolved — matches Go's guard in
            // ResolveReports, which is what keeps a third report from
            // sending anything twice.
            return false;
        }

        $reports = Microaction::query()
            ->where('actiontype', 'CheckMessage')
            ->where('msgid', $msgid)
            ->where('result', 'Reject')
            ->whereNotNull('comments')
            ->where(function ($q) {
                $q->whereNull('msgcategory')->orWhere('msgcategory', 'ShouldntBeHere');
            })
            ->where('userid', '!=', $message->fromuser);

        // Only reports made since the last decision on the post count. A moderator who restored
        // a taken-down post has decided, and the reports that took it down are not counted again.
        if ($message->rejectedat !== null) {
            $reports->where('timestamp', '>', $message->rejectedat);
        }

        $reports = $reports->get();

        if ($reports->isEmpty()) {
            return false;
        }

        // A report counts when its author is a real, current member who is not banned and not
        // another account of the poster, so two throwaway or linked accounts cannot take a post
        // down between them.
        $reporterIds = $this->eligibleReporters($reports->pluck('userid')->unique()->values(), (int) $message->fromuser);
        $reporters = User::whereIn('id', $reporterIds)->get()->keyBy('id');

        $quorumMet = $reporters->contains(fn (User $u) => $u->isModerator())
            || $reporterIds->count() >= self::APPROVAL_QUORUM;

        if (! $quorumMet) {
            return false;
        }

        $why = 'Other members reported that it should not be on Freegle.';

        if (! $this->takedown->takeDown($msgid, $why)) {
            return false;
        }

        Log::info("ReportResolutionService: resolved message #{$msgid} on ".$reporterIds->count().' report(s)');

        foreach ($reporterIds as $reporterId) {
            $this->tellReporter((int) $reporterId, $message);
        }

        return true;
    }

    /**
     * The reporters whose reports count: not deleted, not banned, and not linked to the poster
     * as another account of the same person.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $reporterIds
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function eligibleReporters($reporterIds, int $posterId)
    {
        if ($reporterIds->isEmpty()) {
            return $reporterIds;
        }

        $linked = DB::table('users_related')
            ->where(function ($q) use ($posterId) {
                $q->where('user1', $posterId)->orWhere('user2', $posterId);
            })
            ->get(['user1', 'user2'])
            ->flatMap(fn ($r) => [(int) $r->user1, (int) $r->user2])
            ->unique();

        return User::whereIn('id', $reporterIds)
            ->whereNull('deleted')
            ->whereNull('banned')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->reject(fn ($id) => $linked->contains($id))
            ->values();
    }

    /**
     * Thank a reporter and tell them the outcome, in their own User2Mod
     * room. Same convention as TakedownService::tellPoster — a System
     * chat message addressed to the person being told, not Go's
     * system-user-as-sender MODMAIL shape. One tier, one convention.
     */
    private function tellReporter(int $reporterId, Message $message): void
    {
        if ($reporterId <= 0) {
            return;
        }

        $room = ChatRoom::getOrCreateUser2Mod($reporterId);

        if (! $room) {
            Log::warning("ReportResolutionService: could not open User2Mod room for user #{$reporterId}");

            return;
        }

        ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $reporterId,
            'message' => "Thanks for reporting \"{$message->subject}\". Enough people agreed with you that it has been taken down.",
            'type' => ChatMessage::TYPE_SYSTEM,
            'refmsgid' => $message->id,
            'date' => now(),
            'platform' => 0,
            'reviewrequired' => 0,
            'processingrequired' => 0,
            'processingsuccessful' => 1,
            'replyreceived' => 0,
        ]);
    }
}
