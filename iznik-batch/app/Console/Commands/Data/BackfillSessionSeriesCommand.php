<?php

namespace App\Console\Commands\Data;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One-shot backfill for sessions.series rows corrupted by the pre-PR-219
 * bug where Go wrote utils.RandomHex(16) (a 32-char hex string) into a
 * bigint unsigned column. MySQL silently coerced to 0 (hex starting with
 * a non-digit) or 18446744073709551615 / MAX uint64 (hex starting with
 * 'f'). Both values break the legacy Authorization2 persistent-token
 * path (auth.go rejects series == 0, and MAX uint64 doesn't survive a
 * JSON round-trip through JavaScript's Number).
 *
 * Active JWT sessions are unaffected by this UPDATE — series isn't in
 * the JWT claim set, only the persistent token. Users on the persistent
 * token path already had a broken series and will re-auth next visit
 * either way.
 */
class BackfillSessionSeriesCommand extends Command
{
    protected $signature = 'sessions:backfill-series {--dry-run : Show the count of affected rows without updating}';

    protected $description = 'Replace corrupted sessions.series values (0 or MAX uint64) with JS-safe random values.';

    public function handle(): int
    {
        $affected = DB::table('sessions')
            ->where(function ($q) {
                $q->where('series', 0)->orWhere('series', '18446744073709551615');
            })
            ->count();

        $this->info("Found {$affected} sessions row(s) with corrupted series.");

        if ($affected === 0) {
            return Command::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn('Dry run — no changes made.');
            return Command::SUCCESS;
        }

        $updated = 0;
        $batchSize = 500;

        while (true) {
            $ids = DB::table('sessions')
                ->where(function ($q) {
                    $q->where('series', 0)->orWhere('series', '18446744073709551615');
                })
                ->limit($batchSize)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            foreach ($ids as $id) {
                DB::table('sessions')
                    ->where('id', $id)
                    ->update(['series' => $this->randomJsSafeUint64()]);
                $updated++;
            }

            $this->info("Updated {$updated} / {$affected}");
        }

        Log::info('sessions.series backfill complete', ['rows_updated' => $updated]);
        $this->info("Done. Updated {$updated} row(s).");

        return Command::SUCCESS;
    }

    /**
     * Mirrors Go's utils.RandomUint64 from PR #219: a non-zero random
     * value in [1, 2^53-1] so it round-trips through a JavaScript Number
     * without precision loss (Number.MAX_SAFE_INTEGER is 2^53-1).
     */
    private function randomJsSafeUint64(): int
    {
        $max = (1 << 53) - 1;
        do {
            $bytes = random_bytes(8);
            $high = unpack('J', $bytes)[1];
            $v = $high & $max;
        } while ($v === 0);

        return $v;
    }
}
