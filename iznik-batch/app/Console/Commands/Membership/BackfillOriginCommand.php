<?php

namespace App\Console\Commands\Membership;

use App\Console\Concerns\PreventsOverlapping;
use App\Services\MembershipOriginBackfillService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Fill memberships.added_origin and memberships_history.added_origin from the join logs, for
 * rows written before every insert recorded it. DRY-RUN BY DEFAULT: reports what it would set
 * and changes nothing; pass --send to write. Only rows still 'unknown' are read or written, so
 * it is safe to stop and run again. See MembershipOriginBackfillService for the rules.
 */
#[AsCommand(name: 'memberships:backfill-origin')]
class BackfillOriginCommand extends Command
{
    use PreventsOverlapping;

    protected $signature = 'memberships:backfill-origin
                            {--user= : Restrict to a single user id}
                            {--chunk=1000 : Rows per read and per UPDATE}
                            {--send : Write the origins (default: dry-run report only)}';

    protected $description = 'Backfill added_origin on memberships and memberships_history from the Group/Joined logs';

    public function handle(MembershipOriginBackfillService $service): int
    {
        if (!$this->acquireLock()) {
            $this->info('Already running, exiting.');

            return Command::SUCCESS;
        }

        try {
            $send = (bool) $this->option('send');
            $userId = $this->option('user') !== null ? (int) $this->option('user') : null;
            $chunk = max(1, (int) $this->option('chunk'));

            if (!$send) {
                $this->info('DRY RUN - nothing will be written. Pass --send to apply.');
            }

            $started = microtime(true);
            $stats = $service->backfill($userId, $chunk, $send,
                fn (string $message) => $this->output->write("\r{$message}...   "));
            $this->output->writeln('');
            $this->info(sprintf('Took %d minutes.', round((microtime(true) - $started) / 60)));

            $verb = $send ? 'Set' : '[DRY RUN] Would set';
            $this->report("{$verb} on memberships", $stats['memberships'], $stats['memberships_unknown']);
            $this->report("{$verb} on memberships_history", $stats['history'], $stats['history_unknown']);

            if ($stats['unrecognised_texts']) {
                $this->line('Unrecognised join texts by the member themselves (left unknown):');
                $this->table(['Text', 'Rows'], collect($stats['unrecognised_texts'])
                    ->sortDesc()->map(fn ($n, $text) => [$text, $n])->values()->all());
            }

            $this->line('Disagreements (reported only; up to 10 membership ids each):');
            $this->table(['Check', 'Memberships', 'Sample ids'], collect($stats['checks'])
                ->map(fn ($n, $name) => [$name, $n, implode(', ', $stats['samples'][$name] ?? [])])
                ->values()->all());

            Log::info('memberships:backfill-origin complete', $stats + ['send' => $send]);

            return Command::SUCCESS;
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * @param  array<string,int>  $byOrigin
     * @param  array<string,int>  $unknownReasons
     */
    private function report(string $title, array $byOrigin, array $unknownReasons): void
    {
        $this->line("{$title} (".array_sum($byOrigin).' rows read):');
        arsort($byOrigin);
        $this->table(['added_origin', 'Rows'], collect($byOrigin)->map(fn ($n, $o) => [$o, $n])->values()->all());

        if ($unknownReasons) {
            arsort($unknownReasons);
            $this->line('Left unknown, by reason:');
            $this->table(['Reason', 'Rows'], collect($unknownReasons)->map(fn ($n, $r) => [$r, $n])->values()->all());
        }
    }
}
