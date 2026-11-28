<?php

namespace App\Mail\Chat;

use App\Mail\MjmlMailable;
use App\Services\Support\BriefFormatter;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The one mail Support gets when a moderator refers a chat to them.
 *
 * It comes from the volunteers address of the community the referral is for, so Support's reply
 * reaches moderators who can act on it. It says who referred which chat and why, and carries the AI
 * first pass, or the plain reason there is none. It never carries what was said in the chat: Support
 * read that in ModTools, through the link.
 */
class ReferToSupportMail extends MjmlMailable
{
    /**
     * @param  array<string, mixed>|null  $brief
     */
    public function __construct(
        public readonly string $referrerName,
        public readonly int $referrerId,
        public readonly ?int $chatId,
        public readonly ?string $chatType,
        public readonly string $memberName,
        public readonly int $memberId,
        public readonly ?string $groupName,
        public readonly string $fromAddress,
        public readonly string $fromName,
        public readonly string $replyToAddress,
        public readonly string $replyToName,
        public readonly ?string $reason = null,
        public readonly ?array $brief = null,
        public readonly ?string $skipReason = null,
    ) {
        parent::__construct();
    }

    /**
     * Transactional: a moderator asked Support for help. It carries no List-Unsubscribe.
     */
    protected function unsubscribeType(): ?string
    {
        return null;
    }

    protected function getSubject(): string
    {
        $who = "{$this->referrerName} (#{$this->referrerId})";
        if ($this->chatId === null) {
            return "{$who} asked for help with a chat that is no longer there";
        }
        $about = $this->memberId > 0 ? " with {$this->memberName} (#{$this->memberId})" : '';

        return "{$who} asked for help with chat #{$this->chatId}{$about}";
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->fromAddress, $this->fromName),
            replyTo: [new Address($this->replyToAddress, $this->replyToName)],
            subject: $this->getSubject(),
        );
    }

    /** How the chat type reads to a person. */
    private function chatTypeText(): string
    {
        return match ($this->chatType) {
            'User2Mod' => 'A member writing to the volunteers',
            'User2User' => 'Two members',
            'Mod2Mod' => 'Volunteers',
            default => '',
        };
    }

    public function build(): static
    {
        $modSite = rtrim((string) config('freegle.sites.mod'), '/');
        $data = [
            'referrerName' => $this->referrerName,
            'referrerId' => $this->referrerId,
            'groupName' => $this->groupName,
            'memberName' => $this->memberId > 0 ? $this->memberName : null,
            'memberId' => $this->memberId,
            'chatId' => $this->chatId,
            'chatTypeText' => $this->chatTypeText(),
            'reason' => $this->reason !== null && trim($this->reason) !== '' ? trim($this->reason) : null,
            'brief' => $this->brief !== null ? BriefFormatter::parts($this->brief) : null,
            'skipReason' => $this->brief === null ? ($this->skipReason ?? 'no reason was recorded') : null,
            'chatUrl' => $this->chatId !== null ? "{$modSite}/support/refer/{$this->chatId}" : null,
            'memberUrl' => $this->memberId > 0 ? "{$modSite}/support/{$this->memberId}" : null,
            'replyToName' => $this->replyToName,
            'title' => $this->getSubject(),
        ];
        $data['text'] = $this->plainText($data);

        return $this->mjmlView('emails.mjml.chat.refer-to-support', $data, 'emails.text.chat.refer-to-support');
    }

    /**
     * The same mail as plain text.
     *
     * @param  array<string, mixed>  $d
     */
    private function plainText(array $d): string
    {
        $parts = ["{$this->referrerName} (#{$this->referrerId}) has asked Support for help."];

        $facts = [];
        if ($d['groupName']) {
            $facts[] = "Community: {$d['groupName']}";
        }
        if ($d['memberName']) {
            $facts[] = "Member: {$d['memberName']} (#{$this->memberId})";
        }
        if ($this->chatId !== null) {
            $facts[] = "Chat: #{$this->chatId}".($d['chatTypeText'] !== '' ? " ({$d['chatTypeText']})" : '');
        } else {
            $facts[] = 'Chat: no longer there';
        }
        $parts[] = implode("\n", $facts);

        if ($d['reason']) {
            $parts[] = "The moderator asked: {$d['reason']}";
        }
        $parts[] = $this->brief !== null
            ? "AI first pass\n\n".BriefFormatter::text($this->brief)
            : "No AI first pass: {$d['skipReason']}";
        if ($d['chatUrl']) {
            $parts[] = "Read the chat: {$d['chatUrl']}";
        }
        if ($d['memberUrl']) {
            $parts[] = "The member in Support Tools: {$d['memberUrl']}";
        }
        $parts[] = "Reply to this email to reach {$this->replyToName}.";

        return implode("\n\n", $parts);
    }
}
