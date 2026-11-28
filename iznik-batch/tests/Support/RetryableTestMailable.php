<?php

namespace Tests\Support;

use App\Mail\Contracts\RetryableMailable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Controllable RetryableMailable test double.
 *
 * Static switches let a test force build() to throw (simulating a render bug
 * or transient render-server failure) and control what rebuildFromDescriptor()
 * returns. Reset the switches in setUp() so tests don't leak state.
 */
class RetryableTestMailable extends Mailable implements RetryableMailable
{
    /** Make build() throw, simulating a render/build failure. */
    public static bool $throwOnBuild = false;

    /** Make envelope() throw, as a mail whose envelope reads a missing relation does. */
    public static bool $throwOnEnvelope = false;

    /** How many times build() has run since reset(). */
    public static int $builds = 0;

    /** The message build() throws when $throwOnBuild is true. */
    public static string $throwMessage = 'simulated render failure';

    /** One of: 'self' (rebuild ok), 'null' (no longer applicable). */
    public static string $rebuildResult = 'self';

    /** Captures the descriptor passed to the last rebuildFromDescriptor() call. */
    public static array $lastRebuildDescriptor = [];

    public function __construct(
        public array $descriptorData = ['id' => 1],
        public string $recipient = 'fixture@example.com',
    ) {
    }

    public static function reset(): void
    {
        static::$throwOnBuild = false;
        static::$throwOnEnvelope = false;
        static::$builds = 0;
        static::$throwMessage = 'simulated render failure';
        static::$rebuildResult = 'self';
        static::$lastRebuildDescriptor = [];
    }

    public function envelope(): Envelope
    {
        if (static::$throwOnEnvelope) {
            throw new \RuntimeException("simulated envelope failure");
        }

        // A From is required for the capturing transport to materialise the
        // Symfony Email (the manual capturing mailer doesn't apply Laravel's
        // global mail.from default the way the real mailers do).
        return new Envelope(
            from: new Address('noreply@example.com', 'Retryable Test'),
            subject: 'Retryable test',
        );
    }

    public function build(): static
    {
        static::$builds++;

        if (static::$throwOnBuild) {
            throw new \RuntimeException(static::$throwMessage);
        }

        return $this->html('<p>retryable test body</p>')->to($this->recipient);
    }

    public function mailDescriptor(): array
    {
        return $this->descriptorData;
    }

    public static function rebuildFromDescriptor(array $descriptor): ?self
    {
        static::$lastRebuildDescriptor = $descriptor;

        if (static::$rebuildResult === 'null') {
            return null;
        }

        return new static($descriptor, $descriptor['recipient'] ?? 'fixture@example.com');
    }
}
