<?php

namespace App\Console\Commands\Paf;

use App\Console\Concerns\PreventsOverlapping;
use App\Models\PafImport;
use App\Services\PafService;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Works through a request from the PAF update on SysAdmin Housekeeping to download and apply a Royal
 * Mail address data (PAF) release, writing progress to its paf_imports row as it goes.
 *
 * The paf-extractor sidecar downloads the release from AFD, unlocks it with the key, and
 * hands back only the lines that are new or changed since the last release applied. This
 * command applies those lines to paf_addresses in batches. A run that is stopped part way
 * (a deploy, a restart) carries on from where it got to the next minute.
 *
 * docs/ops/runbooks/paf-address-data.md.
 */
#[AsCommand(name: 'paf:import-run')]
class ImportRunCommand extends Command
{
    use PreventsOverlapping;

    protected $signature = 'paf:import-run
                            {--poll=10 : Seconds between checks on the extractor}';

    protected $description = 'Download and apply a PAF release requested from SysAdmin';

    /** The housekeeping task a finished import marks done. */
    public const HOUSEKEEPING_TASK = 'paf-database';

    /** Seconds between progress writes while applying. */
    private const PROGRESS_EVERY = 5;

    private string $extractor;

    public function handle(PafService $service): int
    {
        if (!$this->acquireLock()) {
            return self::SUCCESS;
        }

        $import = PafImport::nextUnfinished();
        if (!$import) {
            return self::SUCCESS;
        }

        $this->extractor = rtrim((string) config('freegle.paf.extractor_url'), '/');

        try {
            $this->work($import, $service);
        } catch (\Throwable $e) {
            Log::error('paf:import-run failed', ['import' => $import->id, 'error' => $e->getMessage()]);
            $this->markFailed($import, $e->getMessage());

            return self::FAILURE;
        } finally {
            $this->releaseLock();
        }

        return self::SUCCESS;
    }

    private function work(PafImport $import, PafService $service): void
    {
        if ($import->status === PafImport::STATUS_QUEUED && !$this->start($import)) {
            return;
        }

        if (in_array($import->status, PafImport::EXTRACTOR_STEPS, true) && !$this->waitForExtractor($import)) {
            return;
        }

        $file = $this->deltaPath($import);
        if ($import->status === PafImport::STATUS_FETCHING || !file_exists($file)) {
            $this->fetch($import, $file);
        }

        $this->apply($import, $service, $file);
    }

    private function start(PafImport $import): bool
    {
        if ($import->edition === null && $import->release_published === null) {
            // Which release "the latest" is today, for the tab to show. Not finding out does not
            // stop the import.
            try {
                $latest = Http::timeout(90)->get("{$this->extractor}/latest");
                if ($latest->successful() && $latest->json('published')) {
                    $import->update(['release_published' => $latest->json('published')]);
                }
            } catch (\Throwable $e) {
                Log::warning('paf:import-run could not read the latest release', ['error' => $e->getMessage()]);
            }
        }

        $response = Http::timeout(30)->post("{$this->extractor}/jobs", [
            'id' => $import->id,
            'key' => $import->unlock_key,
            'edition' => $import->edition,
        ]);

        if ($response->status() === 409) {
            // The extractor is busy with an earlier job. Try again next run.
            $import->update(['detail' => 'Waiting for the extractor to finish another job']);

            return false;
        }

        if (!$response->successful()) {
            throw new \RuntimeException('The extractor refused the job: ' . ($response->json('error') ?? $response->body()));
        }

        $import->update([
            'status' => PafImport::STATUS_DOWNLOADING,
            'progress' => 0,
            'detail' => 'Started',
            'started_at' => now(),
        ]);

        return true;
    }

    /**
     * Copy the extractor's progress into the row until it has the changed lines ready.
     * Returns false if the job failed.
     */
    private function waitForExtractor(PafImport $import): bool
    {
        $poll = max(0, (int) $this->option('poll'));

        while (true) {
            $state = Http::timeout(30)->get("{$this->extractor}/jobs/current")->throw()->json();

            if (($state['id'] ?? null) !== $import->id) {
                $this->markFailed($import, 'The extractor no longer has this job; it may have been restarted. Start again.');

                return false;
            }

            $phase = $state['phase'] ?? '';

            if ($phase === 'failed') {
                $this->markFailed($import, $state['error'] ?? 'The extractor failed');

                return false;
            }

            if ($phase === 'ready') {
                $import->update([
                    'status' => PafImport::STATUS_FETCHING,
                    'unlock_key' => null,
                    'lines_in_release' => $state['totalLines'] ?? null,
                    'lines_to_apply' => $state['deltaLines'] ?? null,
                    'progress' => 0,
                    'detail' => $state['detail'] ?? null,
                ]);

                return true;
            }

            if (in_array($phase, PafImport::EXTRACTOR_STEPS, true)) {
                $update = [
                    'status' => $phase,
                    'progress' => (int) ($state['progress'] ?? 0),
                    'detail' => mb_substr((string) ($state['detail'] ?? ''), 0, 255),
                ];
                if ($phase === PafImport::STATUS_COMPARING) {
                    // Unlocked; the key is not needed again.
                    $update['unlock_key'] = null;
                }
                $import->update($update);
            }

            sleep($poll);
        }
    }

    private function fetch(PafImport $import, string $file): void
    {
        $import->update(['status' => PafImport::STATUS_FETCHING, 'detail' => 'Fetching the changed lines']);

        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0755, true);
        }

        Http::timeout(3600)
            ->withOptions(['sink' => $file])
            ->get("{$this->extractor}/jobs/{$import->id}/delta")
            ->throw();

        @unlink($this->skippedPath($import));
        $import->update([
            'status' => PafImport::STATUS_APPLYING,
            'lines_applied' => 0,
            'inserted' => 0,
            'updated' => 0,
            'skipped' => 0,
            'progress' => 0,
        ]);
    }

    private function apply(PafImport $import, PafService $service, string $file): void
    {
        $batchSize = max(1, (int) config('freegle.paf.batch_size'));
        $pauseUs = max(0, (int) config('freegle.paf.pause_ms')) * 1000;
        $total = (int) $import->lines_to_apply;

        $in = fopen($file, 'r');
        $skippedOut = fopen($this->skippedPath($import), 'a');

        // Carry on from where an earlier run stopped. The batch in hand when it stopped is
        // applied again, which changes nothing the second time.
        for ($i = 0; $i < $import->lines_applied && fgets($in) !== false; $i++);

        $counts = $import->only(['lines_applied', 'inserted', 'updated', 'skipped']);
        $lastWrite = 0;

        while (true) {
            $lines = [];
            while (count($lines) < $batchSize && ($line = fgets($in)) !== false) {
                $lines[] = $line;
            }
            if (!$lines) {
                break;
            }

            $result = $service->applyLines($lines);
            foreach ($result['skipped'] as $line) {
                fwrite($skippedOut, $line . "\n");
            }
            fflush($skippedOut);

            $counts['lines_applied'] += count($lines);
            $counts['inserted'] += $result['inserted'];
            $counts['updated'] += $result['updated'];
            $counts['skipped'] += count($result['skipped']);

            if (time() - $lastWrite >= self::PROGRESS_EVERY) {
                $this->writeApplyProgress($import, $counts, $total);
                $lastWrite = time();
            }

            if ($pauseUs) {
                usleep($pauseUs);
            }
        }

        fclose($in);
        fclose($skippedOut);
        $this->writeApplyProgress($import, $counts, $total);

        // Hand back the lines that could not be applied, so the extractor offers them again
        // with the next release, and make this release the one the next is compared with.
        // Streamed: if locations is missing many postcodes this file is large.
        Http::timeout(3600)
            ->withBody(Utils::streamFor(fopen($this->skippedPath($import), 'r')), 'text/csv')
            ->post("{$this->extractor}/jobs/{$import->id}/commit")
            ->throw();

        @unlink($file);
        @unlink($this->skippedPath($import));

        $import->update([
            'status' => PafImport::STATUS_DONE,
            'progress' => 100,
            'detail' => "Applied {$counts['lines_applied']} lines: {$counts['inserted']} new addresses, "
                . "{$counts['updated']} changed, {$counts['skipped']} waiting for their postcode",
            'finished_at' => now(),
        ]);

        // The quarterly PAF update on the SysAdmin Housekeeping tab is this; a finished import does it.
        DB::table('housekeeper_tasks')->where('task_key', self::HOUSEKEEPING_TASK)->update([
            'last_run_at' => now(),
            'last_status' => 'success',
            'last_summary' => "Applied by PAF import {$import->id}: {$counts['inserted']} new, {$counts['updated']} changed",
        ]);
    }

    private function writeApplyProgress(PafImport $import, array $counts, int $total): void
    {
        $import->update($counts + [
            'progress' => $total ? min(99, intdiv($counts['lines_applied'] * 100, $total)) : 99,
            'detail' => "Applied {$counts['lines_applied']} of {$total} lines",
        ]);
    }

    private function markFailed(PafImport $import, string $error): void
    {
        $import->update([
            'status' => PafImport::STATUS_FAILED,
            'unlock_key' => null,
            'error' => mb_substr($error, 0, 2000),
            'finished_at' => now(),
        ]);
    }

    private function deltaPath(PafImport $import): string
    {
        return storage_path("app/paf/delta-{$import->id}.csv");
    }

    private function skippedPath(PafImport $import): string
    {
        return storage_path("app/paf/skipped-{$import->id}.csv");
    }
}
