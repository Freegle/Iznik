<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which chat message a TrashNothing chat-messages feed record became, keyed by TN's id for the
 * record. See ChatMessageIngestionService.
 */
class ChatMessageTn extends Model
{
    protected $table = 'chat_messages_tn';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $casts = [
        'tndate' => 'datetime',
        'created' => 'datetime',
    ];

    public function chatMessage(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'chatmsgid');
    }
}
