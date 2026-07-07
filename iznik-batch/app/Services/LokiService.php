<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Loki logging service for Laravel batch jobs.
 *
 * Writes logs to JSON files that Alloy ships to Grafana Loki.
 */
class LokiService
{
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
     */
    public function logIncomingEmail(
        string $envelopeFrom,
        string $envelopeTo,
        ?string $fromAddress,
        string $subject,
        string $messageId,
        string $routingOutcome,
        array $context = [],
    ): void {
        if (! $this->enabled) {
            return;
        }

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

        $entry = [
            'timestamp' => now()->toIso8601String(),
            'labels' => [
                'app' => 'freegle',
                'source' => 'incoming_mail',
                'type' => 'routed',
                'subtype' => $routingOutcome,
            ],
            'message' => $message,
        ];

        $this->writeLog('incoming_mail.log', $entry);
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
}
