<?php

namespace App\Mail\Backup;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Tells the geeks that the nightly database backup failed.
 *
 * Deliberately a plain text Mailable rather than an MjmlMailable: this has to arrive when
 * the database is unhappy, which is exactly when the backup fails, so it depends on no
 * compiler service, no database lookup and no tracking.
 *
 * It is a Mailable rather than Mail::raw() so that "did the alert actually go out" is
 * assertable. A backup failure nobody hears about is the shape of problem the ops notes
 * already call out as the one that hurts.
 */
class BackupFailedMail extends Mailable
{
    public function __construct(public readonly string $detail) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'BACKUP ERROR: database backup failed');
    }

    public function content(): Content
    {
        return new Content(text: 'emails.backup-failed-text');
    }
}
