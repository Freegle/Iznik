<?php

namespace App\Mail\Housekeeper;

use App\Mail\MjmlMailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Notification email summarising the results of a housekeeping task.
 *
 * Sent by HousekeeperService after processing tasks like facebook-deletion.
 */
class HousekeeperResultsMail extends MjmlMailable
{
    public function __construct(
        public readonly string $task,
        public readonly string $status,
        public readonly string $summary,
        public readonly array $results = [],
    ) {
        parent::__construct();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('freegle.mail.noreply_addr'),
                config('freegle.branding.name')
            ),
            subject: $this->getSubject(),
        );
    }

    protected function getSubject(): string
    {
        return sprintf(
            'Housekeeper: %s — %s',
            $this->task,
            $this->status === 'success' ? 'OK' : 'FAILED'
        );
    }

    public function build(): static
    {
        return $this->mjmlView(
            'emails.mjml.housekeeper.results',
            [
                'task' => $this->task,
                'taskStatus' => $this->status,
                'summary' => $this->summary,
                'results' => $this->results,
                'timestamp' => now()->format('d/m/Y H:i:s'),
            ]
        );
    }

    protected function getRecipientUserId(): ?int
    {
        return NULL;
    }
}
