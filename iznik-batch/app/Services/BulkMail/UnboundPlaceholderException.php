<?php

namespace App\Services\BulkMail;

use RuntimeException;

/**
 * Thrown when substituted HTML still contains unbound {{var}} placeholders.
 *
 * This is fail-loud on purpose: an unsubstituted placeholder in a sent email
 * would be visible as garbage to the recipient. We'd rather raise during the
 * send loop and skip the message (or fail the cron tick) than ship broken
 * output.
 */
class UnboundPlaceholderException extends RuntimeException
{
    public function __construct(
        public readonly array $missingKeys,
        public readonly string $mailableClass = ''
    ) {
        $keys = implode(', ', $missingKeys);
        $cls = $mailableClass !== '' ? " in {$mailableClass}" : '';
        parent::__construct("Unbound merge placeholders{$cls}: {$keys}");
    }
}
