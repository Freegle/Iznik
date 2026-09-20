<?php

namespace App\Support;

/**
 * Experiment: warn, do not hold.
 *
 * Today a chat message the content check flags (reviewrequired=1) is invisible to the
 * recipient until a moderator approves it, and nobody tells either side. With nobody there
 * it is auto-rejected a week later. With this switched on the message is delivered anyway,
 * and every path that carries it - the app, the push, the email - shows a warning in place
 * of the text until the member chooses to read it. A moderator can still reject it later.
 *
 * The reason keys and the switch name are shared with the Go API (chat/warnnothold.go).
 * Off unless switched on: this is a thought experiment, not the shipped behaviour.
 */
class ChatWarnNotHold
{
    public static function enabled(): bool
    {
        return (bool) config('freegle.moderation.chat_warn_not_hold', false);
    }

    /**
     * Stored reasons that mean "a person decided this sender is not to be heard", not "the
     * content check saw something". A member on full chat moderation (a shadow ban) is held
     * with the generic Spam reason; Last is the hold that chains from it; Fully is the
     * explicit form. None of those is a warning to tap through. Shared with the Go API
     * (chat.HeldReasonsNeverDelivered).
     */
    public const NEVER_DELIVERED = ['Spam', 'Fully', 'Last'];

    /**
     * Whether a message may reach the recipient. A clean message always may. A held one may
     * only under the experiment, and only when it was held for something the member can be
     * warned about; a hold with no recorded reason cannot be explained, so it stays a hold.
     */
    public static function deliverable(bool $reviewrequired, ?string $reportreason): bool
    {
        if (! $reviewrequired) {
            return true;
        }

        return self::enabled()
            && $reportreason !== null
            && ! in_array($reportreason, self::NEVER_DELIVERED, true);
    }

    /**
     * Turn the stored moderator-facing reportreason into the short key the member-facing
     * wording is picked by. Anything unknown, including no reason, is "checked".
     */
    public static function reason(?string $reportreason): string
    {
        return match ($reportreason) {
            'Money' => 'money',
            'Link', 'URL on DBL' => 'link',
            'Email' => 'contact',
            'Language' => 'language',
            'WorryWord' => 'concern',
            'Referenced known spammer', 'Greetings spam', 'Known spam keyword' => 'scam',
            default => 'checked',
        };
    }

    /**
     * What a push or an email says in place of the held text. It never quotes the message:
     * the point of the warning is that the member reads the text on purpose, in the app,
     * where the warning is shown alongside it.
     */
    public static function warningText(string $reason): string
    {
        $why = match ($reason) {
            'money' => 'mentions money. Freegle is always free, so be careful',
            'link' => 'contains a link. Only open links from people you trust',
            'contact' => 'contains contact details. Take care before sharing yours',
            'language' => 'may be in another language',
            'concern' => 'contains something our checks flagged as a possible concern',
            'scam' => 'looks like it could be a scam',
            default => 'is being checked, so take care with it',
        };

        return "You have a new message which {$why}. Open Freegle to read it.";
    }
}
