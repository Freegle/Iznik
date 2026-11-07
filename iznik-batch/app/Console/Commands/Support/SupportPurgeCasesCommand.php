<?php

namespace App\Console\Commands\Support;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Retention for support cases: Handled cases are deleted after two years, and so are Open cases
 * with no activity for two years (their messages and first pass runs go with them). The first pass
 * brief, and the analysis and transcript of first pass runs, which hold facts about a member, are
 * cleared after one year.
 */
#[AsCommand(name: 'support:purge-cases')]
class SupportPurgeCasesCommand extends Command
{
    public const DELETE_AFTER_YEARS = 2;

    public const CLEAR_BRIEF_AFTER_YEARS = 1;

    private const CHUNK = 500;

    protected $signature = 'support:purge-cases';

    protected $description = 'Delete old support cases and clear old first pass briefs and runs';

    public function handle(): int
    {
        $deleted = 0;
        do {
            $ids = DB::table('support_cases')->where('status', 'Handled')
                ->whereRaw('COALESCE(handled_at, created_at) < NOW() - INTERVAL ? YEAR', [self::DELETE_AFTER_YEARS])
                ->limit(self::CHUNK)->pluck('id')->all();
            $deleted += $this->deleteCases($ids);
        } while (count($ids) === self::CHUNK);

        // Open and untouched: no message and no change for two years.
        do {
            $ids = DB::table('support_cases as c')->where('c.status', 'Open')
                ->whereRaw('c.updated_at < NOW() - INTERVAL ? YEAR', [self::DELETE_AFTER_YEARS])
                ->whereNotExists(function ($q) {
                    $q->select(DB::raw(1))->from('support_case_messages as m')->whereColumn('m.caseid', 'c.id')
                        ->whereRaw('m.created_at >= NOW() - INTERVAL ? YEAR', [self::DELETE_AFTER_YEARS]);
                })
                ->limit(self::CHUNK)->pluck('c.id')->all();
            $deleted += $this->deleteCases($ids);
        } while (count($ids) === self::CHUNK);

        // updated_at is set to itself so that clearing a brief does not look like activity.
        $cleared = 0;
        do {
            $n = DB::table('support_cases')->whereNotNull('brief')
                ->whereRaw('created_at < NOW() - INTERVAL ? YEAR', [self::CLEAR_BRIEF_AFTER_YEARS])
                ->limit(self::CHUNK)->update(['brief' => null, 'updated_at' => DB::raw('updated_at')]);
            $cleared += $n;
        } while ($n === self::CHUNK);

        do {
            $n = DB::table('support_ai_runs')->where('kind', 'FirstPass')
                ->where(fn ($q) => $q->whereNotNull('analysis')->orWhereNotNull('transcript'))
                ->whereRaw('created_at < NOW() - INTERVAL ? YEAR', [self::CLEAR_BRIEF_AFTER_YEARS])
                ->limit(self::CHUNK)->update(['analysis' => null, 'transcript' => null]);
        } while ($n === self::CHUNK);

        $this->info("Deleted {$deleted} cases, cleared {$cleared} briefs.");

        return self::SUCCESS;
    }

    /**
     * Delete cases and the first pass runs written for them (their own passes and replays).
     *
     * @param  array<int, int|string>  $ids
     */
    private function deleteCases(array $ids): int
    {
        if (! $ids) {
            return 0;
        }

        $queries = [];
        foreach ($ids as $id) {
            $queries[] = "First pass for support case {$id}";
            $queries[] = "Replay of support case {$id}";
        }
        foreach (array_chunk($queries, 500) as $chunk) {
            DB::table('support_ai_runs')->where('kind', 'FirstPass')->whereIn('query', $chunk)->delete();
        }

        return DB::table('support_cases')->whereIn('id', $ids)->delete();
    }
}
