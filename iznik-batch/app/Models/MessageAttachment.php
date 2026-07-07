<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageAttachment extends Model
{
    protected $table = 'messages_attachments';
    protected $guarded = ['id'];
    public $timestamps = FALSE;

    protected $casts = [
        'archived' => 'boolean',
        'rotated' => 'boolean',
        'primary' => 'boolean',
    ];

    /**
     * Get the message this attachment belongs to.
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'msgid');
    }

    /**
     * Check if this is the primary attachment.
     */
    public function isPrimary(): bool
    {
        return $this->primary === true;
    }
}
