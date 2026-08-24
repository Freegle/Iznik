<?php

namespace App\Console\Commands\Partnerships;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Renders the authority statistics spreadsheets that the ModTools Partnerships page asks for.
 *
 * Generating one council's report takes minutes, far longer than a web request can wait, so
 * the page writes a row into partnerships_statsjobs and this command - run every minute by
 * the scheduler - picks it up, runs the existing authority:stats command and stores the
 * resulting spreadsheets in the database for the page to download.
 *
 * Spreadsheets live in the DB rather than on disk because the Go API serves the download and
 * has no filesystem in common with this container.
 *
 *   php artisan partnerships:stats:run
 */
class StatsJobRunnerCommand extends Command
{
    protected $signature = 'partnerships:stats:run
                            {--limit=1 : How many queued jobs to run in one pass}';

    protected $description = 'Render queued authority statistics spreadsheets for the Partnerships page';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $jobs = DB::table('partnerships_statsjobs')
            ->where('status', 'Pending')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($jobs->isEmpty()) {
            $this->info('No queued statistics jobs.');

            return Command::SUCCESS;
        }

        foreach ($jobs as $job) {
            // Claim the job before doing any work. The conditional update means a second
            // runner that started at the same moment claims nothing and moves on.
            $claimed = DB::table('partnerships_statsjobs')
                ->where('id', $job->id)
                ->where('status', 'Pending')
                ->update(['status' => 'Running', 'started' => now()]);

            if (!$claimed) {
                continue;
            }

            $this->runJob($job);
        }

        return Command::SUCCESS;
    }

    private function runJob(object $job): void
    {
        $outputDir = storage_path('app/partnerships-stats/' . $job->id);
        $this->cleanDir($outputDir);

        if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
            $this->failJob($job, "Could not create output directory: {$outputDir}");

            return;
        }

        $ids = array_values(array_filter(array_map('intval', explode(',', (string) $job->authorityids))));
        if (empty($ids)) {
            $this->failJob($job, 'No authority IDs in the job.');

            return;
        }

        $stored = 0;
        $problems = [];

        foreach ($ids as $authorityId) {
            // One authority at a time so each spreadsheet can be filed against the council it
            // belongs to, and one bad authority does not lose the whole batch.
            try {
                $exit = Artisan::call('authority:stats', [
                    '--i' => (string) $authorityId,
                    '--q' => $job->quarter,
                    '--output' => $outputDir,
                ]);

                if ($exit !== 0) {
                    $problems[] = sprintf('Authority %d: %s', $authorityId, trim(Artisan::output()));

                    continue;
                }
            } catch (Throwable $e) {
                $problems[] = sprintf('Authority %d: %s', $authorityId, $e->getMessage());

                continue;
            }

            $stored += $this->storeFiles($job, $authorityId, $outputDir);
        }

        $this->cleanDir($outputDir);

        if ($stored === 0) {
            $this->failJob($job, $problems ? implode("\n", $problems) : 'No spreadsheets were produced.');

            return;
        }

        DB::table('partnerships_statsjobs')->where('id', $job->id)->update([
            'status' => 'Ready',
            // Partial success still counts as ready - the files that did render are useful,
            // and the errors are recorded alongside them.
            'error' => $problems ? implode("\n", $problems) : null,
            'completed' => now(),
        ]);

        $this->info(sprintf('Job %d: stored %d spreadsheet%s.', $job->id, $stored, $stored === 1 ? '' : 's'));
    }

    /**
     * Move every spreadsheet now in the output directory into the database against this
     * authority, and return how many were stored.
     */
    private function storeFiles(object $job, int $authorityId, string $outputDir): int
    {
        $stored = 0;

        foreach (glob($outputDir . '/*.xlsx') ?: [] as $path) {
            $content = @file_get_contents($path);
            @unlink($path);

            if ($content === false) {
                continue;
            }

            DB::table('partnerships_statsfiles')->insert([
                'jobid' => $job->id,
                'authorityid' => $authorityId,
                'filename' => basename($path),
                'size' => strlen($content),
                'content' => $content,
            ]);

            $stored++;
        }

        return $stored;
    }

    private function failJob(object $job, string $error): void
    {
        DB::table('partnerships_statsjobs')->where('id', $job->id)->update([
            'status' => 'Failed',
            'error' => $error,
            'completed' => now(),
        ]);

        $this->error(sprintf('Job %d failed: %s', $job->id, $error));
    }

    private function cleanDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
    }
}
