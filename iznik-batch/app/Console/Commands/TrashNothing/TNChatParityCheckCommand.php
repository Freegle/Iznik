<?php

namespace App\Console\Commands\TrashNothing;

use App\Services\LokiService;
use App\Services\TrashNothing\Ingestion\ChatIngestPlan;
use App\Services\TrashNothing\Ingestion\ChatMessageIngestionService;
use App\Services\TrashNothing\Ingestion\TnChatMessage;
use App\Services\TrashNothing\Sync\ChatMessageSyncer;
use App\Services\TrashNothing\Verify\ChatEmailInventory;
use App\Services\TrashNothing\Verify\ChatParityChecker;
use App\Services\TrashNothing\Verify\ChatParityReport;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Compares what the email path made of TN members' chat mail with what the API path would make
 * of TN's chat-messages feed for the same messages. Read-only. See
 * plans/2026-11-12-tn-api-chat-ingestion.md, 5.3 and 5.6.
 *
 * It reads production chats, so it runs only on batch-prod or on yesterday's copy, and prints
 * ids, outcomes and field names, never what anyone wrote.
 */
#[AsCommand(name: 'tn:chat-parity-check')]
class TNChatParityCheckCommand extends Command
{
    /** How far behind real time the default window ends, so both paths and processing have settled. */
    private const LAG_MINUTES = 60;

    private const WINDOW_MINUTES = 60;

    /** The feed is read this much wider than the emails: TN's date and our arrival differ a little. */
    private const FEED_SLOP_MINUTES = 10;

    /** How many of each failure are listed; the counts are always whole. */
    private const LISTED = 50;

    protected $signature = 'tn:chat-parity-check
                            {--date-min= : Start of the window (ISO-8601, UTC). Default: two hours before the end of the email side}
                            {--date-max= : End of the window (ISO-8601, UTC). Default: an hour before the end of the email side}
                            {--email-side=archive : Where the email side comes from: "archive" (batch-prod) or "db" (yesterday: the raw mail kept beside each chat message)}
                            {--feed=tn : Where the API side comes from: "tn" (TN\'s chat-messages feed) or "email" (the record TN should send, built from each email, to test our side before TN serves the feed)}
                            {--apply : Write the API path\'s messages for real first, and compare written rows. Yesterday only: refused unless FREEGLE_TN_PARITY_ALLOW_WRITES is set}
                            {--json : Print the whole report as JSON}';

    protected $description = 'Compare TN chat messages the email path wrote with what the API path would write from TN\'s feed.';

    public function handle(ChatEmailInventory $inventory, LokiService $loki): int
    {
        $side = (string) $this->option('email-side');
        if (!in_array($side, ['archive', 'db'], true)) {
            $this->error('--email-side must be "archive" or "db".');

            return Command::FAILURE;
        }

        if ($this->option('apply') && !config('freegle.trashnothing.parity_allow_writes')) {
            $this->error('--apply writes chat messages, so it runs only where FREEGLE_TN_PARITY_ALLOW_WRITES is set: a yesterday clone.');

            return Command::FAILURE;
        }

        $feed = (string) $this->option('feed');
        if (!in_array($feed, ['tn', 'email'], true)) {
            $this->error('--feed must be "tn" or "email".');

            return Command::FAILURE;
        }

        [$from, $to] = $this->window($side);
        $this->info("TN chat parity, {$side} side, {$from->toIso8601ZuluString()} to {$to->toIso8601ZuluString()}");

        $synthesize = $feed === 'email';
        $emails = $side === 'db' ? $inventory->fromDatabase($from, $to, $synthesize) : $inventory->fromArchive($from, $to, $synthesize);
        if ($side === 'archive' && !$emails['stats']['archive_present']) {
            $this->error('The incoming archive is missing, so there is nothing to compare against.');

            return Command::FAILURE;
        }

        if ($synthesize) {
            $records = array_values(array_filter(array_column($emails['entries'], 'record')));
            $this->line('Feed: built from the email, ' . count($records) . ' records. This tests our side only.');
        } else {
            $syncer = new ChatMessageSyncer(
                dryRun: true,
                localTesting: false,
                apiKey: (string) config('freegle.trashnothing.api_key', ''),
                apiBaseUrl: (string) config('freegle.trashnothing.api_base_url', ''),
                loki: $loki,
            );
            $records = $syncer->records(
                $from->subMinutes(self::FEED_SLOP_MINUTES)->toIso8601ZuluString(),
                $to->addMinutes(self::FEED_SLOP_MINUTES)->toIso8601ZuluString(),
            );
            if ($syncer->fetchFailed()) {
                $this->error('TN\'s chat-messages feed could not be read in full, so the comparison would be wrong.');

                return Command::FAILURE;
            }
        }

        if ($this->option('apply')) {
            $this->applyAll($records, $emails['entries']);
        }

        $report = (new ChatParityChecker(new ChatMessageIngestionService(true)))
            ->check($emails['entries'], $records, [$from->toIso8601ZuluString(), $to->toIso8601ZuluString()]);

        $this->print($report, $emails['stats'], count($records));

        // Nothing compared is not a pass: the window has no TN chat mail in it, most often because
        // it is outside the two days a snapshot keeps.
        if ($report->total('email') === 0) {
            $this->error('No TN chat mail in this window, so nothing was compared.' . $this->keptRange($inventory, $side));

            return Command::FAILURE;
        }

        $loki->logBatchJob('tn:chat-parity-check', $report->failed() ? 'failed' : 'completed', $report->summary() + [
            'email_side' => $side,
            'feed' => $feed,
            'date_min' => $from->toIso8601ZuluString(),
            'date_max' => $to->toIso8601ZuluString(),
        ]);

        return $report->failed() ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Write every record as the API path would, each as of its email copy, so the comparison is
     * of written rows (plan 5.6). The checker then finds each as already written.
     *
     * @param  list<TnChatMessage>  $records
     */
    private function applyAll(array $records, array $emails): void
    {
        $ingestion = new ChatMessageIngestionService;
        $written = 0;
        foreach ($records as $record) {
            $asOf = $emails[$record->logId()]['chat_message']['id'] ?? null;
            if ($ingestion->apply($ingestion->plan($record, $asOf))->result === ChatIngestPlan::RESULT_WRITTEN) {
                $written++;
            }
        }
        $this->line("Applied: {$written} written of " . count($records) . ' records');
    }

    /** Where the database's chat mail starts and ends, to choose a window that has some. */
    private function keptRange(ChatEmailInventory $inventory, string $side): string
    {
        if ($side !== 'db') {
            return '';
        }
        $range = $inventory->databaseChatMailRange();

        return $range === null
            ? ' The database keeps no chat mail at all.'
            : " The database keeps chat mail from {$range[0]} to {$range[1]} (UTC).";
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private function window(string $side): array
    {
        // On yesterday NOW() is today, not the snapshot: anchor on the last mail the snapshot has.
        $end = $side === 'db'
            ? CarbonImmutable::parse((string) (DB::table('messages')->max('arrival') ?? 'now'), 'UTC')
            : CarbonImmutable::now('UTC');

        $to = $this->option('date-max')
            ? CarbonImmutable::parse((string) $this->option('date-max'), 'UTC')
            : $end->subMinutes(self::LAG_MINUTES);
        $from = $this->option('date-min')
            ? CarbonImmutable::parse((string) $this->option('date-min'), 'UTC')
            : $to->subMinutes(self::WINDOW_MINUTES);

        return [$from, $to];
    }

    private function print(ChatParityReport $report, array $stats, int $records): void
    {
        if ($this->option('json')) {
            $this->line(json_encode($report->toArray() + ['stats' => $stats, 'records' => $records], JSON_PRETTY_PRINT));

            return;
        }

        $this->line('Email side: ' . json_encode($stats));
        $this->line("Feed records read: {$records}");

        $kinds = array_unique(array_merge(array_keys($report->counts('email')), array_keys($report->counts('extra'))));
        sort($kinds);
        $this->table(['kind', 'emails', 'agree', 'extras'], array_map(fn ($k) => [
            $k, $report->counts('email')[$k] ?? 0, $report->counts('agree')[$k] ?? 0, $report->counts('extra')[$k] ?? 0,
        ], $kinds));

        $sections = [
            'coverage' => ['Layer 1, emails with no feed record', true],
            'outcome' => ['Layer 3, one path wrote and the other did not', true],
            'content' => ['Layer 4, both wrote but differ', true],
            'extras' => ['Layer 2, records that should never be in the feed', true],
            'explained' => ['Explained: the TN id rule (3.1), or a change to the email path\'s row since it was written', false],
            'email_extra_text' => ['The email had more text than the record', false],
            'unverifiable' => ['Could not be compared', false],
        ];
        foreach ($sections as $key => [$title, $isFailure]) {
            $items = $isFailure ? $report->failures($key) : $report->notes($key);
            if ($items === [] && !$isFailure) {
                continue;
            }
            $this->line('');
            $this->line(($isFailure ? ($items === [] ? '[ok]   ' : '[FAIL] ') : '[note] ') . $title . ': ' . count($items));
            foreach (array_slice($items, 0, self::LISTED) as $item) {
                $this->line('  ' . json_encode($item));
            }
        }

        $extras = $report->notes('extras');
        if ($extras !== []) {
            $this->line('');
            $this->line('[note] Records with no email (row 8 replies the email path loses, and others): ' . count($extras));
        }
    }
}
