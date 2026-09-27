<?php

namespace App\Console\Commands\Message;

use App\Models\MessageAutomod;
use App\Models\MessageGroup;
use App\Services\Automod\AutomodFactsService;
use App\Services\Automod\AutomodService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backtests the automod chart against posts a moderator already decided, so a chart or backend
 * change can be checked before it goes anywhere near a live group (plans/active/automod-flowchart.md).
 *
 * Two modes:
 *
 * - --export=path.jsonl: writes {id, subject, body, type, facts, rules, outcome} for the sampled
 *   posts and does not call the automod service at all. The service's own evaluate.js (automod/)
 *   loads this file and compares chart versions/backends offline, as many times as wanted,
 *   without re-querying the database each time. --backend is ignored in this mode.
 * - No --export: calls the live automod service (POST /review) for each sampled post with the
 *   requested --backend(s), without writing a messages_automod row for it (these posts are no
 *   longer Pending; recording a verdict for them would be noise, not a moderation decision -
 *   see AutomodService::review()'s $persist argument), and prints how often the chart's verdict
 *   agrees with what the moderator actually did.
 */
class AutomodEvaluateCommand extends Command
{
    protected $signature = 'automod:evaluate
        {--days=30 : How many days back to sample decided posts from}
        {--limit=500 : Maximum number of posts to sample}
        {--group= : Restrict the sample to a single group id}
        {--backend=claude : claude, nli, or both - ignored when --export is given}
        {--export= : Write the sample as JSONL to this path instead of calling the service}';

    protected $description = 'Backtest the automod chart against posts a moderator already decided';

    private const VALID_BACKENDS = ['claude', 'nli'];

    public function handle(AutomodFactsService $facts, AutomodService $service): int
    {
        $exportPath = $this->option('export');

        $backends = self::VALID_BACKENDS;
        if (!$exportPath) {
            $backendOption = (string) $this->option('backend');
            $backends = $backendOption === 'both' ? self::VALID_BACKENDS : [$backendOption];

            foreach ($backends as $backend) {
                if (!in_array($backend, self::VALID_BACKENDS, true)) {
                    $this->error("Unknown --backend '{$backend}'; use claude, nli, or both.");

                    return Command::FAILURE;
                }
            }
        }

        $sample = $this->sample(
            (int) $this->option('days'),
            (int) $this->option('limit'),
            $this->option('group') !== null ? (int) $this->option('group') : null
        );

        if ($sample->isEmpty()) {
            $this->info('No moderator-decided, content-checked posts found in that window.');

            return Command::SUCCESS;
        }

        if ($exportPath) {
            return $this->export($sample, $facts, (string) $exportPath);
        }

        return $this->liveCompare($sample, $service, $backends);
    }

    /**
     * Only posts a moderator actually decided are useful ground truth. An Approved row only
     * counts when approvedby is set: an auto-approved row (approvedby NULL, see
     * AutoApproveCleanServiceTest::assertApproved()) reflects the existing clean-path
     * auto-approve, not independent human judgement, so counting it would mark the chart
     * against its own (or a predecessor feature's) decision. Every Rejected row is a human
     * decision regardless, since the chart never auto-rejects (AutomodMode's docblock).
     *
     * @return \Illuminate\Support\Collection<int, object{msgid: int, groupid: int, collection: string}>
     */
    private function sample(int $days, int $limit, ?int $groupid)
    {
        return DB::table('messages_groups as mg')
            ->join('messages as m', 'm.id', '=', 'mg.msgid')
            ->whereNotNull('mg.contentcheck_checked_at')
            ->where('mg.deleted', 0)
            ->where('mg.arrival', '>=', now()->subDays($days))
            ->where(function ($query) {
                $query->where(function ($approved) {
                    $approved->where('mg.collection', MessageGroup::COLLECTION_APPROVED)
                        ->whereNotNull('mg.approvedby');
                })->orWhere('mg.collection', MessageGroup::COLLECTION_REJECTED);
            })
            ->when($groupid !== null, fn ($query) => $query->where('mg.groupid', $groupid))
            ->orderByDesc('mg.arrival')
            ->limit($limit)
            ->get(['mg.msgid', 'mg.groupid', 'mg.collection']);
    }

    private function outcomeFor(string $collection): string
    {
        return $collection === MessageGroup::COLLECTION_APPROVED
            ? MessageAutomod::VERDICT_APPROVE
            : MessageAutomod::VERDICT_HOLD;
    }

    private function export($sample, AutomodFactsService $facts, string $path): int
    {
        $handle = fopen($path, 'w');
        if ($handle === false) {
            $this->error("Could not open {$path} for writing.");

            return Command::FAILURE;
        }

        $written = 0;
        foreach ($sample as $row) {
            $message = DB::table('messages')
                ->where('id', $row->msgid)
                ->first(['subject', 'textbody', 'type']);

            if (!$message) {
                continue;
            }

            fwrite($handle, json_encode([
                'id' => (int) $row->msgid,
                'subject' => $message->subject ?? '',
                'body' => $message->textbody ?? '',
                'type' => $message->type ?? '',
                'facts' => $facts->facts((int) $row->msgid, (int) $row->groupid),
                'rules' => $facts->rules((int) $row->groupid),
                'outcome' => $this->outcomeFor($row->collection),
            ]) . "\n");
            $written++;
        }

        fclose($handle);

        $this->info("Wrote {$written} posts to {$path}");

        return Command::SUCCESS;
    }

    /**
     * @param string[] $backends
     */
    private function liveCompare($sample, AutomodService $service, array $backends): int
    {
        $stats = [];
        foreach ($backends as $backend) {
            $stats[$backend] = ['agree' => 0, 'disagree' => 0, 'unavailable' => 0];
        }

        foreach ($sample as $row) {
            $msgid = (int) $row->msgid;
            $groupid = (int) $row->groupid;
            $outcome = $this->outcomeFor($row->collection);

            foreach ($backends as $backend) {
                // shadow: whichever mode a live run would use, the verdict is what we compare,
                // and persist=false means this never writes a messages_automod row for a post
                // that is no longer Pending.
                $result = $service->review($msgid, $groupid, 'shadow', $backend, false);

                if ($result === null) {
                    $stats[$backend]['unavailable']++;

                    continue;
                }

                if (($result['verdict'] ?? null) === $outcome) {
                    $stats[$backend]['agree']++;
                } else {
                    $stats[$backend]['disagree']++;
                }
            }
        }

        foreach ($stats as $backend => $counts) {
            $total = $counts['agree'] + $counts['disagree'];
            $rate = $total > 0 ? round(100 * $counts['agree'] / $total, 1) : 0.0;

            $this->info(
                "{$backend}: agree {$counts['agree']}, disagree {$counts['disagree']}, " .
                "unavailable {$counts['unavailable']} ({$rate}% agreement)"
            );
        }

        return Command::SUCCESS;
    }
}
