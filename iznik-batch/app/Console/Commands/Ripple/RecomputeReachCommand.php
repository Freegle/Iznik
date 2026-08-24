<?php

namespace App\Console\Commands\Ripple;

use App\Services\Ripple\ExpandService;
use Illuminate\Console\Command;

/**
 * ripple:recompute-reach — one-off backfill that shrinks the stored reach of
 * existing active posts to the audience-budget cap (config freegle.ripple.extent),
 * for posts whose reach was computed before the cap was enabled.
 *
 * Re-fetches each post's now-capped schedule and overwrites its polygon/schedule
 * at the current tick. It does NOT ripple groups, touch memberships, mail, or
 * retract already-delivered copies (see ExpandService::recomputeReach). Safe to
 * re-run; --dry-run reports without writing.
 */
class RecomputeReachCommand extends Command
{
    protected $signature = 'ripple:recompute-reach
                            {--dry-run : Report what would shrink without writing}
                            {--limit=2000 : Max reach rows to process this run}
                            {--msgid= : Restrict to a single message ID}';

    protected $description = 'Backfill: shrink over-reached active posts to the ripple audience cap';

    public function handle(ExpandService $service): int
    {
        $target = (int) config('freegle.ripple.extent.target_users', 0);
        if (!config('freegle.ripple.extent.enabled') || $target <= 0) {
            $this->warn('ripple.extent cap is not enabled (set RIPPLE_EXTENT_ENABLED=true and a positive '
                . 'RIPPLE_EXTENT_TARGET_USERS) — there is nothing smaller to shrink reach to.');
            return Command::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $r = $service->recomputeReach(
            $dryRun,
            (int) $this->option('limit'),
            $this->option('msgid') !== null ? (int) $this->option('msgid') : null,
        );

        $this->info(sprintf(
            '%s reach for %d/%d candidate post(s) to cap=%d (%d skipped: unreachable or cap did not bind).',
            $dryRun ? 'Would shrink' : 'Shrank',
            $r['shrunk'],
            $r['candidates'],
            $target,
            $r['skipped'],
        ));

        $before = (int) ($r['groups_before'] ?? 0);
        $after = (int) ($r['groups_after'] ?? 0);
        $n = max(1, (int) $r['shrunk']);
        $pct = $before > 0 ? round(100 * ($before - $after) / $before, 1) : 0.0;
        $this->info(sprintf(
            'Crossposts across those posts: %d -> %d group-memberships (-%s%%); avg per post %.1f -> %.1f groups.',
            $before,
            $after,
            $pct,
            $before / $n,
            $after / $n,
        ));

        return Command::SUCCESS;
    }
}
