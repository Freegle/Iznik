<?php

namespace App\Monitoring;

/**
 * The result of evaluating a single scheduled-task outcome check.
 *
 * Status is one of:
 *  - OK       — the job's work demonstrably happened.
 *  - BREACH   — the expected side effect is missing/stale; escalate to Sentry.
 *  - SKIPPED  — the check did not apply right now (precondition unmet, or
 *               outside its active window). Never a failure.
 */
final class OutcomeResult
{
    public const OK = 'ok';
    public const BREACH = 'breach';
    public const SKIPPED = 'skipped';

    public function __construct(
        public readonly string $slug,
        public readonly string $status,
        public readonly string $message,
        public readonly string $severity = 'error',
    ) {
    }

    public static function ok(string $slug, string $message): self
    {
        return new self($slug, self::OK, $message);
    }

    public static function breach(string $slug, string $message, string $severity = 'error'): self
    {
        return new self($slug, self::BREACH, $message, $severity);
    }

    public static function skipped(string $slug, string $message): self
    {
        return new self($slug, self::SKIPPED, $message);
    }

    public function isOk(): bool
    {
        return $this->status === self::OK;
    }

    public function isBreach(): bool
    {
        return $this->status === self::BREACH;
    }

    public function isSkipped(): bool
    {
        return $this->status === self::SKIPPED;
    }
}
