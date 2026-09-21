<?php

namespace App\Console\Commands\Content;

use App\Services\BlockedKeywordBackfillService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Re-applies Freegle-wide 'block' concern keywords to recent chat messages and posts.
 *
 * The same pass runs automatically when a block keyword is created (the Go API queues a
 * concern_keyword_backfill task for the last 24 hours). This command is the manual form:
 * a wider window, a dry run, or one keyword at a time.
 */
class RejectBlockedKeywordCommand extends Command
{
    protected $signature = 'content:reject-blocked-keyword
                            {--since= : Only content from this datetime on (default: 24 hours ago)}
                            {--keyword=* : Restrict to these concern_keywords rows, by id or by keyword text}
                            {--limit= : Stop after this many matches in each of chat and posts}
                            {--dry-run : Report what would change without changing it}';

    protected $description = 'Reject delivered chat messages and remove posts that match a global block concern keyword';

    public function handle(BlockedKeywordBackfillService $service): int
    {
        $since = $this->option('since')
            ? Carbon::parse($this->option('since'))
            : now()->subDay();

        $keywordIds = $this->resolveKeywordIds((array) $this->option('keyword'));
        if ($keywordIds === []) {
            $this->error('None of the given keywords is a global concern keyword with action=block.');

            return Command::FAILURE;
        }

        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $dryRun = (bool) $this->option('dry-run');

        $this->info(($dryRun ? '[DRY RUN] ' : '') . "Applying block keywords to content since {$since->toDateTimeString()}"
            . ($keywordIds !== null ? ' (keyword ids ' . implode(', ', $keywordIds) . ')' : ' (every global block keyword)'));

        $result = $service->run($since, $keywordIds, $dryRun, $limit, fn(string $line) => $this->line($line));

        foreach (['chat' => 'Chat messages', 'posts' => 'Posts'] as $key => $label) {
            $s = $result[$key];
            $this->info("{$label}: {$s['matched']} matched, "
                . ($dryRun ? "would change {$s['matched']} (dry run)" : "changed {$s['changed']}")
                . " of {$s['scanned']} scanned");
            foreach ($s['by_keyword'] as $keyword => $n) {
                $this->line("  {$keyword}: {$n}");
            }
            if ($s['samples'] !== []) {
                $this->line('  sample ids: ' . implode(', ', $s['samples']));
            }
        }

        return Command::SUCCESS;
    }

    /**
     * @return int[]|null ids to restrict to, null for no restriction, [] when nothing given resolves.
     */
    private function resolveKeywordIds(array $given): ?array
    {
        $given = array_values(array_filter(array_map('trim', $given), fn($v) => $v !== ''));
        if ($given === []) {
            return null;
        }

        $ids = [];
        foreach ($given as $value) {
            $rows = DB::table('concern_keywords')
                ->where('scope', 'global')
                ->where('action', 'block')
                ->where(function ($q) use ($value) {
                    $q->where('keyword', $value);
                    if (ctype_digit($value)) {
                        $q->orWhere('id', (int) $value);
                    }
                })
                ->pluck('id');

            if ($rows->isEmpty()) {
                $this->warn("'{$value}' is not a global block keyword; ignored.");
            }

            foreach ($rows as $id) {
                $ids[(int) $id] = (int) $id;
            }
        }

        return array_values($ids);
    }
}
