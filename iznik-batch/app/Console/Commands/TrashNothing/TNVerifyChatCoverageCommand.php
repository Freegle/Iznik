<?php

namespace App\Console\Commands\TrashNothing;

use App\Models\ChatMessageTn;
use App\Services\LokiService;
use App\Services\TrashNothing\Ingestion\ChatIngestPlan;
use App\Services\TrashNothing\Ingestion\DeliberateSkips;
use App\Services\TrashNothing\Sync\ChatMessageSyncer;
use App\Services\TrashNothing\Verify\ChatEmailInventory;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * After the chat cutover: every TN member's chat email the incoming archive kept, of a kind the
 * cutover moved, must have been written from TN's chat-messages feed (a chat_messages_tn row for
 * its X-Trash-Nothing-Log-Id) or decided on purpose (a note in DeliberateSkips). The email path no
 * longer routes that mail, so a miss is a message nobody received. See
 * plans/2026-11-12-tn-api-chat-ingestion.md 5.4.
 *
 * A miss is looked up at TN by its id. With --auto-ingest, one TN still has is written through the
 * same plan() and apply() as the sync, behind three rails: a cap on how many in one run (above it,
 * none are written and Sentry hears), an age limit, and an alert for one still missing after.
 */
#[AsCommand(name: 'tn:verify-chat-coverage')]
class TNVerifyChatCoverageCommand extends Command
{
    protected $signature = 'tn:verify-chat-coverage
                            {--date-min= : Start of the window (ISO-8601, UTC)}
                            {--date-max= : End of the window (ISO-8601, UTC)}
                            {--auto-ingest : Write a miss TN still has, behind the rails (also freegle.trashnothing.verify_chat_coverage.auto_ingest)}
                            {--force : Run although the chat cutover flag is off, when "covered" proves nothing}';

    protected $description = 'Check that every TN chat email of a moved kind was written from TN\'s chat-messages feed.';

    public function handle(ChatEmailInventory $inventory, LokiService $loki): int
    {
        if (!config('freegle.trashnothing.ingest_chat_via_api') && !$this->option('force')) {
            $this->warn('The chat cutover flag is off, so the email path still writes these messages. Use --force to run anyway.');

            return Command::SUCCESS;
        }

        $cfg = (array) config('freegle.trashnothing.verify_chat_coverage', []);
        [$from, $to] = $this->window($cfg);
        $this->info("TN chat coverage, {$from->toIso8601ZuluString()} to {$to->toIso8601ZuluString()}");

        $emails = $inventory->fromArchive($from, $to);
        if (!$emails['stats']['archive_present']) {
            $this->error('The incoming archive is missing, so coverage cannot be checked.');

            return Command::FAILURE;
        }

        $kinds = ChatMessageSyncer::cutOverKinds();
        $counts = ['emails' => 0, 'covered' => 0, 'decided' => 0, 'not_moved' => 0, 'missing' => 0, 'backfilled' => 0];
        $misses = [];

        foreach ($emails['entries'] as $logId => $entry) {
            $counts['emails']++;

            if (!in_array($entry['kind'], $kinds, true)) {
                // Not moved: the email path still writes it.
                $counts['not_moved']++;
                continue;
            }

            if (ChatMessageTn::where('tnmessageid', $logId)->exists()) {
                $counts['covered']++;
                continue;
            }

            $reason = DeliberateSkips::reasonFor(ChatMessageSyncer::SKIP_KEY_PREFIX . $logId);
            if ($reason !== null) {
                $counts['decided']++;
                $this->line('  decided: ' . json_encode(['log_id' => $logId, 'kind' => $entry['kind'], 'reason' => $reason]));
                continue;
            }

            $misses[$logId] = $entry;
        }

        $syncer = new ChatMessageSyncer(
            dryRun: false,
            localTesting: false,
            apiKey: (string) config('freegle.trashnothing.api_key', ''),
            apiBaseUrl: (string) config('freegle.trashnothing.api_base_url', ''),
            loki: $loki,
            write: true,
        );
        $classified = [];
        foreach ($misses as $logId => $entry) {
            [$status, $record] = $syncer->fetchRecord($logId);
            $classified[$logId] = ['entry' => $entry, 'status' => $status, 'record' => $record];
        }

        if ($this->option('auto-ingest') || (bool) ($cfg['auto_ingest'] ?? false)) {
            $this->backfill($classified, $counts, $cfg, $syncer);
        }

        $counts['missing'] = count($classified);
        foreach ($classified as $logId => $c) {
            $this->line('  missing: ' . json_encode([
                'log_id' => $logId,
                'kind' => $c['entry']['kind'],
                'timestamp' => $c['entry']['timestamp'],
                'tn' => $c['status'],
            ]));
        }

        $this->line(json_encode($counts));
        $loki->logBatchJob('tn:verify-chat-coverage', $classified === [] ? 'completed' : 'failed', $counts + [
            'date_min' => $from->toIso8601ZuluString(),
            'date_max' => $to->toIso8601ZuluString(),
        ]);

        if ($classified !== []) {
            $this->error("{$counts['missing']} TN chat messages arrived by email and were never written.");

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Write the misses TN still has, behind the rails: none when there are more than the cap, none
     * older than the age limit, and Sentry hears of one still not written after.
     *
     * @param  array<string, array{entry: array, status: string, record: ?\App\Services\TrashNothing\Ingestion\TnChatMessage}>  $classified
     */
    private function backfill(array &$classified, array &$counts, array $cfg, ChatMessageSyncer $syncer): void
    {
        $backfill = array_filter($classified, fn ($c) => $c['status'] === 'found');
        $max = (int) ($cfg['auto_ingest_max'] ?? 20);

        if (count($backfill) > $max) {
            $this->report("TN chat coverage found " . count($backfill) . " messages TN has that were never written, more than the {$max} it will backfill in one run: nothing written, the sync needs looking at");

            return;
        }

        $maxAge = (int) ($cfg['max_age_hours'] ?? 24);
        foreach ($backfill as $logId => $c) {
            $record = $c['record'];
            if ($record->date !== null && strtotime($record->date) < now()->subHours($maxAge)->getTimestamp()) {
                $classified[$logId]['status'] = 'too-old';
                continue;
            }

            $plan = $syncer->ingestOne($record);
            $classified[$logId]['status'] = 'backfill-' . $plan->result;
            if ($plan->result === ChatIngestPlan::RESULT_WRITTEN || $plan->result === ChatIngestPlan::RESULT_DUPLICATE) {
                $counts['backfilled']++;
                unset($classified[$logId]);
            } elseif (in_array($plan->result, [ChatIngestPlan::RESULT_SKIPPED, ChatIngestPlan::RESULT_DROPPED], true)) {
                // Decided now, as the sync would have: noted, and listed as decided next run.
                $counts['decided']++;
                $this->line('  decided: ' . json_encode(['log_id' => $logId, 'kind' => $record->kind, 'reason' => $plan->reason()]));
                unset($classified[$logId]);
            } else {
                $this->report("TN chat coverage backfilled {$logId} and it is still not written ({$plan->result})");
            }
        }
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private function window(array $cfg): array
    {
        $to = $this->option('date-max')
            ? CarbonImmutable::parse((string) $this->option('date-max'), 'UTC')
            : CarbonImmutable::now('UTC')->subMinutes((int) ($cfg['lag_minutes'] ?? 60));
        $from = $this->option('date-min')
            ? CarbonImmutable::parse((string) $this->option('date-min'), 'UTC')
            : $to->subMinutes((int) ($cfg['window_minutes'] ?? 60) + (int) ($cfg['overlap_minutes'] ?? 15));

        return [$from, $to];
    }

    private function report(string $message): void
    {
        $this->error($message);
        Log::error($message);
        if (function_exists('\Sentry\captureMessage')) {
            \Sentry\captureMessage($message);
        }
    }
}
