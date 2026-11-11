<?php

namespace App\Services\Support;

/**
 * The mailbox history until mailbox access is set up: always unavailable, with a plain reason.
 */
class UnconfiguredMailboxHistory implements MailboxHistory
{
    public const REASON = 'mailbox access is not set up yet';

    public function forMember(?int $memberId): array
    {
        return ['available' => false, 'reason' => self::REASON, 'entries' => []];
    }
}
