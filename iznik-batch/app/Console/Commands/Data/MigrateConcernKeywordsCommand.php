<?php

namespace App\Console\Commands\Data;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateConcernKeywordsCommand extends Command
{
    protected $signature = 'data:migrate-concern-keywords
                            {--dry-run : Show what would be migrated without writing}
                            {--force : Re-run even if concern_keywords already has rows}';

    protected $description = 'Copy data from worrywords and spam_keywords into concern_keywords. Safe to re-run (uses INSERT IGNORE).';

    private const WORRY_CATEGORY_MAP = [
        'Regulated'  => 'substance_regulated',
        'Reportable' => 'substance_reportable',
        'Medicine'   => 'substance_medicine',
        'Review'     => 'review',
        'Allowed'    => 'allowed',
    ];

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $force  = $this->option('force');

        $existing = DB::table('concern_keywords')->count();
        if ($existing > 0 && !$force) {
            $this->warn("concern_keywords already has {$existing} rows. Use --force to re-run.");
            return Command::SUCCESS;
        }

        // Track global keywords already inserted to detect cross-table conflicts.
        $seen = [];

        $wwCount  = $this->migrateWorrywords($dryRun, $seen);
        $skCount  = $this->migrateSpamKeywords($dryRun, $seen);
        $grpCount = $this->migratePerGroupWords($dryRun);

        $mode = $dryRun ? '[DRY RUN] Would insert' : 'Inserted';
        $this->info("{$mode}: {$wwCount} worryword rows, {$skCount} spam_keyword rows, {$grpCount} per-group word rows.");
        return Command::SUCCESS;
    }

    private function migrateWorrywords(bool $dryRun, array &$seen): int
    {
        $rows = DB::table('worrywords')->get();
        $count = 0;

        foreach ($rows as $ww) {
            $category = self::WORRY_CATEGORY_MAP[$ww->type] ?? 'review';
            $action   = in_array($ww->type, ['Regulated', 'Reportable']) ? 'block' : 'flag';
            $matchMode = ($ww->type === 'Allowed') ? 'literal' : 'fuzzy';

            $record = [
                'keyword'    => $ww->keyword,
                'substance'  => $ww->substance ?? null,
                'category'   => $category,
                'match_mode' => $matchMode,
                'action'     => $action,
                'scope'      => 'global',
                'group_id'   => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if ($dryRun) {
                $this->line("  [ww] {$ww->keyword} → {$category}/{$matchMode}/{$action}");
            } else {
                DB::table('concern_keywords')->insertOrIgnore($record);
            }
            $seen[$ww->keyword] = "worrywords:{$ww->type}";
            $count++;
        }

        return $count;
    }

    private function migrateSpamKeywords(bool $dryRun, array &$seen): int
    {
        $rows = DB::table('spam_keywords')->get();
        $count = 0;

        foreach ($rows as $sk) {
            $category  = ($sk->action === 'Spam') ? 'scam' : 'review';
            $matchMode = ($sk->type === 'Regex') ? 'regex' : 'literal';
            $action    = ($sk->action === 'Spam') ? 'block' : 'flag';

            if (isset($seen[$sk->word])) {
                $this->warn("  [sk] Skipping duplicate '{$sk->word}' (already migrated from {$seen[$sk->word]})");
                continue;
            }

            $record = [
                'keyword'    => $sk->word,
                'substance'  => null,
                'category'   => $category,
                'match_mode' => $matchMode,
                'exclude'    => $sk->exclude ?? null,
                'action'     => $action,
                'scope'      => 'global',
                'group_id'   => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if ($dryRun) {
                $this->line("  [sk] {$sk->word} → {$category}/{$matchMode}/{$action}");
            } else {
                DB::table('concern_keywords')->insertOrIgnore($record);
            }
            $seen[$sk->word] = "spam_keywords:{$sk->type}";
            $count++;
        }

        return $count;
    }

    private function migratePerGroupWords(bool $dryRun): int
    {
        $groups = DB::table('groups')
            ->whereNotNull('settings')
            ->select(['id', 'settings'])
            ->get();

        $count = 0;

        foreach ($groups as $group) {
            $settings = json_decode($group->settings, true);
            $wordsStr = $settings['spammers']['worrywords'] ?? '';
            if (empty($wordsStr)) {
                continue;
            }

            $words = array_filter(array_map('trim', explode(',', $wordsStr)));
            foreach ($words as $word) {
                if (empty($word)) {
                    continue;
                }
                $record = [
                    'keyword'    => $word,
                    'substance'  => null,
                    'category'   => 'review',
                    'match_mode' => 'literal',
                    'action'     => 'flag',
                    'scope'      => 'group',
                    'group_id'   => $group->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                if ($dryRun) {
                    $this->line("  [grp:{$group->id}] {$word} → review/literal/flag");
                } else {
                    DB::table('concern_keywords')->insertOrIgnore($record);
                }
                $count++;
            }
        }

        return $count;
    }
}
