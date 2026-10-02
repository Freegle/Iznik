<?php

namespace App\Console\Commands\Mail;

use App\Services\Mail\RelayLogIngestService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Read the outbound relay's maillog into logs_emails.
 *
 * This is the replacement for V1's scripts/cron/eximlogs.php, which ran from
 * root's crontab on the relay every ten minutes. It is the only thing that
 * writes logs_emails, and three things read that table: the moderator-only
 * "recent emails to this user" endpoint, the GDPR user dump, and the AI
 * support helper - which treats a missing row as proof we never sent the
 * message. So this is how we answer "did you actually email me?", and a wrong
 * row here becomes a wrong answer to a member.
 */
class IngestRelayLogsCommand extends Command
{
    protected $signature = 'mail:relay-logs:ingest
        {--dry-run : Parse and report without writing any rows}';

    protected $description = 'Read the outbound relay maillog into logs_emails, so we can tell a member what we sent them';

    public function handle(RelayLogIngestService $ingest): int
    {
        if (!$ingest->enabled()) {
            $this->info('Relay log ingest is disabled (no relay host configured).');

            return self::SUCCESS;
        }

        $stats = $ingest->ingest((bool) $this->option('dry-run'));

        if (($stats['failed'] ?? false) === true) {
            // Deliberately not a failure exit: the relay being briefly
            // unreachable is ordinary, the offset is not advanced, and the
            // next run re-reads the same bytes. Failing the command would
            // page someone for a blip that fixes itself.
            $this->warn('Could not read the relay log this run; the next run will re-read the same bytes.');
            Log::warning('Relay log ingest could not read the relay');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s %d row(s) from %d log line(s); %d loopback hop(s) ignored.',
            $this->option('dry-run') ? 'Would record' : 'Recorded',
            $stats['written'],
            $stats['lines'],
            $stats['handovers']
        ));

        return self::SUCCESS;
    }
}
