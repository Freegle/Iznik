<?php

namespace App\Console\Commands\Ripple;

use App\Services\Ripple\RippleEngagementService;
use App\Traits\GracefulShutdown;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * ripple:clear-engaged - makes a ripple-created membership ordinary once the member has
 * posted on the community, replied to one of its posts, or written back to one of its
 * members about their rippled post, so its moderators can write to them (Discourse 10239).
 * See RippleEngagementService.
 *
 * --since runs a one-off backfill over everything from that date, without moving the
 * scheduled run's saved positions. Rippling began making these memberships on
 * 2026-06-23, so --since=2026-06-23 covers every one of them.
 */
#[AsCommand(name: 'ripple:clear-engaged')]
class ClearEngagedMembershipsCommand extends Command
{
    use GracefulShutdown;

    protected $signature = 'ripple:clear-engaged
                            {--days=30 : How far back a first run looks, when no position is saved}
                            {--since= : Backfill: check everything since this date (e.g. 2026-06-23), without moving the scheduled position}';

    protected $description = 'Make ripple-created memberships ordinary once the member has engaged with the community';

    public function handle(RippleEngagementService $svc): int
    {
        $this->registerShutdownHandlers();

        $since = $this->option('since');
        if ($since !== null) {
            try {
                $from = \Carbon\Carbon::parse($since);
            } catch (\Throwable) {
                $this->error("--since must be a date, such as 2026-06-23; got '{$since}'.");

                return self::FAILURE;
            }

            $this->info("Backfilling from {$from->toDateString()}.");
            $stats = $svc->backfill($from);
        } else {
            $stats = $svc->run((int) $this->option('days'));
        }

        $this->info("Made ordinary: {$stats['posted']} posted, {$stats['replied']} replied, "
            ."{$stats['wrote_back']} wrote back.");

        if (array_sum($stats) > 0) {
            Log::info('ripple:clear-engaged', $stats);
        }

        return self::SUCCESS;
    }
}
