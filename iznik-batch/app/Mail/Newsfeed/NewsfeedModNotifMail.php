<?php

namespace App\Mail\Newsfeed;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewsfeedModNotifMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly int $count,
        public readonly string $summary,
    ) {
    }

    public function envelope(): Envelope
    {
        $plural = $this->count !== 1 ? 's' : '';
        return new Envelope(
            from: new Address(
                config('freegle.mail.noreply_addr'),
                config('freegle.branding.name')
            ),
            subject: "{$this->count} chitchat post{$plural} from your members",
        );
    }

    public function build(): static
    {
        return $this->text('emails.text.newsfeed.mod-notif');
    }
}
