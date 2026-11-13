<?php

namespace App\Console\Commands\Support;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Retention for first pass runs: after a year a run keeps its tokens, cost and outcome, and loses
 * what it found (the analysis and the transcript), which is about a member.
 */
#[AsCommand(name: 'support:purge-runs')]
class SupportPurgeRunsCommand extends Command
{
    public const CLEAR_AFTER_YEARS = 1;

    private const CHUNK = 500;

    protected $signature = 'support:purge-runs';

    protected $description = 'Clear the analysis and transcript of first pass runs more than a year old';

    public function handle(): int
    {
        $cleared = 0;
        do {
            $n = DB::table('support_ai_runs')->where('kind', 'FirstPass')
                ->where(fn ($q) => $q->whereNotNull('analysis')->orWhereNotNull('transcript'))
                ->where('created_at', '<', now()->subYears(self::CLEAR_AFTER_YEARS))
                ->limit(self::CHUNK)->update(['analysis' => null, 'transcript' => null]);
            $cleared += $n;
        } while ($n === self::CHUNK);

        $this->info("Cleared {$cleared} first pass runs.");

        return self::SUCCESS;
    }
}
