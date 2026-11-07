<?php

namespace App\Services\Support;

/**
 * A support case first pass that did not produce a brief.
 *
 * The reason is one of FirstPassService's fixed plain reasons and is the only text that is ever
 * stored or mailed about the failure; nothing the helper said is kept.
 */
class FirstPassFailure extends \RuntimeException
{
    public function __construct(
        public readonly string $failureCode,
        public readonly string $reason,
        /** The helper answered (so a run row is written) rather than being unreachable or busy. */
        public readonly bool $helperRan = false,
        /** Retrying cannot help, so the case goes straight to Failed. */
        public readonly bool $terminal = false,
    ) {
        parent::__construct($reason);
    }
}
