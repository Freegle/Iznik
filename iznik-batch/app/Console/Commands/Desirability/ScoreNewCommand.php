<?php

namespace App\Console\Commands\Desirability;

use App\Services\Desirability\DesirabilityService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Incremental desirability scoring - scores OFFERs approved since the last run.
 *
 * Designed for the scheduler (hourly). No-ops quietly while the artifact table
 * is empty, so shipping the code does nothing in production until an operator
 * imports an artifact with desirability:import-artifact.
 *
 *   php artisan desirability:score-new
 *   php artisan desirability:score-new --limit=2000 --dry-run
 */
class ScoreNewCommand extends Command
{
    protected $signature = 'desirability:score-new
                            {--limit=2000  : Max new messages to score per run}
                            {--since=      : Override the since datetime (YYYY-MM-DD HH:MM:SS)}
                            {--dry-run     : Show pending count without scoring}';

    protected $description = 'Score new approved OFFERs for item desirability (for scheduler)';

    /**
     * Same clock as eee:classify-new: when a moderator approved the post, falling
     * back to arrival for auto-approved posts. See EeeClassifyNewCommand for why
     * arrival alone is wrong in both directions.
     */
    protected const APPROVAL_CLOCK = 'COALESCE(messages_groups.approvedat, messages_groups.arrival)';

    public function __construct(protected DesirabilityService $desirability)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');

        if (! $this->desirability->artifactReady()) {
            // Not an alarm: absence of the artifact is the shipped default, not a
            // fault. The high-water mark defaults from now-24h once it exists.
            $this->info('No desirability artifact imported for ' . $this->desirability->modelVersion() . ' - nothing to do.');

            return Command::SUCCESS;
        }

        $since = $this->option('since') ?: $this->getHighWaterMark();
        $this->info("desirability:score-new | since: {$since} | limit: {$limit}");

        $clock = self::APPROVAL_CLOCK;
        $modelVersion = $this->desirability->modelVersion();

        // Approved, undeleted OFFERs only, resumable at the boundary via >= plus
        // NOT EXISTS (same shape as eee:classify-new; ties re-scan for free).
        $rows = DB::table('messages')
            ->select('messages.id', 'messages.subject')
            ->join('messages_groups', 'messages_groups.msgid', '=', 'messages.id')
            ->where('messages.type', 'Offer')
            ->whereNull('messages.deleted')
            ->where('messages_groups.collection', 'Approved')
            ->where('messages_groups.deleted', 0)
            // keep-raw: COALESCE over two columns; the builder has no expression form.
            ->whereRaw("$clock >= ?", [$since])
            ->whereNotExists(function ($q) use ($modelVersion) {
                $q->select(DB::raw(1))
                    ->from('messages_desirability')
                    ->whereColumn('messages_desirability.msgid', 'messages.id')
                    ->where('messages_desirability.model_version', $modelVersion);
            })
            ->groupBy('messages.id', 'messages.subject')
            // keep-raw: aggregate over the COALESCE clock; not expressible in the builder.
            ->orderByRaw("MIN($clock)")
            ->limit($limit)
            ->get();

        $this->info(count($rows) . ' new messages to score.');

        if ($dryRun || $rows->isEmpty()) {
            return Command::SUCCESS;
        }

        $scored = 0;
        $failures = 0;
        $bySource = ['exact' => 0, 'knn' => 0, 'default' => 0];

        foreach ($rows as $row) {
            try {
                $result = $this->desirability->scoreSubject($row->subject);
                DB::table('messages_desirability')->upsert([
                    'msgid' => $row->id,
                    'score' => $result['score'],
                    'bucket' => $result['bucket'],
                    'source' => $result['source'],
                    'matched_canonical' => $result['matched_canonical'],
                    'model_version' => $modelVersion,
                ], ['msgid', 'model_version'], ['score', 'bucket', 'source', 'matched_canonical']);
                $scored++;
                $bySource[$result['source']]++;
            } catch (\Throwable $e) {
                // One failing message must not stall the rest; it stores no row, so
                // the NOT EXISTS retries it next run.
                Log::error('desirability:score-new failed for message', ['msgid' => $row->id, 'error' => $e->getMessage()]);
                $failures++;
            }
        }

        $this->info("Done: {$scored} scored (exact {$bySource['exact']}, knn {$bySource['knn']}, default {$bySource['default']})");

        if ($failures > 0) {
            $this->warn("{$failures} messages failed and will be retried next run - see the log.");
        }

        if ($scored === 0 && $failures > 0) {
            // Dead-man switch: every attempt failed while the run "succeeded"
            // item by item (e.g. artifact table dropped mid-run, DB fault).
            \App\Support\EeeAlarm::raise('desirability-all-failed',
                "desirability:score-new: every score failed this run ({$failures} attempts) - per-item errors in the log");

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Newest approval clock already scored under this model version, read from
     * production MySQL (same reasoning as EeeClassifyNewCommand::getHighWaterMark).
     */
    protected function getHighWaterMark(): string
    {
        $clock = self::APPROVAL_CLOCK;
        $mark = DB::table('messages_desirability')
            ->join('messages_groups', 'messages_groups.msgid', '=', 'messages_desirability.msgid')
            ->where('messages_desirability.model_version', $this->desirability->modelVersion())
            // keep-raw: MAX over a COALESCE clock.
            ->selectRaw("MAX($clock) AS mark")
            ->value('mark');

        return $mark ?: now()->subDay()->toDateTimeString();
    }
}
