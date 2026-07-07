<?php

namespace App\Http\Controllers;

use App\Services\LokiService;
use App\Services\Mail\Incoming\IncomingArchiveService;
use App\Services\Mail\Incoming\IncomingMailService;
use App\Services\Mail\Incoming\MailParserService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Controller for receiving incoming mail from Postfix via HTTP.
 *
 * This endpoint is called by the Postfix pipe handler when mail arrives.
 * The raw email is sent in the request body with envelope info in headers.
 */
class IncomingMailController extends Controller
{
    public function __construct(
        private readonly MailParserService $parser,
        private readonly IncomingMailService $mailService,
        private readonly IncomingArchiveService $archiveService
    ) {}

    /**
     * Receive and process an incoming email.
     */
    public function receive(Request $request): Response
    {
        $sender = $request->header('X-Envelope-From', '');
        $recipient = $request->header('X-Envelope-To', '');
        $rawEmail = $request->getContent();

        Log::info('Incoming mail received via HTTP', [
            'sender' => $sender,
            'recipient' => $recipient,
            'size' => strlen($rawEmail),
        ]);

        // Validate we have content
        if (empty($rawEmail)) {
            Log::warning('Empty email content received');

            return response('Empty content', 400);
        }

        // Archive raw email to disk before processing (safety net for reprocessing).
        $archivePath = $this->archiveService->archive($rawEmail, $sender, $recipient);

        try {
            // Parse the email
            $parsed = $this->parser->parse($rawEmail, $sender, $recipient);

            // Route the email
            $result = $this->mailService->route($parsed);

            Log::info('Incoming mail processed', [
                'sender' => $sender,
                'recipient' => $recipient,
                'result' => $result->value,
            ]);

            // Record outcome in archive file.
            if ($archivePath) {
                $this->archiveService->recordOutcome($archivePath, $result->value);
            }

            // Log to Loki for ModTools incoming email dashboard
            app(LokiService::class)->logIncomingEmail(
                $sender,
                $recipient,
                $parsed->fromAddress,
                $parsed->subject ?? '',
                $parsed->messageId ?? '',
                $result->value,
                $this->mailService->getLastRoutingContext(),
            );

            return response('OK', 200);

        } catch (\PDOException $e) {
            // Database errors - return 503 for Postfix to retry
            Log::error('Database error processing incoming mail', [
                'sender' => $sender,
                'recipient' => $recipient,
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);

            if ($this->isTransientDatabaseError($e)) {
                return response('Database temporarily unavailable', 503);
            }

            // Permanent database error - accept to avoid infinite retries
            return response('OK', 200);

        } catch (\Throwable $e) {
            // Other errors - log but accept the message
            Log::error('Error processing incoming mail', [
                'sender' => $sender,
                'recipient' => $recipient,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Return 200 to prevent infinite retries for permanent errors
            return response('OK', 200);
        }
    }

    /**
     * Check if a PDO exception is transient (worth retrying).
     */
    private function isTransientDatabaseError(\PDOException $e): bool
    {
        $transientCodes = [
            2002, // Connection refused
            2003, // Can't connect to MySQL server
            2006, // MySQL server has gone away
            1040, // Too many connections
            1205, // Lock wait timeout
            1213, // Deadlock found
        ];

        return in_array((int) $e->getCode(), $transientCodes, true);
    }
}
