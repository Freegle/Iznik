<?php

namespace App\Console\Commands\Support;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Retention for support cases: Handled cases are deleted after two years (their messages go with
 * them), and the first pass brief, which holds facts about a member, is cleared after one year.
 */
#[AsCommand(name: 'support:purge-cases')]
class SupportPurgeCasesCommand extends Command
{
    public const DELETE_AFTER_YEARS = 2;

    public const CLEAR_BRIEF_AFTER_YEARS = 1;

    private const CHUNK = 500;

    protected $signature = 'support:purge-cases';

    protected $description = 'Delete old handled support cases and clear old first pass briefs';

    public function handle(): int
    {
        $deleted = 0;
        do {
            $n = DB::table('support_cases')->where('status', 'Handled')
                ->whereRaw('COALESCE(handled_at, created_at) < NOW() - INTERVAL ? YEAR', [self::DELETE_AFTER_YEARS])
                ->limit(self::CHUNK)->delete();
            $deleted += $n;
        } while ($n === self::CHUNK);

        $cleared = 0;
        do {
            $n = DB::table('support_cases')->whereNotNull('brief')
                ->whereRaw('created_at < NOW() - INTERVAL ? YEAR', [self::CLEAR_BRIEF_AFTER_YEARS])
                ->limit(self::CHUNK)->update(['brief' => null]);
            $cleared += $n;
        } while ($n === self::CHUNK);

        $this->info("Deleted {$deleted} handled cases, cleared {$cleared} briefs.");

        return self::SUCCESS;
    }
}
