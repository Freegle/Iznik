<?php

namespace App\Console\Commands;

use App\Services\LokiService;
use App\Services\Mail\Incoming\IncomingArchiveService;
use App\Services\Mail\Incoming\IncomingMailService;
use App\Services\Mail\Incoming\MailParserService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Process incoming email from Postfix.
 *
 * This command is the entry point for Postfix to deliver incoming emails.
 * It reads the raw email from stdin (or --stdin-content for testing),
 * parses it, and routes it using IncomingMailService.
 *
 * Usage from Postfix master.cf:
 *   freegle unix - n n - - pipe
 *     flags=F user=www-data argv=/usr/bin/php /path/artisan mail:incoming ${sender} ${recipient}
 *
 * Exit codes (per sysexits.h):
 *   0 (EX_OK) - Message processed successfully
 *   75 (EX_TEMPFAIL) - Temporary failure, Postfix should retry
 */
class IncomingMailCommand extends Command
{
    protected $signature = 'mail:incoming
        {sender : The envelope sender (MAIL FROM)}
        {recipient : The envelope recipient (RCPT TO)}
        {--stdin-content= : Raw email content (for testing, instead of stdin)}';

    protected $description = 'Process incoming email from Postfix';

    /**
     * Postfix exit codes.
     */
    private const EX_OK = 0;

    private const EX_TEMPFAIL = 75;

    public function handle(MailParserService $parser, IncomingMailService $service, IncomingArchiveService $archiveService): int
    {
        $sender = $this->argument('sender');
        $recipient = $this->argument('recipient');

        // Get raw email content from stdin or test option
        $rawEmail = $this->option('stdin-content');
        if ($rawEmail === null) {
            $rawEmail = file_get_contents('php://stdin');
        }

        // Archive raw email to disk before processing (safety net for reprocessing).
        $archivePath = $archiveService->archive($rawEmail, $sender, $recipient);

        try {
            // Parse the email
            $parsed = $parser->parse($rawEmail, $sender, $recipient);

            // Log verbose info if requested
            if ($this->output->isVerbose()) {
                $this->line("From: {$parsed->fromAddress}");
                $this->line("Subject: {$parsed->subject}");
                $this->line("Envelope-From: {$sender}");
                $this->line("Envelope-To: {$recipient}");
            }

            // Route the email
            $result = $service->route($parsed);

            // Output the result
            $this->line($result->value);

            // Record outcome in archive file.
            if ($archivePath) {
                $archiveService->recordOutcome($archivePath, $result->value);
            }

            // Log for monitoring (Laravel log)
            Log::channel('incoming_mail')->info('Mail processed', [
                'source' => 'batch',
                'job' => 'mail:incoming',
                'envelope_from' => $sender,
                'envelope_to' => $recipient,
                'from_address' => $parsed->fromAddress,
                'subject' => $parsed->subject,
                'message_id' => $parsed->messageId,
                'routing_result' => $result->value,
            ]);

            // Log to Loki for ModTools incoming email dashboard
            app(LokiService::class)->logIncomingEmail(
                $sender,
                $recipient,
                $parsed->fromAddress,
                $parsed->subject ?? '',
                $parsed->messageId ?? '',
                $result->value,
                $service->getLastRoutingContext(),
            );

            return $result->getExitCode();

        } catch (\Throwable $e) {
            // Log the error
            Log::channel('incoming_mail')->error('Mail processing failed', [
                'source' => 'batch',
                'job' => 'mail:incoming',
                'envelope_from' => $sender,
                'envelope_to' => $recipient,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // For unrecoverable errors, still return success to prevent endless retries
            // Only return TEMPFAIL for transient database/connection errors
            if ($this->isTransientError($e)) {
                $this->error('Temporary failure: '.$e->getMessage());

                return self::EX_TEMPFAIL;
            }

            // Log and drop the message
            $this->error('Processing failed: '.$e->getMessage());

            return self::EX_OK;
        }
    }

    /**
     * Check if an exception represents a transient error.
     *
     * Transient errors are temporary issues that Postfix should retry.
     */
    private function isTransientError(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        // Database connection errors
        if (str_contains($message, 'connection refused') ||
            str_contains($message, 'too many connections') ||
            str_contains($message, 'connection timed out') ||
            str_contains($message, 'deadlock')) {
            return true;
        }

        // MySQL-specific transient errors
        if ($e instanceof \PDOException) {
            $errorCode = $e->getCode();
            // 2002: Can't connect to local MySQL server
            // 2006: MySQL server has gone away
            // 1040: Too many connections
            // 1205: Lock wait timeout
            // 1213: Deadlock found
            if (in_array($errorCode, ['2002', '2006', '1040', '1205', '1213'])) {
                return true;
            }
        }

        return false;
    }
}
