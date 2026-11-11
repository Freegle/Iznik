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
 * The reason keys and the switch name are shared with the Go API (chat/warnnothold.go), which
 * reads the same CHAT_WARN_NOT_HOLD variable. Off unless switched on: this is a thought
 * experiment, not the shipped behaviour.
 */
class ChatWarnNotHold
{
    public static function enabled(): bool
    {
        return (bool) config('freegle.moderation.chat_warn_not_hold', false);
    }

    /**
     * Stored reasons that mean "the content check saw something in the text", which a member
     * can be warned about and left to judge. Every other reason, and a missing one, is a hold
     * the member cannot be given a useful warning for: a shadow ban (Spam, Fully, Last), a
     * blocked source (CountryBlocked, SpamAssassin, Script), a reused image (SameImage,
     * DodgyImage) and so on. Those stay hidden. This is an allowlist, so a reason added to the
     * enum later stays hidden until it is chosen here. Shared with the Go API
     * (chat.HeldReasonsDelivered).
     */
    public const DELIVERED = [
        'Money', 'Link', 'URL on DBL', 'Email', 'Language', 'WorryWord',
        'Referenced known spammer', 'Greetings spam', 'Known spam keyword', 'Abuse',
    ];

    /**
     * Whether a message may reach the recipient. A clean message always may. A held one may
     * only under the experiment, and only when it was held for something the member can be
     * warned about (DELIVERED); any other hold stays a hold.
     */
    public static function deliverable(bool $reviewrequired, ?string $reportreason): bool
    {
        if (! $reviewrequired) {
            return true;
        }

        return self::enabled()
            && $reportreason !== null
            && in_array($reportreason, self::DELIVERED, true);
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
