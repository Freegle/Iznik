<?php

namespace App\Mail\WhatJobs;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The WhatJobs clicks emails: a sudden-change alert, or the weekly summary. Both are a heading,
 * some paragraphs, an optional table of days and an optional chart, embedded in the message
 * (cid:) so it shows without loading anything remote.
 */
class WhatJobsClicksMail extends Mailable
{
    /**
     * @param  list<array{heading: ?string, paragraphs: list<string>}>  $sections
     * @param  array{head: list<string>, rows: list<list<string>>}|null  $table
     */
    public function __construct(
        public readonly string $emailSubject,
        public readonly array $sections,
        public readonly ?array $table = null,
        public readonly ?string $chartPng = null,
        public readonly string $chartAlt = '',
        // The chart goes after this section.
        public readonly int $chartAfter = 0,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('freegle.geeks_addr', 'geeks@ilovefreegle.org'), 'Freegle Geeks'),
            subject: $this->emailSubject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.plain.whatjobs-clicks',
            text: 'emails.text.whatjobs-clicks',
            with: [
                'sections' => $this->sections,
                'table' => $this->table,
                'chartPng' => $this->chartPng,
                'chartAlt' => $this->chartAlt,
                'chartAfter' => $this->chartAfter,
            ],
        );
    }
}
