<?php

namespace App\Services\Concierge;

/**
 * Turns a ConciergeEngine action into reply prose. Split from the engine so the
 * decision logic stays deterministic and testable; the actual wording can be a
 * template (deterministic, used in tests + as a safe fallback) or an LLM.
 */
interface Drafter
{
    /**
     * @param array<string,mixed> $action   one ConciergeEngine action
     * @param array<string,mixed> $replier   the replier it targets
     * @param array<string,mixed> $context   {items, collection, signoff, ...}
     * @return array{subject:string,body:string}|null  null = no message for this action
     */
    public function draft(array $action, array $replier, array $context): ?array;
}
