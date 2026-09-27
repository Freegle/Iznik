<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Loki logging service for Laravel batch jobs.
 *
 * Writes logs to JSON files that Alloy ships to Grafana Loki.
 */
class LokiService
{
    /**
     * Source label for routing entries produced by the incoming email path.
     */
    public const SOURCE_INCOMING_MAIL = 'incoming_mail';

    /**
     * Source label for routing entries produced by the TN API ingestion path.
     *
     * Deliberately distinct from SOURCE_INCOMING_MAIL while every other label
     * and every message field stays identical: a shared label would inject TN
     * API posts into ModTools' member-facing incoming-EMAIL dashboard (which
     * selects on source="incoming_mail"), and would make the two paths
     * indistinguishable in a query. Differing on exactly one label is what
     * makes a side-by-side comparison possible. See plans/
     * tn-api-post-ingestion.md section I.
     */
    public const SOURCE_TN_API = 'tn_api';

    private bool $enabled = false;

    private string $logPath;

    public function __construct()
    {
        $this->enabled = config('freegle.loki.enabled', false);
        $this->logPath = config('freegle.loki.log_path', '/var/log/freegle');
    }

    /**
     * Check if Loki logging is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Log a batch job event.
     *
     * @param  string  $jobName  Name of the batch job
     * @param  string  $event  Event type (started, completed, failed)
     * @param  array  $context  Additional context data
     */
    public function logBatchJob(string $jobName, string $event, array $context = []): void
    {
        if (! $this->enabled) {
            return;
        }

        $entry = [
            'timestamp' => now()->toIso8601String(),
            'labels' => [
                'app' => 'freegle',
                'source' => 'batch',
                'job_name' => $jobName,
                'event' => $event,
            ],
            'message' => array_merge([
                'job' => $jobName,
                'event' => $event,
            ], $context),
        ];

        $this->writeLog('batch.log', $entry);
    }

    /**
     * Log an email send event.
     *
     * @param  string  $type  Email type (digest, notification, etc.)
     * @param  string  $recipient  Recipient email address
     * @param  string  $subject  Email subject
     * @param  int|null  $userId  User ID
     * @param  int|null  $groupId  Group ID
     * @param  string|null  $traceId  Trace ID for log correlation
     * @param  array  $context  Additional context
     */
    public function logEmailSend(
        string $type,
        string $recipient,
        string $subject,
        ?int $userId = null,
        ?int $groupId = null,
        ?string $traceId = null,
        array $context = []
    ): void {
        if (! $this->enabled) {
            return;
        }

        $labels = [
            'app' => 'freegle',
            'source' => 'email',
            'email_type' => $type,
            'event' => 'sent',
        ];

        if ($userId) {
            $labels['user_id'] = (string) $userId;
        }

        if ($groupId) {
            $labels['groupid'] = (string) $groupId;
        }

        if ($traceId) {
            $labels['trace_id'] = $traceId;
        }

        // Add mailable class as label if available in context.
        if (! empty($context['mailable_class'])) {
            $labels['mailable_class'] = class_basename($context['mailable_class']);
        }

        $entry = [
            'timestamp' => now()->toIso8601String(),
            'labels' => $labels,
            'message' => array_merge([
                'recipient' => $recipient,
                'subject' => $subject,
                'user_id' => $userId,
                'group_id' => $groupId,
                'trace_id' => $traceId,
            ], $context),
        ];

        $this->writeLog('email.log', $entry);
    }

    /**
     * Log an incoming email routing event.
     *
     * @return array|null  The entry written, or null when Loki is disabled.
     *                     Returned (rather than void) so a caller can trace
     *                     what it emitted — tn:parity-check diffs the two
     *                     paths' entries against each other. Callers that
     *                     ignore the return value are unaffected.
     */
    public function logIncomingEmail(
        string $envelopeFrom,
        string $envelopeTo,
        ?string $fromAddress,
        string $subject,
        string $messageId,
        string $routingOutcome,
        array $context = [],
    ): ?array {
        if (! $this->enabled) {
            return null;
        }

        $entry = $this->buildRoutedEntry(
            self::SOURCE_INCOMING_MAIL,
            $routingOutcome,
            $this->buildRoutedMessage($envelopeFrom, $envelopeTo, $fromAddress, $subject, $messageId, $routingOutcome, $context),
        );

        $this->writeLog('incoming_mail.log', $entry);

        return $entry;
    }

    /**
     * Log a routing event for a post ingested via the TN API.
     *
     * Emits the same schema, into the same file, with the same label set and
     * the same subtype vocabulary as logIncomingEmail() — differing only on
     * the source label — so a single Loki query can compare the email and API
     * ingestion paths side by side.
     *
     * The email path is frozen (see plans/tn-api-post-ingestion.md), so the
     * shared construction in buildRoutedMessage()/buildRoutedEntry() is what
     * structurally guarantees the two stay identical: the schema cannot drift
     * on one side without drifting on both.
     */
    public function logIngestedPost(
        string $envelopeFrom,
        string $envelopeTo,
        ?string $fromAddress,
        string $subject,
        string $messageId,
        string $routingOutcome,
        array $context = [],
    ): ?array {
        if (! $this->enabled) {
            return null;
        }

        $entry = $this->buildRoutedEntry(
            self::SOURCE_TN_API,
            $routingOutcome,
            $this->buildRoutedMessage($envelopeFrom, $envelopeTo, $fromAddress, $subject, $messageId, $routingOutcome, $context),
        );

        $this->writeLog('incoming_mail.log', $entry);

        return $entry;
    }

    /**
     * Build the message body of a type=routed entry. Shared by every ingestion
     * path so their Loki output cannot diverge in shape — see logIngestedPost().
     *
     * NB: $context is merged LAST and therefore overwrites the fixed fields on
     * a key collision. This matters for 'message_id': callers that created a
     * message pass the numeric message id in the context, which replaces the
     * RFC822/synthesized message id passed as $messageId. That behaviour is
     * relied upon to correlate the two paths (the numeric id resolves to
     * messages.tnpostid), so it must be preserved, not "fixed".
     */
    private function buildRoutedMessage(
        string $envelopeFrom,
        string $envelopeTo,
        ?string $fromAddress,
        string $subject,
        string $messageId,
        string $routingOutcome,
        array $context = [],
    ): array {
        $message = [
            'envelope_from' => $envelopeFrom,
            'envelope_to' => $envelopeTo,
            // Bounce mail (MAILER-DAEMON etc) and other malformed messages
            // often have no parseable From header — store as empty string in
            // the Loki entry rather than crashing the whole incoming-mail
            // pipeline (which is what the strict string typehint used to do).
            'from_address' => $fromAddress ?? '',
            'subject' => $subject,
            'message_id' => $messageId,
            'routing_outcome' => $routingOutcome,
        ];

        // Merge routing context (group_name, user_id, chat_id, etc.)
        if (! empty($context)) {
            $message = array_merge($message, $context);
        }

        return $message;
    }

    /**
     * Wrap a routed message body in its labels/timestamp envelope.
     */
    private function buildRoutedEntry(string $source, string $routingOutcome, array $message): array
    {
        return [
            'timestamp' => now()->toIso8601String(),
            'labels' => [
                'app' => 'freegle',
                'source' => $source,
                'type' => 'routed',
                'subtype' => $routingOutcome,
            ],
            'message' => $message,
        ];
    }

    /**
     * Log an email bounce event.
     */
    public function logBounceEvent(
        string $email,
        int $userId,
        bool $isPermanent,
        string $reason,
    ): void {
        if (! $this->enabled) {
            return;
        }

        $entry = [
            'timestamp' => now()->toIso8601String(),
            'labels' => [
                'app' => 'freegle',
                'source' => 'bounce',
                'type' => 'bounced',
                'subtype' => $isPermanent ? 'permanent' : 'temporary',
            ],
            'message' => [
                'email' => $email,
                'user_id' => $userId,
                'is_permanent' => $isPermanent,
                'reason' => $reason,
            ],
        ];

        $this->writeLog('bounce.log', $entry);
    }

    /**
     * Log a general event from batch processing.
     *
     * @param  string  $type  Log type
     * @param  string  $subtype  Log subtype
     * @param  array  $context  Additional context
     */
    public function logEvent(string $type, string $subtype, array $context = []): void
    {
        if (! $this->enabled) {
            return;
        }

        $labels = [
            'app' => 'freegle',
            'source' => 'batch_event',
            'type' => $type,
            'subtype' => $subtype,
        ];

        if (! empty($context['groupid'])) {
            $labels['groupid'] = (string) $context['groupid'];
        }

        $entry = [
            'timestamp' => now()->toIso8601String(),
            'labels' => $labels,
            'message' => array_merge([
                'type' => $type,
                'subtype' => $subtype,
            ], $context),
        ];

        $this->writeLog('batch_event.log', $entry);
    }

    /**
     * Write a log entry to a JSON file.
     *
     * @param  string  $filename  Log filename
     * @param  array  $entry  Log entry
     */
    private function writeLog(string $filename, array $entry): void
    {
        $logFile = $this->logPath.'/'.$filename;

        // Ensure directory exists.
        $dir = dirname($logFile);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        // Write as JSON line.
        $line = json_encode($entry)."\n";
        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Hash email for privacy in logs.
     */
    private function hashEmail(string $email): string
    {
        $parts = explode('@', $email);
        if (count($parts) === 2) {
            return substr(md5($parts[0]), 0, 8).'@'.$parts[1];
        }

        return substr(md5($email), 0, 16);
    }

    /**
     * Read side: run a LogQL query over [$start, $end] and return the decoded
     * JSON body of every matching log line — or NULL if the query itself failed
     * (no URL, network error, non-200). NULL (vs an empty array) matters: a caller
     * must not treat a query error as "zero results", or a Loki hiccup would look
     * like "unused". An empty array means the query succeeded with no matches.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function queryRange(string $logql, Carbon $start, Carbon $end): ?array
    {
        $url = config('freegle.loki.query_url');
        if (empty($url)) {
            Log::warning('LokiService::queryRange: no freegle.loki.query_url configured');

            return null;
        }

        try {
            $resp = Http::timeout(30)->get(rtrim($url, '/').'/loki/api/v1/query_range', [
                'query' => $logql,
                'start' => $start->getTimestamp() * 1_000_000_000, // ns
                'end' => $end->getTimestamp() * 1_000_000_000,   // ns
                'limit' => 5000,
                'direction' => 'forward',
            ]);
        } catch (\Throwable $e) {
            Log::warning('LokiService::queryRange failed: '.$e->getMessage());

            return null;
        }

        if (! $resp->ok()) {
            Log::warning('LokiService::queryRange non-200: '.$resp->status().' '.$resp->body());

            return null;
        }

        $rows = [];
        foreach (($resp->json('data.result') ?? []) as $stream) {
            foreach (($stream['values'] ?? []) as $pair) {
                // $pair = [ "<ns timestamp>", "<log line json>" ]
                $decoded = json_decode($pair[1] ?? '', true);
                if (is_array($decoded)) {
                    $rows[] = $decoded;
                }
            }
        }

        return $rows;
    }
}
