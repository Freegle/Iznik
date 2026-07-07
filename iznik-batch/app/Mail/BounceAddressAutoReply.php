<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Symfony\Component\Mime\Email;

/**
 * Auto-reply sent when a human accidentally replies to a bounce Return-Path address.
 *
 * Includes all standard loop prevention headers to avoid mail loops.
 */
class BounceAddressAutoReply extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $recipientEmail,
    ) {}

    public function envelope(): Envelope
    {
        $siteName = config('freegle.site_name', 'Freegle');

        return new Envelope(
            from: new Address(
                'noreply@'.config('freegle.mail.user_domain', 'users.ilovefreegle.org'),
                $siteName
            ),
            subject: "Your reply could not be delivered - {$siteName}",
        );
    }

    public function headers(): Headers
    {
        return new Headers(
            text: [
                'Auto-Submitted' => 'auto-replied',
                'X-Auto-Response-Suppress' => 'All',
                'Precedence' => 'auto_reply',
            ],
        );
    }

    public function content(): \Illuminate\Mail\Mailables\Content
    {
        return new \Illuminate\Mail\Mailables\Content(
            text: 'emails.bounce-auto-reply-text',
        );
    }

    /**
     * Null Return-Path so bounces of this auto-reply don't generate further replies.
     * This can't be set via the Headers class, so we use withSymfonyMessage.
     */
    public function build(): static
    {
        $this->withSymfonyMessage(function (Email $message) {
            $message->returnPath('');
        });

        return $this;
    }
}
