<?php

namespace App\Console\Commands\TrashNothing;

use App\Services\LokiService;
use App\Services\Mail\Incoming\IncomingMailService;
use App\Services\Mail\Incoming\MailParserService;
use App\Services\TrashNothing\Sync\EmailReplaySyncer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Replay TN post emails through the legacy email-ingestion pipeline
 * (MailParserService + IncomingMailService).
 *
 * Emails are loaded from the TN post-log CSV, which is cached on local disk
 * and only downloaded when there is no cached copy (or --refresh-csv is
 * passed). In --local-testing mode, uses tests/fixtures/tn_sync/fd_post_log.csv
 * instead.
 *
 * Ad hoc parity-testing tool, not a scheduled job: it always writes for
 * real (no --dry-run), so it is meant to be run once against a disposable
 * test database, then compared against a PostSyncer --dry-run run over the
 * same date window (see TNSyncCommand) by diffing their TN-SYNC-TRACE log lines.
 */
class TNReplayEmailsCommand extends Command
{
    protected $signature = 'tn:replay-emails
                            {--local-testing : Load from local fixture CSV instead of downloading from TN}
                            {--refresh-csv : Re-download the TN post-log CSV even if a cached copy exists}
                            {--date-min= : Only replay emails on or after this UTC timestamp (ISO-8601, e.g. 2026-07-22T10:00:00Z)}';

    protected $description = 'Replay TN post-email records through the legacy email-ingestion path, for parity testing against the API-based PostSyncer.';

    public function handle(LokiService $loki, MailParserService $parser, IncomingMailService $mailService): int
    {
        $localTesting = (bool) $this->option('local-testing');
        $refreshCsv   = (bool) $this->option('refresh-csv');
        $dateMin      = $this->option('date-min') ?: null;

        Log::info('TN-SYNC-TRACE [EMAILS-START]');

        $syncer = new EmailReplaySyncer($localTesting, $loki, $parser, $mailService, null, $refreshCsv);
        [$count, $minDate, $maxDate] = $syncer->sync($dateMin);

        $this->info("Replayed {$count} TN post emails through the legacy path.");
        $this->info('Date range: from=' . ($minDate ?? 'null') . ' to=' . ($maxDate ?? 'null'));

        Log::info('TN-SYNC-TRACE [EMAILS-END] total=' . $count . ' min_date=' . ($minDate ?? 'null') . ' max_date=' . ($maxDate ?? 'null'));

        return Command::SUCCESS;
    }
}
