<?php

namespace App\Console\Commands\Ripple;

use App\Services\Ripple\RippleReplyService;
use App\Traits\GracefulShutdown;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ripple:release-replies — releases or expires held external replies (#3 / PR C)
 * as posts ripple out. For each post with held replies:
 *   - no reach row  → the post left messages_spatial (taken/withdrawn) → mark gone
 *   - reach 'done'  → max reach without covering everyone → release anyway
 *   - otherwise     → release the ones now inside reach, AND the ones whose delay
 *                     has run out
 *
 * That last clause is the one that matters most in practice. Coverage releases only
 * the minority of held repliers the ripple eventually reaches - three in four of them
 * live somewhere it never will - so the delay is the only exit the rest ever get
 * short of the 'done' backstop days later. See RippleReplyService::releaseDue.
 *
 * Inert until the reach engine is live: until a reply is held there is nothing to
 * release, so this is a cheap no-op.
 */
class ReleaseRepliesCommand extends Command
{
    use GracefulShutdown;

    protected $signature = 'ripple:release-replies
                            {--dry-run : Report without releasing}
                            {--release-open : Release ALL still-held replies for posts that are still open (not taken/withdrawn), regardless of reach coverage}
                            {--since-hours= : With --release-open, restrict the backfill to held replies whose reply was written within this many hours (row-level; open posts only)}';

    protected $description = 'Release/expire held external replies (#3) as posts ripple out';

    public function handle(RippleReplyService $svc): int
    {
        $this->registerShutdownHandlers();

        $dryRun = (bool) $this->option('dry-run');
        $releaseOpen = (bool) $this->option('release-open');
        $sinceHours = $this->option('since-hours') !== null ? (int) $this->option('since-hours') : null;

        // Scoped backfill: release recent held replies (row-level) for still-open posts only.
        // Row-level so a post with both recent and old held replies only has the recent ones
        // released, honouring the window exactly.
        if ($releaseOpen && $sinceHours !== null) {
            return $this->backfillRecentOpen($svc, $sinceHours, $dryRun);
        }

        $msgids = DB::table('rippling_held_replies')
            ->where('status', 'held')
            ->distinct()
            ->pluck('msgid');

        $released = 0;
        $gone = 0;
        $wouldRelease = 0;
        $wouldGone = 0;

        foreach ($msgids as $msgid) {
            $msgid = (int) $msgid;

            // Backfill mode: don't strand held replies behind reach that never reached the
            // replier. For any post that is still open, release everyone now (delivered via
            // the notification pipeline, which is keyed on releasedat); only genuinely gone
            // posts are marked gone.
            if ($releaseOpen) {
                if ($this->postIsGone($msgid)) {
                    if ($dryRun) {
                        $wouldGone += $this->heldCount($msgid);
                    } else {
                        $gone += $svc->markGone($msgid);
                    }
                } else {
                    if ($dryRun) {
                        $wouldRelease += $this->heldCount($msgid);
                    } else {
                        $released += $svc->releaseAll($msgid, 'backfill');
                    }
                }

                continue;
            }

            // Check "gone" FIRST, before consulting the reach row. A Taken/Received/Withdrawn
            // (or deleted) post must always mark its held replies gone — telling the repliers,
            // never surfacing them to the offerer — even when a stale reach row still lingers.
            // Otherwise the 'done' branch below wins the race and releaseAll floods the offerer
            // of an item they have already given away with far-away held replies. Real case
            // (Discourse 9808/#555): a TV marked Taken at 12:48 still had its reach row reach
            // 'done' at 13:33, and at 13:34 releaseAll dumped all 13 held TrashNothing replies
            // into the offerer's inbox as if they were live.
            if ($this->postIsGone($msgid)) {
                if ($dryRun) {
                    $wouldGone += $this->heldCount($msgid);
                } else {
                    $gone += $svc->markGone($msgid);
                }

                continue;
            }

            $reach = DB::table('rippling_reach')->where('msgid', $msgid)->first();

            if ($reach === null) {
                // No reach row and the post is not gone → it is transiently absent from
                // messages_spatial (reach row removed) without being taken. Wait for reach to
                // be re-initialised rather than releasing or wrongly telling repliers it's gone.
                continue;
            } elseif ($reach->status === 'done') {
                if ($dryRun) {
                    $wouldRelease += $this->heldCount($msgid);
                } else {
                    $released += $svc->releaseAll($msgid);
                }
            } else {
                if ($dryRun) {
                    $wouldRelease += $this->heldCount($msgid);
                } else {
                    // Coverage first, so a reply the ripple has genuinely reached is
                    // counted as reached rather than as a delay expiring; then the
                    // delay, which is the only exit most held repliers ever get.
                    $released += $svc->releaseCovered($msgid);
                    $released += $svc->releaseDue($msgid);
                }
            }
        }

        if ($dryRun) {
            $this->info("[DRY RUN] Posts with held replies: {$msgids->count()}, " .
                "would release ≤{$wouldRelease}, would mark gone {$wouldGone}");

            return Command::SUCCESS;
        }

        $this->info("Posts with held replies: {$msgids->count()}, Released: {$released}, Gone: {$gone}");
        Log::info('ripple:release-replies complete', [
            'posts' => $msgids->count(), 'released' => $released, 'gone' => $gone,
        ]);

        return Command::SUCCESS;
    }

    /**
     * Release recent held replies (reply written within $sinceHours) for posts still open,
     * one row at a time. Gone posts are left for the normal (per-post) release path.
     */
    private function backfillRecentOpen(RippleReplyService $svc, int $sinceHours, bool $dryRun): int
    {
        $rows = DB::table('rippling_held_replies as h')
            ->join('chat_messages as cm', 'cm.id', '=', 'h.chatmsgid')
            ->where('h.status', 'held')
            ->where('cm.date', '>=', now()->subHours($sinceHours))
            ->orderBy('h.id')
            ->get(['h.id', 'h.msgid']);

        $released = 0;
        $skippedGone = 0;

        foreach ($rows as $row) {
            if ($this->postIsGone((int) $row->msgid)) {
                $skippedGone++;

                continue;
            }
            if ($dryRun) {
                $released++;
            } else {
                $released += $svc->releaseHeldRow((int) $row->id);
            }
        }

        $prefix = $dryRun ? '[DRY RUN] ' : '';
        $this->info("{$prefix}Recent held replies (≤{$sinceHours}h): {$rows->count()}, " .
            ($dryRun ? 'would release' : 'released') . " {$released}, skipped (post gone) {$skippedGone}");

        if (! $dryRun) {
            Log::info('ripple:release-replies backfill complete', [
                'since_hours' => $sinceHours, 'released' => $released, 'skipped_gone' => $skippedGone,
            ]);
        }

        return Command::SUCCESS;
    }

    /** A post is genuinely gone (terminal) if deleted or marked Taken/Received. */
    private function postIsGone(int $msgid): bool
    {
        $deleted = DB::table('messages')->where('id', $msgid)->value('deleted');
        if ($deleted !== null) {
            return true;
        }

        return DB::table('messages_outcomes')
            ->where('msgid', $msgid)
            ->whereIn('outcome', ['Taken', 'Received', 'Withdrawn'])
            ->exists();
    }

    private function heldCount(int $msgid): int
    {
        return (int) DB::table('rippling_held_replies')
            ->where('msgid', $msgid)
            ->where('status', 'held')
            ->count();
    }
}
