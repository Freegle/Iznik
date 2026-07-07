<?php

namespace App\Services\Mail\Incoming;

/**
 * Routing outcomes for incoming email processing.
 *
 * These match the outcomes in iznik-server's MailRouter.php.
 */
enum RoutingResult: string
{
    // Message approved and posted to group
    case APPROVED = 'Approved';

    // Message held for moderator approval
    case PENDING = 'Pending';

    // Message marked as spam for review
    case INCOMING_SPAM = 'IncomingSpam';

    // Message sent to group volunteers via chat
    case TO_VOLUNTEERS = 'ToVolunteers';

    // Message routed to user (chat reply)
    case TO_USER = 'ToUser';

    // System message handled (unsubscribe, digest off, etc.)
    case TO_SYSTEM = 'ToSystem';

    // Read receipt or calendar response
    case RECEIPT = 'Receipt';

    // Calendar event (tryst) response
    case TRYST = 'Tryst';

    // Message dropped/discarded
    case DROPPED = 'Dropped';

    // Routing failed
    case FAILURE = 'Failure';

    // Error during processing (e.g., unparseable bounce)
    case ERROR = 'Error';

    /**
     * Check if this result indicates the message was saved.
     */
    public function isSaved(): bool
    {
        return in_array($this, [
            self::APPROVED,
            self::PENDING,
            self::INCOMING_SPAM,
        ]);
    }

    /**
     * Check if this result indicates the message was discarded.
     */
    public function isDiscarded(): bool
    {
        return in_array($this, [
            self::DROPPED,
            self::FAILURE,
        ]);
    }

    /**
     * Get the Postfix exit code for this result.
     *
     * EX_OK (0) = success, message handled
     * EX_TEMPFAIL (75) = temporary failure, retry later
     */
    public function getExitCode(): int
    {
        return match ($this) {
            self::FAILURE => 75,  // EX_TEMPFAIL
            default => 0,         // EX_OK
        };
    }
}
