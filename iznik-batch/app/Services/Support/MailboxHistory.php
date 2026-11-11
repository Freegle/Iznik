<?php

namespace App\Services\Support;

/**
 * Past mail between a member and the support and info mailboxes, for the support case first pass.
 *
 * The result is shaped for the helper request:
 * ['available' => bool, 'reason' => string, 'entries' => [['date', 'mailbox', 'direction', 'subject', 'excerpt']]].
 * When available is false the reason says why in plain words and entries is empty; the brief then
 * says "not available", never "none".
 */
interface MailboxHistory
{
    /**
     * @return array{available: bool, reason: string, entries: array<int, array<string, string>>}
     */
    public function forMember(?int $memberId): array;
}
