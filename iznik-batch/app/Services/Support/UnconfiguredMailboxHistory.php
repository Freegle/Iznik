<?php

namespace App\Services\Support;

/**
 * The mailbox history the batch sends: always unavailable, with a plain reason. The AI helper reads
 * the mailboxes itself when it has their passwords (claude-agent-sdk/mail-history.js) and puts its own
 * result in place of this one.
 */
class UnconfiguredMailboxHistory implements MailboxHistory
{
    public const REASON = 'mailbox access is not set up yet';

    public function forMember(?int $memberId): array
    {
        return ['available' => false, 'reason' => self::REASON, 'entries' => []];
    }
}
