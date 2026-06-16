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
 * DARK until RIPPLE_HOLD_REPLIES is set (the hold side that creates these rows is
 * also behind that flag), so with the flag off there is nothing to release.
 */
class ReleaseRepliesCommand extends Command
{
    use GracefulShutdown;

    protected $signature = 'ripple:release-replies {--dry-run : Report without releasing}';

    protected $description = 'Release/expire held external replies (#3) as posts ripple out';

    public function handle(RippleReplyService $svc): int
    {
        $this->registerShutdownHandlers();

        if (!config('freegle.ripple.hold_replies', false)) {
            $this->info('Held-reply release disabled (RIPPLE_HOLD_REPLIES off).');

            return Command::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        $msgids = DB::table('chat_messages_rippling')
            ->where('status', 'held')
            ->distinct()
            ->pluck('msgid');

        $released = 0;
        $gone = 0;

        foreach ($msgids as $msgid) {
            $msgid = (int) $msgid;
            if ($dryRun) {
                continue;
            }

            $reach = DB::table('messages_reach')->where('msgid', $msgid)->first();
            if ($reach === null) {
                $gone += $svc->markGone($msgid);
            } elseif ($reach->status === 'done') {
                $released += $svc->releaseAll($msgid);
            } else {
                $released += $svc->releaseCovered($msgid);
            }
        }

        $this->info(($dryRun ? '[DRY RUN] ' : '') .
            "Posts with held replies: {$msgids->count()}, Released: {$released}, Gone: {$gone}");
        Log::info('ripple:release-replies complete', [
            'posts' => $msgids->count(), 'released' => $released, 'gone' => $gone,
        ]);

        return Command::SUCCESS;
    }
}
