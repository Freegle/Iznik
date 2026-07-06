<?php

namespace App\Mail\Authority;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The quarterly reminder sent to the partnerships inbox: "the council stats for
 * this quarter are ready - review them and update the template before they go
 * out". The generated spreadsheets are attached.
 */
class AuthorityStatsReminderMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  string  $quarterLabel  e.g. "Q2 2026"
     * @param  array<int, string>  $attachmentPaths  absolute paths to the .xlsx files
     */
    public function __construct(
        public string $quarterLabel,
        public array $attachmentPaths,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Freegle council statistics ready to review - {$this->quarterLabel}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.authority-stats-reminder',
            with: [
                'quarterLabel' => $this->quarterLabel,
                'count' => count($this->attachmentPaths),
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return array_map(
            static fn (string $path): Attachment => Attachment::fromPath($path)
                ->as(basename($path))
                ->withMime('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
            $this->attachmentPaths,
        );
    }
}
