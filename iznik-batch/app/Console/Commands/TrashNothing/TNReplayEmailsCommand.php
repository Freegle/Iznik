<?php

namespace App\Console\Commands\TrashNothing;

use App\Services\LokiService;
use App\Services\Mail\Incoming\IncomingMailService;
use App\Services\Mail\Incoming\MailParserService;
use App\Services\TrashNothing\Sync\EmailReplaySyncer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Replay TN "post emails sent" records for a date range through the legacy
 * email-ingestion pipeline (MailParserService + IncomingMailService).
 *
 * Ad hoc parity-testing tool, not a scheduled job: it always writes for
 * real (no --dry-run), so it's meant to be run once against a disposable
 * test database, then compared against a PostSyncer --dry-run run over the
 * same --from/--to window (see TNSyncCommand) by diffing their
 * TN-SYNC-TRACE log lines.
 */
class TNReplayEmailsCommand extends Command
{
    protected $signature = 'tn:replay-emails
                            {--from= : Sync start timestamp (ISO-8601), required}
                            {--to= : Sync end timestamp (ISO-8601), required}
                            {--local-testing : Load API responses from local fixture files instead of hitting the live TN API}';

    protected $description = 'Replay TN post-email records through the legacy email-ingestion path, for parity testing against the API-based PostSyncer.';

    public function handle(LokiService $loki, MailParserService $parser, IncomingMailService $mailService): int
    {
        $from = (string) $this->option('from');
        $to   = (string) $this->option('to');

        if ($from === '' || $to === '') {
            $this->error('--from and --to are both required (ISO-8601 timestamps).');
            return Command::FAILURE;
        }

        $localTesting = (bool) $this->option('local-testing');
        $apiKey       = (string) config('freegle.trashnothing.api_key', '');
        $apiBaseUrl   = (string) config('freegle.trashnothing.api_base_url', '');

        Log::info("TN-SYNC-TRACE [EMAILS-START] from={$from} to={$to}");

        $syncer = new EmailReplaySyncer($localTesting, $apiKey, $apiBaseUrl, $loki, $parser, $mailService);
        [$count, $maxDate] = $syncer->sync($from, $to);

        $this->info("Replayed {$count} TN post emails through the legacy path.");
        Log::info("TN-SYNC-TRACE [EMAILS-END] total={$count} max_date=" . ($maxDate ?? 'null'));

        return Command::SUCCESS;
    }
}
