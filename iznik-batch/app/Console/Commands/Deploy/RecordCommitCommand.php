<?php

namespace App\Console\Commands\Deploy;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Record the currently-deployed Laravel git commit SHA into the config table
 * (key `deploy.laravel_commit`) so the Go API's /api/version endpoint can report
 * the live Laravel build.
 *
 * Unlike deploy:refresh (which is heavy — clears caches, restarts queue workers
 * and supervisor programs), this command does ONLY the commit-recording, so it
 * is safe to run frequently on the scheduler. That makes /api/version.laravel_commit
 * self-heal after any deploy without depending on version.txt change-detection.
 *
 * The monitor-fsm "verified-live" gate compares this against a merged PR's commit
 * to decide a fix is actually live before replying on the reporter's Discourse post.
 */
class RecordCommitCommand extends Command
{
    protected $signature = 'deploy:record-commit';

    protected $description = 'Record the deployed Laravel git commit to config.deploy.laravel_commit (lightweight; safe to schedule).';

    public function handle(): int
    {
        // The Laravel app (iznik-batch) is a SUBDIR of the monorepo, so .git lives
        // at the monorepo root, not at base_path(). Walk up from base_path() to find
        // it — the recorded commit is then the monorepo HEAD, which matches the PRs
        // the monitor-fsm deploy gate compares against.
        $commit = $this->findGitCommit(base_path());

        if (! $commit) {
            $this->warn('No .git/HEAD found above '.base_path().' — cannot record deploy commit.');

            return self::SUCCESS;
        }

        DB::table('config')->upsert(
            [['key' => 'deploy.laravel_commit', 'value' => $commit]],
            ['key'],
            ['value'],
        );

        $this->line("Recorded Laravel deploy commit: {$commit}");

        return self::SUCCESS;
    }

    /**
     * Walk up from $start looking for a directory that contains a readable
     * .git/HEAD, and return its commit SHA. Handles the monorepo-subdir layout
     * (iznik-batch is a subdir; .git is at the monorepo root above it).
     */
    private function findGitCommit(string $start): ?string
    {
        $dir = $start;
        for ($i = 0; $i < 10; $i++) {
            if ($commit = $this->readGitHead($dir)) {
                return $commit;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break; // reached filesystem root
            }
            $dir = $parent;
        }

        return null;
    }

    /**
     * Read the HEAD commit SHA from a git working directory.
     * (Same logic as deploy:refresh; duplicated to keep this command standalone.)
     */
    private function readGitHead(string $dir): ?string
    {
        $headFile = $dir.'/.git/HEAD';
        if (! file_exists($headFile)) {
            return null;
        }

        $head = trim(file_get_contents($headFile));

        // Detached HEAD: the file contains the SHA directly.
        if (preg_match('/^[0-9a-f]{40}$/i', $head)) {
            return $head;
        }

        // Symbolic ref: e.g. "ref: refs/heads/master"
        if (str_starts_with($head, 'ref: ')) {
            $refPath = $dir.'/.git/'.substr($head, 5);
            if (file_exists($refPath)) {
                return trim(file_get_contents($refPath));
            }
        }

        return null;
    }
}
