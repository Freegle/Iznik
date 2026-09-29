<?php

namespace App\Console\Commands\Eee;

use App\Services\EeeVisionService;
use Illuminate\Console\Command;

/**
 * Classify listings from their text alone into the UK WEEE collection streams.
 *
 * For posts with no photo and for history, such as the file supplied to Material Focus.
 * Input is a tab-separated file of key, title and optional description, with no header.
 * Output is tab-separated key, is_eee (1, 0 or empty for unknown), stream number and
 * stream name. The output is appended to and keys already in it are skipped, so an
 * interrupted run resumes where it stopped.
 *
 * By default each call is live. --google-batch sends everything to Gemini's batch service
 * instead, which takes up to a day and costs half as much. The job name is kept in
 * <output>.job, so re-running after an interruption waits on the same job rather than
 * paying for a second one.
 *
 *   php artisan eee:classify-texts in.tsv out.tsv --batch=50 --concurrency=8
 *   php artisan eee:classify-texts in.tsv out.tsv --google-batch
 */
class EeeClassifyTextsCommand extends Command
{
    protected $signature = 'eee:classify-texts
                            {input  : TSV of key, title, description}
                            {output : TSV to append results to}
                            {--batch=50       : Listings per API request}
                            {--concurrency=8  : Live requests in flight at once}
                            {--passes=3       : Live attempts for listings the model leaves out}
                            {--google-batch   : Use Gemini\'s batch service: half price, up to a day}
                            {--poll=60        : Seconds between batch job status checks}';

    protected $description = 'Classify listings into UK WEEE streams from their text alone';

    protected const DONE_STATES = ['JOB_STATE_SUCCEEDED', 'BATCH_STATE_SUCCEEDED'];
    protected const FAILED_STATES = [
        'JOB_STATE_FAILED', 'JOB_STATE_CANCELLED', 'JOB_STATE_EXPIRED',
        'BATCH_STATE_FAILED', 'BATCH_STATE_CANCELLED', 'BATCH_STATE_EXPIRED',
    ];

    public function __construct(protected EeeVisionService $vision)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $input  = $this->argument('input');
        $output = $this->argument('output');

        if (!is_readable($input)) {
            $this->error("Cannot read {$input}");
            return self::FAILURE;
        }

        $todo = $this->todo($input, $output);
        $this->info(count($todo) . ' to do.');

        $batches = array_chunk($todo, max(1, (int) $this->option('batch')), true);

        return $this->option('google-batch')
            ? $this->runGoogleBatch($batches, $output)
            : $this->runLive($todo, $output);
    }

    /** Listings in the input whose key is not yet in the output. */
    protected function todo(string $input, string $output): array
    {
        $done = [];
        if (is_readable($output)) {
            foreach (file($output, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $done[explode("\t", $line, 2)[0]] = true;
            }
        }

        $todo = [];
        foreach (file($input, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $cols = explode("\t", $line);
            if (!isset($done[$cols[0]])) {
                $todo[$cols[0]] = ['subject' => $cols[1] ?? '', 'description' => $cols[2] ?? ''];
            }
        }

        return $todo;
    }

    protected function runLive(array $todo, string $output): int
    {
        $batchSize   = max(1, (int) $this->option('batch'));
        $concurrency = max(1, (int) $this->option('concurrency'));
        $fh          = fopen($output, 'a');
        $tokensIn    = $tokensOut = 0;

        for ($pass = 1; $pass <= (int) $this->option('passes') && $todo; $pass++) {
            $batches = array_chunk($todo, $batchSize, true);
            $bar     = $this->output->createProgressBar(count($batches));

            foreach (array_chunk($batches, $concurrency) as $wave) {
                foreach ($this->vision->classifyTextBatches($wave) as $key => $r) {
                    $this->writeResult($fh, $key, $r);
                    unset($todo[$key]);
                }
                $tokensIn  += $this->vision->lastBatchUsage['input_tokens'];
                $tokensOut += $this->vision->lastBatchUsage['output_tokens'];
                $bar->advance(count($wave));
            }

            $bar->finish();
            $this->newLine();
            $this->info("Pass {$pass}: " . count($todo) . ' left unanswered.');
        }

        fclose($fh);
        $this->info("Tokens: {$tokensIn} in, {$tokensOut} out.");

        return $todo ? self::FAILURE : self::SUCCESS;
    }

    protected function runGoogleBatch(array $batches, string $output): int
    {
        $jobFile   = "{$output}.job";
        $keysFile  = "{$output}.batches.json";
        $requests  = "{$output}.requests.jsonl";
        $resultsFn = "{$output}.results.jsonl";

        if (is_readable($jobFile)) {
            // Resume: the job was paid for already, so wait on it rather than submitting again.
            $job     = trim(file_get_contents($jobFile));
            $batches = json_decode(file_get_contents($keysFile), true);
            $this->info("Resuming {$job}.");
        } else {
            if (!$batches) {
                return self::SUCCESS;
            }

            $fh = fopen($requests, 'w');
            foreach ($batches as $i => $batch) {
                fwrite($fh, json_encode([
                    'key'     => "b{$i}",
                    'request' => $this->vision->buildTextBatchRequest($batch),
                ], JSON_UNESCAPED_UNICODE) . "\n");
            }
            fclose($fh);
            file_put_contents($keysFile, json_encode($batches, JSON_UNESCAPED_UNICODE));

            $job = $this->vision->submitGeminiBatchJob($requests, 'eee-classify-texts-' . basename($output));
            file_put_contents($jobFile, $job);
            $this->info('Submitted ' . count($batches) . " requests as {$job}.");
        }

        do {
            $status = $this->vision->geminiBatchJob($job);
            $this->line(now()->toDateTimeString() . " {$status['state']} " . json_encode($status['stats']));
            if (in_array($status['state'], self::FAILED_STATES, true)) {
                $this->error("Batch job ended {$status['state']}; delete {$jobFile} to submit again.");
                return self::FAILURE;
            }
            if (!in_array($status['state'], self::DONE_STATES, true)) {
                sleep(max(5, (int) $this->option('poll')));
            }
        } while (!in_array($status['state'], self::DONE_STATES, true));

        $this->vision->downloadGeminiBatchResults($status['results_file'], $resultsFn);

        $fh       = fopen($output, 'a');
        $answered = 0;
        $tokensIn = $tokensOut = 0;
        foreach (new \SplFileObject($resultsFn) as $line) {
            $row = json_decode($line, true);
            if (!is_array($row)) {
                continue;
            }
            $batch = $batches[(int) substr($row['key'] ?? '', 1)] ?? null;
            if ($batch === null) {
                continue;
            }
            $tokensIn  += $row['response']['usageMetadata']['promptTokenCount'] ?? 0;
            $tokensOut += $row['response']['usageMetadata']['candidatesTokenCount'] ?? 0;
            $text       = $row['response']['candidates'][0]['content']['parts'][0]['text'] ?? null;
            foreach ($this->vision->parseTextBatchAnswer($text, $batch) as $key => $r) {
                $this->writeResult($fh, $key, $r);
                $answered++;
            }
        }
        fclose($fh);
        unlink($jobFile);

        $asked = array_sum(array_map('count', $batches));
        $this->info("Answered {$answered} of {$asked}. Tokens: {$tokensIn} in, {$tokensOut} out.");
        if ($answered < $asked) {
            $this->warn('Re-run without --google-batch to fill the gaps live.');
        }

        return $answered < $asked ? self::FAILURE : self::SUCCESS;
    }

    protected function writeResult($fh, string $key, array $r): void
    {
        $stream = $r['weee_stream'];
        fwrite($fh, implode("\t", [
            $key,
            $r['is_eee'] === null ? '' : ($r['is_eee'] ? '1' : '0'),
            $stream ?? '',
            $stream ? EeeVisionService::WEEE_STREAMS[$stream] : '',
        ]) . "\n");
    }
}
