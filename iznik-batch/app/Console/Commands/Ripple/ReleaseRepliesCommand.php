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
 *   - otherwise     → release the ones now inside reach
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
                        $released += $svc->releaseAll($msgid);
                    }
                }

                continue;
            }

            $reach = DB::table('rippling_reach')->where('msgid', $msgid)->first();

            if ($reach === null) {
                // No reach row. This is only terminal ("taken-gone") if the post is
                // ACTUALLY gone — deleted, or with a Taken/Received outcome. A post can
                // be transiently absent from messages_spatial (and so have its reach row
                // removed) without being taken; in that case wait for reach to be
                // re-initialised rather than wrongly telling repliers it's gone.
                if (!$this->postIsGone($msgid)) {
                    continue;
                }
                if ($dryRun) {
                    $wouldGone += $this->heldCount($msgid);
                } else {
                    $gone += $svc->markGone($msgid);
                }
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
                    $released += $svc->releaseCovered($msgid);
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
