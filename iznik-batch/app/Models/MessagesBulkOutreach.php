<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One outreach record per (bulk offer, organisation user): the org's details, its
 * preferences for that specific bulk offer, and the outreach lifecycle.
 *
 * @see \App\Console\Commands\BulkOffer\ImportOrgsCommand
 */
class MessagesBulkOutreach extends Model
{
    protected $table = 'messages_bulk_outreach';

    protected $guarded = ['id'];

    protected $casts = [
        'clusters' => 'array',
        'activity_date' => 'date',
        'suppressed_until' => 'date',
        'sent_at' => 'datetime',
        'replied_at' => 'datetime',
    ];

    public const STATUS_IMPORTED = 'Imported';
    public const STATUS_QUEUED = 'Queued';
    public const STATUS_SENT = 'Sent';
    public const STATUS_REPLIED = 'Replied';
    public const STATUS_TOOK = 'Took';
    public const STATUS_DECLINED = 'Declined';
    public const STATUS_NORESPONSE = 'NoResponse';
    public const STATUS_SKIPPED = 'Skipped';

    // An auto-reply / out-of-office / auto-acknowledgement, NOT a genuine human
    // reply - set by PollGmailOutreachCommand so it isn't emitted to the FSM
    // brain or counted as a Replied.
    public const STATUS_AUTO_ACK = 'AutoAck';

    // A delivery-failure bounce/DSN for the outreach email - set by
    // PollGmailOutreachCommand, which also suppresses the row so the (dead)
    // address is never re-contacted.
    public const STATUS_BOUNCED = 'Bounced';

    public function user()
    {
        return $this->belongsTo(User::class, 'userid');
    }
}
