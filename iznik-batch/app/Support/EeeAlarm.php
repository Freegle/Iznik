<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Escalation for "the EEE pipeline is dark" states.
 *
 * The classification pipeline fails SOFT by design: a rejected API key, a
 * retired model or an empty component index each surface as per-item
 * Log::warning lines and a run that "succeeds" having classified nothing.
 * Nothing routes Laravel logs to Sentry (LOG_STACK=daily), so the pipeline
 * stayed dark for weeks without anyone noticing (the gemini-2.0 retirement,
 * and again the empty index on first prod deploy).
 *
 * raise() writes a Log::error AND a Sentry event (the team actively watches
 * Sentry - same rationale as EmailHealthCommand). Deduped per process key so
 * a loop over hundreds of items produces one event per run, not hundreds.
 */
class EeeAlarm
{
    /** @var array<string, true> */
    private static array $sent = [];

    /** Test seam: receives the message instead of \Sentry\captureMessage when set. */
    public static ?\Closure $captureWith = null;

    public static function raise(string $key, string $message, array $context = []): void
    {
        Log::error('[EEE] ' . $message, $context);

        if (isset(self::$sent[$key])) {
            return;
        }
        self::$sent[$key] = true;

        if (self::$captureWith !== null) {
            (self::$captureWith)('[EEE] ' . $message);

            return;
        }

        // function_exists guard matches the codebase pattern in TNSyncCommand /
        // EmailHealthCommand (Sentry's helpers aren't loaded in every environment).
        if (function_exists('\Sentry\captureMessage')) {
            \Sentry\captureMessage('[EEE] ' . $message);
        }
    }

    /** Test seam: forget which keys have fired this process. */
    public static function reset(): void
    {
        self::$sent = [];
        self::$captureWith = null;
    }
}
