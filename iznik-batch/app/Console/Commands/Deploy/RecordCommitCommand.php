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
        $commit = $this->readGitHead(base_path());

        if (! $commit) {
            $this->warn('No .git/HEAD found — cannot record deploy commit.');

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
