<?php

namespace App\Mail\Contracts;

/**
 * A Mailable that can be durably retried after a render/build failure or a
 * transient infrastructure error.
 *
 * Background: emails are rendered inline on the cron hot path and then handed
 * to {@see \App\Services\EmailSpoolerService::spool()}, which writes the fully
 * built message to the file spool for the mail-spooler daemon to send. The
 * render happens *before* anything durable is written, so a render bug (e.g. a
 * template referencing an undefined key during a deploy window) throws there —
 * the message never reaches the spool and the recipient is silently dropped.
 * This is exactly the failure that lost ~1,100 immediate digests once.
 *
 * When a mailable implements this interface, spool() turns that drop into a
 * durable retry instead: on a non-permanent build/render failure it captures
 * the descriptor below, dispatches a {@see \App\Jobs\SpoolMail} queue job, and
 * lets Laravel's queue retry it (backoff + retryUntil, then failed_jobs). The
 * retry rebuilds a *fresh* mailable from current DB state via
 * {@see rebuildFromDescriptor()} and re-renders it, so a fix deployed within
 * the retry window drains the backlog automatically.
 *
 * The descriptor MUST hold only IDs (scalars / arrays of scalars), never built
 * objects — storing IDs is what makes the retry render against current data
 * and keeps the queue payload small and stable.
 */
interface RetryableMailable
{
    /**
     * Minimal, JSON-serialisable identifiers needed to rebuild this mailable.
     *
     * Return only scalars or arrays of scalars (IDs) — never Eloquent models
     * or other object graphs.
     *
     * @return array<string, mixed>
     */
    public function mailDescriptor(): array;

    /**
     * Rebuild a fresh instance from a descriptor, re-fetching from the DB.
     *
     * Return null when the email is no longer applicable (e.g. the message or
     * the recipient has since been deleted, or the recipient now has no usable
     * address). A null result cancels the retry — it is treated as "nothing to
     * send", not as a failure, so it never counts towards dead-lettering.
     *
     * @param array<string, mixed> $descriptor
     */
    public static function rebuildFromDescriptor(array $descriptor): ?self;
}
