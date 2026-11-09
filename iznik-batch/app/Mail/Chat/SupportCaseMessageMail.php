<?php

namespace App\Mail\Chat;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells one side of a support case that the other has written in the ModTools case thread.
 *
 * Support's message goes to the referring moderator; the referrer's goes to the support address.
 * Replies are made in ModTools, so Reply-To is the no-reply address. It carries the message and
 * the case link and nothing from the first pass.
 */
class SupportCaseMessageMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly int $caseId,
        public readonly string $role,
        public readonly string $authorName,
        public readonly string $body,
    ) {}

    public function envelope(): Envelope
    {
        $noreply = new Address(config('freegle.mail.noreply_addr'), config('freegle.branding.name'));
        $subject = $this->role === 'Support'
            ? "Support has replied about case #{$this->caseId}"
            : "{$this->authorName} replied on support case #{$this->caseId}";

        return new Envelope(from: $noreply, replyTo: [$noreply], subject: $subject);
    }

    public function build(): static
    {
        $modSite = config('freegle.sites.mod');
        $lead = $this->role === 'Support'
            ? "{$this->authorName} from Support wrote:"
            : "{$this->authorName} wrote:";
        $text = "{$lead}\n\n{$this->body}\n\nReply in ModTools, not by email: {$modSite}/support/cases/{$this->caseId}\n\nThis is an automated message.";

        return $this->text('emails.plain.support-case-referral', ['body' => $text]);
    }
}
