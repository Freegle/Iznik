<?php

namespace App\Mail\Chat;

use App\Services\Support\BriefFormatter;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Plain text email sent to support when a user refers a chat for help.
 *
 * V1 parity: ChatRoom::referToSupport() lines 2266-2284.
 *
 * For a support case it carries the first pass brief, or the plain reason there is none, then the
 * case link and the chat link. Without a case id it is the original short mail.
 */
class ReferToSupportMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>|null  $brief
     */
    public function __construct(
        public readonly string $userName,
        public readonly int $userId,
        public readonly int $chatId,
        public readonly string $otherUserName,
        public readonly int $otherUserId,
        public readonly string $replyToAddress,
        public readonly string $replyToName,
        public readonly ?array $brief = null,
        public readonly ?int $caseId = null,
        public readonly ?string $skipReason = null,
        public readonly ?string $reason = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('freegle.mail.noreply_addr'),
                config('freegle.branding.name')
            ),
            replyTo: [new Address($this->replyToAddress, $this->replyToName)],
            subject: "{$this->userName} (#{$this->userId}) asked for help with chat #{$this->chatId} with {$this->otherUserName} (#{$this->otherUserId})",
        );
    }

    public function build(): static
    {
        $modSite = config('freegle.sites.mod');
        $chatText = "Please review the chat at {$modSite}/support/refer/{$this->chatId} and then reply to this email to contact the mod who requested help.";

        if ($this->caseId === null) {
            return $this->text('emails.plain.refer-to-support', ['body' => $chatText]);
        }

        $parts = ["Support case #{$this->caseId}"];
        if ($this->reason !== null && $this->reason !== '') {
            $parts[] = "The moderator asked: {$this->reason}";
        }
        if ($this->brief !== null) {
            $parts[] = BriefFormatter::text($this->brief);
        } else {
            $parts[] = 'First pass skipped: '.($this->skipReason ?? 'no reason was recorded');
        }
        $parts[] = "Case: {$modSite}/support/cases/{$this->caseId}";
        $parts[] = $chatText;

        // Plain text, so the body is not HTML-escaped.
        return $this->text('emails.plain.support-case-referral', ['body' => implode("\n\n", $parts)]);
    }
}
