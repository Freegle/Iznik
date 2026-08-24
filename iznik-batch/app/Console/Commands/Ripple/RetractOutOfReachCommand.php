<?php

namespace App\Console\Commands\Ripple;

use App\Services\Ripple\ExpandService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ripple:retract-out-of-reach — one-off cap-backlog cleanup. For posts whose reach
 * was shrunk to the audience cap (ripple:recompute-reach), soft-delete the rippled-in
 * copies in groups now outside the capped reach, leaving the post live on the origin
 * and still-reached groups. The message is untouched (chats/replies keyed on msgid
 * keep working). New posts ripple capped from the start, so this is only needed for
 * the pre-cap backlog. --dry-run reports counts without writing.
 */
class RetractOutOfReachCommand extends Command
{
    protected $signature = 'ripple:retract-out-of-reach
                            {--dry-run : Count what would be retracted without writing}
                            {--limit=5000 : Max posts to process}
                            {--msgid= : Restrict to a single message ID}';

    protected $description = 'Retract rippled-in copies from groups no longer within a post\'s capped reach';

    public function handle(ExpandService $service): int
    {
        $target = (int) config('freegle.ripple.extent.target_users', 0);
        if (!config('freegle.ripple.extent.enabled') || $target <= 0) {
            $this->warn('ripple.extent cap is not enabled — there is no capped reach to retract against.');
            return Command::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        // Only posts whose reach could exceed the cap (the ones recompute shrunk) can
        // have out-of-reach copies. New posts ripple capped from the start.
        $q = DB::table('rippling_reach')
            ->where('status', '!=', 'rejected')
            ->where('total_freeglers', '>', $target);
        if ($this->option('msgid') !== null) {
            $q->where('msgid', (int) $this->option('msgid'));
        }
        $msgids = $q->orderBy('msgid')->limit((int) $this->option('limit'))->pluck('msgid');

        $stats = [
            'posts' => 0,
            'posts_with_excess' => 0,
            'would_retract_groups' => 0,
            'pulled_out_of_reach' => 0,
            'memberships_removed' => 0,
            'skipped_terminal' => 0,
        ];

        foreach ($msgids as $msgid) {
            $stats['posts']++;
            $n = $service->retractOutOfReachCopies((int) $msgid, $dryRun, $stats);
            if ($n > 0) {
                $stats['posts_with_excess']++;
            }
        }

        if ($dryRun) {
            $this->info(sprintf(
                'Would retract %d out-of-reach group-copies across %d/%d post(s) (cap=%d; %d skipped: terminal outcome).',
                $stats['would_retract_groups'],
                $stats['posts_with_excess'],
                $stats['posts'],
                $target,
                $stats['skipped_terminal'],
            ));
        } else {
            $this->info(sprintf(
                'Retracted %d out-of-reach group-copies across %d/%d post(s); removed %d ripple-join membership(s) (cap=%d; %d skipped: terminal outcome).',
                $stats['pulled_out_of_reach'],
                $stats['posts_with_excess'],
                $stats['posts'],
                $stats['memberships_removed'],
                $target,
                $stats['skipped_terminal'],
            ));
        }

        return Command::SUCCESS;
    }
}
