<?php

namespace App\Console\Commands\Chat;

use App\Services\ChatMergedRoomRepairService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Hands chat rooms left on a merged-away account to the account it was merged
 * into. See ChatMergedRoomRepairService for what is done to each room.
 *
 * The pairs come from a file because the old email-verify merge recorded none
 * in the database; they were recovered from the API log and from the merge's
 * own trace in users_emails. The file holds member ids, so it is kept on the
 * host and never committed.
 */
#[AsCommand(name: 'chat:repair-merged-rooms')]
class RepairMergedRoomsCommand extends Command
{
    protected $signature = 'chat:repair-merged-rooms
        {--pairs= : CSV file of loser,survivor account ids, one pair per line (a header line is ignored)}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Hand chat rooms stranded on a merged-away account to the surviving account';

    public function handle(ChatMergedRoomRepairService $repair): int
    {
        $path = (string) $this->option('pairs');
        if ($path === '' || ! is_readable($path)) {
            $this->error('Give a readable --pairs file of loser,survivor ids.');

            return self::FAILURE;
        }

        $pairs = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $n => $line) {
            $cols = array_map('trim', str_getcsv($line));
            if (count($cols) < 2 || ! ctype_digit($cols[0]) || ! ctype_digit($cols[1])) {
                if ($n === 0) {
                    continue;
                }
                $this->error('Line '.($n + 1)." is not loser,survivor: {$line}");

                return self::FAILURE;
            }
            $pairs[] = [(int) $cols[0], (int) $cols[1]];
        }

        $dryRun = (bool) $this->option('dry-run');
        $this->info(($dryRun ? 'DRY RUN: ' : '').count($pairs).' pairs.');

        $totals = [];
        $failed = 0;
        foreach ($pairs as $i => [$loser, $survivor]) {
            try {
                $stats = $repair->repairPair($loser, $survivor, $dryRun);
            } catch (\Throwable $e) {
                // One pair failing must not stop the rest; a rerun picks it up.
                $failed++;
                $this->error("Pair {$loser} -> {$survivor} failed: ".$e->getMessage());
                Log::error('chat:repair-merged-rooms pair failed', ['loser' => $loser, 'survivor' => $survivor, 'error' => $e->getMessage()]);

                continue;
            }

            $status = $stats['status'];
            unset($stats['status']);
            $totals[$status] = ($totals[$status] ?? 0) + 1;
            foreach ($stats as $k => $v) {
                $totals[$k] = ($totals[$k] ?? 0) + $v;
            }

            if ($status !== 'repaired' || array_sum($stats) > 0) {
                $this->line(sprintf('  [%d/%d] %d -> %d: %s %s', $i + 1, count($pairs), $loser, $survivor, $status, json_encode($stats)));
            }
        }

        $this->info(($dryRun ? 'Would change: ' : 'Done: ').json_encode($totals).($failed ? ", {$failed} pairs failed" : ''));

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
