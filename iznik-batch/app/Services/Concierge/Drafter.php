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

/**
 * Deterministic, template-based drafter. No AI, no network — safe in tests and a
 * sensible fallback if the LLM drafter is unavailable.
 */
class TemplateDrafter implements Drafter
{
    public function draft(array $action, array $replier, array $context): ?array
    {
        $name = $replier['name'] ?? 'there';
        $items = $context['items'] ?? [];
        $collection = $context['collection'] ?? '';
        $sign = $context['signoff'] ?? 'Best wishes';
        $subject = 'Re: ' . ($context['subject'] ?? 'the office furniture');
        $nm = fn ($n) => $items[$n]['name'] ?? "#$n";

        switch ($action['kind']) {
            case ConciergeEngine::A_CONFIRM_COLLECTION:
                $b = "Hi $name,\n\nThat's great - {$action['day']} works well for the "
                    . strtolower($nm($action['item'])) . ".\n\nCollection is from $collection, any time 9am-8pm. "
                    . "Let me know roughly when you'll arrive and I'll make sure they're expecting you.\n\n$sign";

                return ['subject' => $subject, 'body' => $b];

            case ConciergeEngine::A_APOLOGISE_SHORTFALL:
                $b = "Hi $name,\n\nI'm so sorry - I've a small piece of disappointing news. "
                    . "The donor has let me know that we're now down to {$action['have']} of the "
                    . strtolower($nm($action['item'])) . " (rather than the {$action['promised']} we'd expected). "
                    . "It's out of our hands, but the remaining one is still yours if you'd like it.\n\n$sign";

                return ['subject' => $subject, 'body' => $b];

            case ConciergeEngine::A_OFFER_MENU:
                if (empty($action['items'])) return null;
                $list = implode(', ', array_map(fn ($n) => strtolower($nm($n)), $action['items']));
                $gone = !empty($action['gone'])
                    ? ' (I\'m sorry, the ' . implode(', ', array_map(fn ($n) => strtolower($nm($n)), $action['gone'])) . ' has since gone.)'
                    : '';
                $b = "Hi $name,\n\nThe donor has confirmed what's still available, so I can come back to you as promised. "
                    . "We still have: $list.$gone Would any of those be useful? Let me know which and I'll set it aside.\n\n"
                    . "It's a free local collection - I'll send you the exact address once you let me know.\n\n$sign";

                return ['subject' => $subject, 'body' => $b];

            case ConciergeEngine::A_OFFER_ALT:
                $b = "Hi $name,\n\nThank you for your patience. I'm sorry the items you first hoped for have gone, "
                    . "but we do still have a " . strtolower($nm($action['item'])) . " which might suit you. "
                    . "Shall I set it aside? It's a free local collection, and I'll send the exact address once you confirm.\n\n$sign";

                return ['subject' => $subject, 'body' => $b];

            case ConciergeEngine::A_HOLDING_NOTE:
                $b = "Hi $name,\n\nJust a quick note to keep you posted - we're double-checking with the donor exactly "
                    . "what's still available, as a few pieces have been spoken for. As soon as they come back to us I'll "
                    . "write with what we can offer. Nothing you need to do for now.\n\n$sign";

                return ['subject' => $subject, 'body' => $b];

            case ConciergeEngine::A_DECLINE_ACK:
                $b = "Hi $name,\n\nThank you so much for letting me know, and no problem at all. "
                    . "Wishing you all the best.\n\n$sign";

                return ['subject' => $subject, 'body' => $b];

            // HOLD / WAITLIST / RENEGE_ALERT are internal states — no message goes out
            // automatically (they surface to the human), so no draft.
            case ConciergeEngine::A_HOLD:
            case ConciergeEngine::A_WAITLIST:
            case ConciergeEngine::A_RENEGE_ALERT:
            default:
                return null;
        }
    }
}
