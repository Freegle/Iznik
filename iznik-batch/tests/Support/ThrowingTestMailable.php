<?php

namespace Tests\Support;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A plain (non-RetryableMailable) Mailable whose build() throws a
 * non-permanent error. Used to prove spool() still rethrows for mailables that
 * have NOT opted into durable retry (backward compatibility).
 */
class ThrowingTestMailable extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Throwing test');
    }

    public function build(): static
    {
        throw new \RuntimeException('simulated render failure (non-retryable)');
    }
}
